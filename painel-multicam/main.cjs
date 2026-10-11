// Processo principal do Painel Multicam: abre a janela, prende cada aba de site no leitor de chat
// e lê a API de eventos do Chaturbate (que não pode ser chamada direto da tela).

const { app, BrowserWindow, ipcMain, dialog } = require('electron');
const path = require('node:path');
const fs = require('node:fs/promises');
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

app.whenReady().then(criarJanela);
app.on('window-all-closed', () => app.quit());
