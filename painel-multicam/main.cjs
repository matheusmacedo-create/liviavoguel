// Processo principal do Painel Multicam: abre a janela, prende cada aba de site no leitor de chat,
// lê a API de eventos do Chaturbate (que não pode ser chamada direto da tela) e cuida do repasse
// da transmissão do OBS para os sites (repasse.js).

const { app, BrowserWindow, ipcMain, dialog } = require('electron');
const path = require('node:path');
const fs = require('node:fs/promises');
const { existsSync } = require('node:fs');
const { pathToFileURL } = require('node:url');

const argConfig = process.argv.find((a) => a.startsWith('--config='));
const caminhoConfig = argConfig
  ? path.resolve(process.cwd(), argConfig.slice('--config='.length))
  : path.join(__dirname, 'config', 'sites.js');
const modoDemo = process.argv.includes('--demo');

// Alguns sites recusam navegadores que se identificam como Electron.
app.userAgentFallback = app.userAgentFallback.replace(/\s(?:Electron|painel-multicam)\/\S+/g, '');

app.on('web-contents-created', (_e, contents) => {
  // Toda aba de site roda isolada, só com o leitor de chat, sem acesso ao computador.
  contents.on('will-attach-webview', (_ev, webPreferences) => {
    webPreferences.preload = path.join(__dirname, 'preload-site.cjs');
    webPreferences.nodeIntegration = false;
    webPreferences.contextIsolation = true;
    webPreferences.sandbox = true;
  });
});

const leiturasPorJanela = new Map();

ipcMain.handle('iniciar-leituras', async (e) => {
  const win = BrowserWindow.fromWebContents(e.sender);
  leiturasPorJanela.get(win)?.abort(); // a tela foi recarregada: para as leituras antigas
  const controle = new AbortController();
  leiturasPorJanela.set(win, controle);
  if (!modoDemo) await iniciarLeiturasPorAPI(win, controle.signal);
});

ipcMain.handle('salvar-csv', async (e, nome, conteudo) => {
  if (typeof nome !== 'string' || typeof conteudo !== 'string') return null;
  const win = BrowserWindow.fromWebContents(e.sender);
  const { canceled, filePath } = await dialog.showSaveDialog(win, {
    defaultPath: path.join(app.getPath('documents'), path.basename(nome)),
    filters: [{ name: 'Planilha CSV', extensions: ['csv'] }],
  });
  if (canceled || !filePath) return null;
  await fs.writeFile(filePath, conteudo, 'utf8');
  return filePath;
});

/* ---------- Transmissão: OBS → painel → sites ---------- */

let repasse = null;
let infoOBS = { servidor: 'rtmp://127.0.0.1:1935/live', chave: 'painel' };
let estadoTransmissao = { configurado: false };

function publicarTransmissao(estado) {
  estadoTransmissao = estado;
  for (const win of BrowserWindow.getAllWindows()) enviar(win, 'transmissao', estado);
}

async function iniciarTransmissao() {
  const { default: config } = await import(pathToFileURL(caminhoConfig).href);
  const segredos = await lerSegredos();
  const chaves = segredos.transmissao ?? {};
  const destinos = {};
  for (const site of config.sites) {
    const d = chaves[site.id];
    if (site.ativo === false || !d?.servidor || !d?.chave) continue;
    destinos[site.id] = { url: `${String(d.servidor).replace(/\/+$/, '')}/${d.chave}`, chave: String(d.chave) };
  }
  const porta = config.transmissao?.porta ?? 1935;
  infoOBS = { servidor: `rtmp://127.0.0.1:${porta}/live`, chave: 'painel' };
  if (!Object.keys(destinos).length) return;

  const ffmpeg = require('ffmpeg-static');
  if (!ffmpeg || !existsSync(ffmpeg)) {
    publicarTransmissao({ configurado: true, falha: 'O ffmpeg não foi instalado. Rode "npm install" de novo nesta pasta.' });
    return;
  }
  const { criarRepasse } = await import(pathToFileURL(path.join(__dirname, 'repasse.js')).href);
  repasse = criarRepasse({ ffmpeg, porta, destinos, aoMudar: (e) => publicarTransmissao({ configurado: true, ...e }) });
  repasse.iniciar();
}

ipcMain.handle('transmissao-info', () => ({ ...infoOBS, estado: estadoTransmissao }));

ipcMain.handle('transmissao-comando', (_e, acao, site, valor) => {
  if (!repasse) return;
  if (acao === 'privado') repasse.privado(typeof site === 'string' ? site : null);
  else if (acao === 'ligar' && typeof site === 'string') repasse.ligar(site, Boolean(valor));
  else if (acao === 'todos') repasse.ligarTodos(Boolean(valor));
});

/* ---------- Histórico das lives (fase 4) ---------- */

// Um arquivo por configuração, para quem cuida de várias modelos não misturar os números.
const arquivoHistorico = () => path.join(app.getPath('userData'), `historico-${path.basename(caminhoConfig, path.extname(caminhoConfig))}.json`);

