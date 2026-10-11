// Tela do Painel Multicam: ranking dos sites, chat de todos juntos e as abas de cada site.
// Todo texto que vem do chat entra na tela como texto puro (textContent), nunca como HTML.

import { criarMetricas } from './core/metricas.js';
import { interpretar } from './core/leitura.js';
import { criarSimulador } from './core/simulador.js';
import { resumoParaCSV } from './core/csv.js';

const parametros = new URLSearchParams(location.search);
const modoDemo = parametros.has('demo');
const { default: config } = await import(parametros.get('config') ?? '../config/sites.js');

const sites = config.sites.filter((s) => s.ativo !== false);
const porId = new Map(sites.map((s) => [s.id, s]));
const janelaMin = config.janelaRankingMin ?? 5;
const ponte = window.painel; // não existe se o HTML for aberto fora do Electron
const MAX_ITENS = 500;

const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const $ = (seletor) => document.querySelector(seletor);

let metricas = criarMetricas({ ...config, sites });
const statusLeitura = new Map();
const filtro = { modo: 'tudo', escondidos: new Set() };
const semSom = new Set();
let abaAtiva = sites[0]?.id;

function el(tag, props = {}, ...filhos) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (k === 'class') n.className = v;
    else if (k === 'cor') n.style.setProperty('--cor-site', v);
    else if (k.startsWith('data-')) n.setAttribute(k, v);
    else n[k] = v;
  }
  for (const f of filhos) if (f != null) n.append(f);
  return n;
}

/* ---------- Ranking ---------- */

const cards = new Map();

function montarRanking() {
  for (const site of sites) {
    const campos = {
      posicao: el('span', { class: 'card-posicao' }),
      rpm: el('span'),
      gorjetas: el('span'),
      pessoas: el('span'),
      sessao: el('span'),
      privados: el('span'),
      status: el('div', { class: 'card-status' }),
    };
    const raiz = el('button', { type: 'button', class: 'card', cor: site.cor, title: 'Abrir a aba deste site', onclick: () => abrirAba(site.id) },
      el('div', { class: 'card-topo' }, el('span', { class: 'card-nome', textContent: site.nome }), campos.posicao),
      el('div', { class: 'card-rpm' }, campos.rpm, el('small', { textContent: ' /min' })),
      el('div', { class: 'card-linha' }, campos.gorjetas, campos.pessoas),
      el('div', { class: 'card-linha' }, campos.sessao, campos.privados),
      campos.status);
    $('#ranking').append(raiz);
    cards.set(site.id, { raiz, campos });
  }
}

function statusPadrao(site) {
  if (modoDemo) return { estado: 'ok', detalhe: 'Dados simulados' };
  if (site.leitura?.modo === 'chaturbate-api') return { estado: 'aviso', detalhe: 'Conectando à API…' };
  return { estado: 'aviso', detalhe: 'Carregando a página…' };
}

function textoStatus(st) {
  if (st.estado === 'ok') return `✓ ${st.detalhe}${st.lidas ? ` (${st.lidas} msgs)` : ''}`;
  return `${st.estado === 'erro' ? '✕' : '⚠'} ${st.detalhe}`;
}

function desenharRanking() {
  const linhas = metricas.ranking();
  const temLider = linhas[0]?.reaisPorMinuto > 0;
  linhas.forEach((l, i) => {
    const { raiz, campos } = cards.get(l.id);
    const lider = i === 0 && temLider;
    raiz.style.order = i;
    raiz.classList.toggle('lider', lider);
    campos.posicao.textContent = lider ? '🔥 1º' : `${i + 1}º`;
    campos.rpm.textContent = brl.format(l.reaisPorMinuto);
    campos.gorjetas.textContent = `${l.gorjetasJanela} gorjeta${l.gorjetasJanela === 1 ? '' : 's'} em ${janelaMin} min`;
    campos.pessoas.textContent = `👀 ${l.espectadores ?? '—'}`;
    campos.sessao.textContent = `Sessão: ${brl.format(l.reais)}`;
    campos.privados.textContent = `Privados: ${l.privados}`;
    const st = statusLeitura.get(l.id) ?? statusPadrao(porId.get(l.id));
    campos.status.className = `card-status ${st.estado}`;
    campos.status.textContent = textoStatus(st);
    campos.status.title = st.detalhe ?? '';
  });

  const segundos = Math.floor((Date.now() - metricas.inicio) / 1000);
  const hh = String(Math.floor(segundos / 3600)).padStart(2, '0');
  const mm = String(Math.floor((segundos % 3600) / 60)).padStart(2, '0');
  const ss = String(segundos % 60).padStart(2, '0');
  $('#tempo-sessao').textContent = `${hh}:${mm}:${ss}`;
  $('#total-sessao').textContent = brl.format(metricas.totalReais());
}

function atualizarStatus(st) {
  if (!porId.has(st.site)) return;
  statusLeitura.set(st.site, st);
}

/* ---------- Chat de todos os sites ---------- */

