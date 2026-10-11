// Roda dentro de cada aba de site. Só LÊ o chat da página e avisa o painel: não clica nem escreve nada.
// O painel manda os seletores CSS (config/sites.js) pelo canal "configurar" quando a página carrega.

const { ipcRenderer } = require('electron');

const AQUECIMENTO_MS = 5000; // mensagens que aparecem logo ao abrir são histórico, não contam

let seletores = {};
let observador = null;
let timerEspectadores = null;
let vistos = new WeakSet();
let aquecendo = true;
let agendado = false;
let lidas = 0;

const status = (estado, detalhe) => ipcRenderer.sendToHost('status', { estado, detalhe, lidas });
const limpar = (s) => (s ?? '').replace(/\s+/g, ' ').trim();

function textoDe(raiz, seletor) {
  if (!seletor) return '';
  try {
    return limpar(raiz.querySelector(seletor)?.textContent);
  } catch {
    return '';
  }
}

function varrer() {
  let lista;
  try {
    lista = document.querySelectorAll(seletores.mensagem);
  } catch {
    status('erro', 'Seletor de mensagem inválido');
    return;
  }
  let novas = 0;
  for (const el of lista) {
    if (vistos.has(el)) continue;
    vistos.add(el);
    if (aquecendo) continue;
    let marcado = false;
    if (seletores.gorjeta) {
      try { marcado = el.matches(seletores.gorjeta) || !!el.querySelector(seletores.gorjeta); } catch { /* seletor inválido */ }
    }
    ipcRenderer.sendToHost('mensagem', {
      usuario: textoDe(el, seletores.usuario),
      texto: textoDe(el, seletores.texto),
      textoCompleto: limpar(el.textContent),
      marcadoComoGorjeta: marcado,
    });
    novas++;
  }
  if (novas) {
    lidas += novas;
    status('ok', 'Lendo o chat da página');
  }
}

function paraNumero(texto) {
  const m = texto.match(/(\d{1,3}(?:[.,]\d{3})+|\d+(?:[.,]\d+)?)\s*(k|mil)?/i);
  if (!m) return null;
  if (m[2]) return Math.round(parseFloat(m[1].replace(',', '.')) * 1000);
  return parseInt(m[1].replace(/[.,]/g, ''), 10);
}

function lerEspectadores() {
  const n = paraNumero(textoDe(document, seletores.espectadores));
  if (n != null) ipcRenderer.sendToHost('espectadores', n);
}

function iniciar() {
  observador?.disconnect();
  clearInterval(timerEspectadores);
  if (!seletores.mensagem) {
    status('aviso', 'Leitura do chat não configurada');
    return;
  }
  vistos = new WeakSet();
  aquecendo = true;
  varrer();
  setTimeout(() => { varrer(); aquecendo = false; }, AQUECIMENTO_MS);
  status('ok', 'Lendo o chat da página');

  observador = new MutationObserver(() => {
    if (agendado) return;
    agendado = true;
    setTimeout(() => { agendado = false; varrer(); }, 150);
  });
  observador.observe(document.documentElement, { childList: true, subtree: true });
  if (seletores.espectadores) {
    lerEspectadores();
    timerEspectadores = setInterval(lerEspectadores, 5000);
  }
}

ipcRenderer.on('configurar', (_e, s) => {
  seletores = s ?? {};
  iniciar();
});