async function lerHistorico() {
  const arquivo = arquivoHistorico();
  try {
    const dados = JSON.parse(await fs.readFile(arquivo, 'utf8'));
    return Array.isArray(dados) ? dados : [];
  } catch (err) {
    if (err.code === 'ENOENT') return [];
    // Arquivo estragado: guarda uma cópia em vez de apagar por cima na próxima gravação.
    await fs.rename(arquivo, `${arquivo}.estragado-${Date.now()}`).catch(() => {});
    console.error('Histórico ilegível, guardei uma cópia e comecei outro:', err.message);
    return [];
  }
}

const sessaoValida = (s) => s && Number.isFinite(s.id) && Number.isFinite(s.inicio) && Number.isFinite(s.fim)
  && Array.isArray(s.sites) && s.porHora && typeof s.porHora === 'object';

let filaHistorico = Promise.resolve();

ipcMain.handle('historico-ler', () => filaHistorico.then(lerHistorico));

ipcMain.handle('historico-salvar', (_e, sessao) => {
  if (!sessaoValida(sessao)) return false;
  // Uma gravação por vez; a mesma live (mesmo id) é atualizada, não duplicada.
  filaHistorico = filaHistorico.then(async () => {
    const lista = await lerHistorico();
    const i = lista.findIndex((s) => s.id === sessao.id);
    if (i >= 0) lista[i] = sessao; else lista.push(sessao);
    const arquivo = arquivoHistorico();
    await fs.mkdir(path.dirname(arquivo), { recursive: true });
    await fs.writeFile(`${arquivo}.tmp`, JSON.stringify(lista));
    await fs.rename(`${arquivo}.tmp`, arquivo);
  }).catch((err) => console.error('Não consegui guardar a live no histórico:', err.message));
  return filaHistorico.then(() => true);
});

function enviar(win, canal, dados) {
  if (!win.isDestroyed()) win.webContents.send(canal, dados);
}

async function lerSegredos() {
  const arquivo = path.join(path.dirname(caminhoConfig), 'segredos.local.json');
  try {
    return JSON.parse(await fs.readFile(arquivo, 'utf8'));
  } catch (err) {
    if (err.code !== 'ENOENT') console.error('Não consegui ler segredos.local.json:', err.message);
    return {};
  }
}

async function iniciarLeiturasPorAPI(win, sinal) {
  const { default: config } = await import(pathToFileURL(caminhoConfig).href);
  const { converterEventoChaturbate } = await import(pathToFileURL(path.join(__dirname, 'src', 'core', 'chaturbate.js')).href);
  const segredos = await lerSegredos();

  for (const site of config.sites) {
    if (site.ativo === false || site.leitura?.modo !== 'chaturbate-api') continue;
    const url = segredos[site.leitura.segredo ?? 'chaturbateEventsUrl'];
    if (!url) {
      enviar(win, 'status-leitura', { site: site.id, estado: 'aviso', detalhe: 'Falta o link da API em segredos.local.json' });
      continue;
    }
    lerChaturbate(win, site.id, url, converterEventoChaturbate, sinal);
  }
}

const esperar = (ms) => new Promise((r) => setTimeout(r, ms));

// A API responde quando há eventos (ou após um tempo) e manda o próximo endereço em nextUrl.
async function lerChaturbate(win, siteId, urlInicial, converter, sinal) {
  let url = urlInicial;
  let falhas = 0;
  let avisouOk = false;
  while (!sinal.aborted && !win.isDestroyed()) {
    const comeco = Date.now();
    try {
      const resp = await fetch(url, { signal: sinal });
      if (!resp.ok) throw new Error(`a API respondeu ${resp.status}`);
      const dados = await resp.json();
      const eventos = dados.events ?? [];
      for (const ev of eventos) {
        const convertido = converter(ev);
        if (convertido) enviar(win, 'evento', { site: siteId, ...convertido });
      }
      if (dados.nextUrl) url = dados.nextUrl;
      if (!avisouOk || falhas) enviar(win, 'status-leitura', { site: siteId, estado: 'ok', detalhe: 'Lendo pela API' });
      avisouOk = true;
      falhas = 0;
      if (!eventos.length && Date.now() - comeco < 1000) await esperar(1000);
    } catch (err) {
      if (sinal.aborted) return;
      falhas++;
      enviar(win, 'status-leitura', { site: siteId, estado: 'erro', detalhe: `API: ${err.message}` });
      await esperar(Math.min(30_000, 2000 * falhas));
    }
  }
}

async function criarJanela() {
  const win = new BrowserWindow({
    width: 1500,
    height: 920,
    minWidth: 1100,
    minHeight: 680,
    backgroundColor: '#0f1115',
    title: 'Painel Multicam',
    webPreferences: {
      preload: path.join(__dirname, 'preload-janela.cjs'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      webviewTag: true,
    },
  });
  win.on('closed', () => {
    leiturasPorJanela.get(win)?.abort();
    leiturasPorJanela.delete(win);
  });
  const query = { config: pathToFileURL(caminhoConfig).href };
  if (modoDemo) query.demo = '1';
  await win.loadFile(path.join(__dirname, 'src', 'index.html'), { query });
}

app.whenReady().then(async () => {
  if (!modoDemo) {
    try {
      await iniciarTransmissao();
    } catch (err) {
      publicarTransmissao({ configurado: true, falha: `Não consegui iniciar a transmissão: ${err.message}` });
    }
  }
  await criarJanela();
});
app.on('window-all-closed', () => app.quit());
app.on('will-quit', () => repasse?.parar()); // não deixa ffmpeg rodando depois de fechar