const feed = $('#feed');
const btnNovas = $('#btn-novas');
let grudadoNoFim = true;

feed.addEventListener('scroll', () => {
  grudadoNoFim = feed.scrollHeight - feed.scrollTop - feed.clientHeight < 40;
  if (grudadoNoFim) btnNovas.hidden = true;
});
btnNovas.onclick = () => { feed.scrollTop = feed.scrollHeight; };

function visivel(item) {
  if (filtro.escondidos.has(item.dataset.site)) return false;
  if (filtro.modo === 'tudo' || item.dataset.tipo !== 'mensagem') return true;
  if (filtro.modo === 'fas') return !!metricas.fa(item.dataset.site, item.dataset.usuario);
  return false; // "só gorjetas": mensagens comuns ficam escondidas
}

function adicionarAoFeed(ev) {
  const site = porId.get(ev.site);
  let texto = ev.texto ?? '';
  if (ev.tipo === 'gorjeta') {
    texto = `💰 ${ev.tokens} tokens · ${brl.format(ev.reais)}${ev.texto ? ` — ${ev.texto}` : ''}`;
  } else if (ev.tipo === 'privado') {
    texto = `🔒 ${ev.texto || 'Show privado'}`;
  }
  const nome = ev.usuario ?? (ev.tipo === 'gorjeta' ? 'anônimo' : '');
  const item = el('li', {
    class: `item ${ev.tipo}`, cor: site.cor, title: `Abrir a aba do ${site.nome}`,
    'data-site': ev.site, 'data-tipo': ev.tipo, 'data-usuario': ev.usuario ?? '',
    onclick: () => abrirAba(ev.site),
  },
  el('span', { class: 'item-site', textContent: site.nome }),
  el('span', { class: 'item-usuario', textContent: nome },
    ev.fa ? el('span', { class: 'item-fa', textContent: `💎 ${brl.format(ev.fa.reais)}`, title: 'Quanto essa pessoa já deu nesta sessão' }) : null),
  el('span', { class: 'item-texto', textContent: texto }));
  item.hidden = !visivel(item);

  feed.append(item);
  while (feed.childElementCount > MAX_ITENS) feed.firstElementChild.remove();
  if (grudadoNoFim) feed.scrollTop = feed.scrollHeight;
  else if (!item.hidden) btnNovas.hidden = false;
}

function reaplicarFiltro() {
  for (const item of feed.children) item.hidden = !visivel(item);
  if (grudadoNoFim) feed.scrollTop = feed.scrollHeight;
}

function montarFiltros() {
  for (const botao of document.querySelectorAll('.filtro')) {
    botao.onclick = () => {
      filtro.modo = botao.dataset.filtro;
      document.querySelectorAll('.filtro').forEach((b) => b.classList.toggle('ativo', b === botao));
      reaplicarFiltro();
    };
  }
  for (const site of sites) {
    const chip = el('button', { type: 'button', class: 'chip', cor: site.cor, textContent: site.nome, title: 'Mostrar ou esconder este site no chat' });
    chip.onclick = () => {
      if (filtro.escondidos.has(site.id)) filtro.escondidos.delete(site.id);
      else filtro.escondidos.add(site.id);
      chip.classList.toggle('desligado', filtro.escondidos.has(site.id));
      reaplicarFiltro();
    };
    $('#chips-sites').append(chip);
  }
}

/* ---------- Alertas ---------- */

let audio = null;
function bip() {
  try {
    audio ??= new AudioContext();
    const osc = audio.createOscillator();
    const volume = audio.createGain();
    osc.frequency.value = 880;
    volume.gain.setValueAtTime(0.15, audio.currentTime);
    volume.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.35);
    osc.connect(volume).connect(audio.destination);
    osc.start();
    osc.stop(audio.currentTime + 0.35);
  } catch { /* sem som, sem problema */ }
}

function mostrarAviso(texto, cor = '#f472b6', aoClicar = null) {
  const caixa = el('div', { class: 'alerta', cor, textContent: texto });
  if (aoClicar) { caixa.style.cursor = 'pointer'; caixa.onclick = aoClicar; }
  $('#alertas').prepend(caixa);
  setTimeout(() => caixa.remove(), 8000);
}

function mostrarAlerta(a) {
  const site = porId.get(a.site);
  mostrarAviso(`🔥 ${a.nome} esquentou: ${a.quantidade} gorjeta${a.quantidade === 1 ? '' : 's'} (${brl.format(a.reais)}) em ${a.minutos} min`,
    site.cor, () => abrirAba(a.site));
  bip();
}

/* ---------- Abas dos sites ---------- */

const abas = new Map();

function criarWebview(site) {
  const wv = document.createElement('webview');
  wv.setAttribute('partition', `persist:${site.id}`); // cada site guarda o próprio login
  wv.setAttribute('webpreferences', 'backgroundThrottling=no'); // continua lendo com a aba escondida
  wv.setAttribute('allowpopups', '');
  wv.setAttribute('src', site.url);

  wv.addEventListener('dom-ready', () => {
    if (site.leitura?.modo === 'pagina') wv.send('configurar', site.leitura.seletores ?? {});
    if (semSom.has(site.id)) wv.setAudioMuted(true);
  });
  wv.addEventListener('ipc-message', (e) => {
    const [dados] = e.args;
    if (e.channel === 'mensagem' && dados && typeof dados === 'object') {
      const ev = interpretar(dados, site);
      if (ev) receber({ site: site.id, ...ev });
    } else if (e.channel === 'espectadores' && Number.isFinite(dados)) {
      receber({ site: site.id, tipo: 'espectadores', quantidade: dados });
    } else if (e.channel === 'status' && site.leitura?.modo === 'pagina' && dados) {
      atualizarStatus({ site: site.id, estado: dados.estado, detalhe: String(dados.detalhe ?? ''), lidas: dados.lidas });
    }
  });
  wv.addEventListener('did-fail-load', (e) => {
    if (e.isMainFrame && e.errorCode !== -3) {
      atualizarStatus({ site: site.id, estado: 'erro', detalhe: `A página não carregou (${e.errorDescription})` });
    }
  });
  return wv;
}

function montarAbas() {
  const barra = $('#barra-abas');
  for (const site of sites) {
    const botao = el('button', { type: 'button', class: 'aba-botao', cor: site.cor, textContent: site.nome, onclick: () => abrirAba(site.id) });
    const painel = el('div', { class: 'aba', 'data-site': site.id });
    let webview = null;
    if (modoDemo) {
      painel.append(el('p', { class: 'aba-vazia', textContent: `No modo demonstração o ${site.nome} não é aberto. Use "npm start" para abrir os sites de verdade.` }));
    } else {
      webview = criarWebview(site);
      painel.append(webview);
    }
    barra.append(botao);
    $('#abas').append(painel);
    abas.set(site.id, { botao, painel, webview });
  }

  barra.append(el('div', { class: 'aba-ferramentas' },
    el('button', { type: 'button', id: 'btn-som', title: 'Ligar ou desligar o som desta aba', onclick: alternarSom }),
    el('button', { type: 'button', textContent: '↻', title: 'Recarregar esta aba', onclick: () => abas.get(abaAtiva)?.webview?.reload() }),
    el('button', { type: 'button', textContent: '🔧', title: 'Ferramentas do desenvolvedor (para descobrir os seletores do chat)', onclick: () => abas.get(abaAtiva)?.webview?.openDevTools() })));
}

function abrirAba(id) {
  if (!abas.has(id)) return;
  abaAtiva = id;
  for (const [siteId, aba] of abas) {
    aba.botao.classList.toggle('ativa', siteId === id);
    aba.painel.classList.toggle('ativa', siteId === id);
  }
  $('#btn-som').textContent = semSom.has(id) ? '🔇 Sem som' : '🔊 Som';
}

function alternarSom() {
  const aba = abas.get(abaAtiva);
  if (semSom.has(abaAtiva)) semSom.delete(abaAtiva);
  else semSom.add(abaAtiva);
  try { aba?.webview?.setAudioMuted(semSom.has(abaAtiva)); } catch { /* a página ainda não carregou */ }
  abrirAba(abaAtiva);
}

/* ---------- Sessão ---------- */

function receber(ev) {
  const r = metricas.registrar(ev);
  if (!r) return;
  if (r.tipo !== 'espectadores') adicionarAoFeed(r);
  if (r.alerta) mostrarAlerta(r.alerta);
}

function nomeArquivo() {
  const d = new Date(metricas.inicio);
  const dois = (n) => String(n).padStart(2, '0');
  return `sessao-${d.getFullYear()}-${dois(d.getMonth() + 1)}-${dois(d.getDate())}-${dois(d.getHours())}h${dois(d.getMinutes())}.csv`;
}

$('#btn-exportar').onclick = async () => {
  const csv = resumoParaCSV(metricas.resumo());
  if (ponte) {
    const salvo = await ponte.salvarCSV(nomeArquivo(), csv);
    if (salvo) mostrarAviso(`Sessão salva em ${salvo}`);
    return;
  }
  const link = el('a', { href: URL.createObjectURL(new Blob([csv], { type: 'text/csv' })), download: nomeArquivo() });
  link.click();
  URL.revokeObjectURL(link.href);
};

$('#btn-zerar').onclick = () => {
  if (!confirm('Zerar a sessão? Os números somem. Exporte o CSV antes se quiser guardar.')) return;
  metricas = criarMetricas({ ...config, sites });
  feed.replaceChildren();
  btnNovas.hidden = true;
  desenharRanking();
};

/* ---------- Início ---------- */

montarRanking();
montarFiltros();
montarAbas();
abrirAba(abaAtiva);
desenharRanking();
setInterval(desenharRanking, 1000);

if (modoDemo) {
  $('#selo-demo').hidden = false;
  criarSimulador(sites, receber);
} else if (ponte) {
  ponte.aoEvento(receber);
  ponte.aoStatus(atualizarStatus);
  ponte.iniciarLeituras();
}
