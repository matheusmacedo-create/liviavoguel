// Repasse da transmissão: o OBS manda UM sinal para o painel, e o painel repassa para cada site.
//
// Um ffmpeg (o "receptor") espera o OBS em rtmp://127.0.0.1:<porta>/live/<chave> e entrega o vídeo em FLV.
// O distribuidor (src/core/flv.js) copia esse vídeo para um ffmpeg por site, que envia para o RTMP do site.
// Como cada site tem o seu processo, dá para pausar todos menos um quando começa um show privado.
// Nada é recodificado (-c copy): o repasse quase não usa processador.
//
// O receptor só aceita conexões do próprio computador (127.0.0.1). O ffmpeg não confere a chave,
// então qualquer chave que o OBS mandar funciona.

import { spawn as spawnPadrao } from 'node:child_process';
import { saidasDesejadas, motivoParado } from './src/core/transmissao.js';
import { criarDistribuidorFLV } from './src/core/flv.js';

const LIMITE_ATRASO = 8 * 1024 * 1024; // site que acumula mais que isso sem enviar é reconectado
const NO_AR_APOS_MS = 3000;

const ultimaLinha = (texto) => texto.trim().split(/\r?\n/).filter(Boolean).pop() ?? '';

function umaVez(fn) {
  let feito = false;
  return (...args) => { if (!feito) { feito = true; fn(...args); } };
}

// destinos: { [siteId]: { url, chave } }, com url = servidor RTMP do site + chave do site.
export function criarRepasse({ ffmpeg, porta = 1935, chave = 'painel', destinos, spawn = spawnPadrao, aoMudar = () => {} }) {
  const ids = Object.keys(destinos);
  const ligados = new Set(ids);
  const saidas = new Map(ids.map((id) => [id, { proc: null, cancelar: null, estado: 'parado', detalhe: '', falhas: 0, timer: null }]));
  const segredos = ids.flatMap((id) => [destinos[id].url, destinos[id].chave]).filter(Boolean);
  const distribuidor = criarDistribuidorFLV();

  let ativo = false;
  let receptor = null;
  let timerReceptor = null;
  let falhasReceptor = 0;
  let estadoReceptor = { estado: 'parado', detalhe: 'Desligado' };
  let obsAoVivo = false;
  let privadoEm = null;

  // Mensagens de erro do ffmpeg podem trazer o endereço com a chave do site: nunca mostrar.
  const redigir = (texto) => segredos.reduce((t, s) => t.split(s).join('***'), String(texto ?? ''));

  function estado() {
    return {
      obsAoVivo,
      privadoEm,
      receptor: { ...estadoReceptor },
      saidas: Object.fromEntries(ids.map((id) => {
        const s = saidas.get(id);
        const detalhe = s.estado === 'parado' ? motivoParado({ id, ligados, obsAoVivo, privadoEm }) : s.detalhe;
        return [id, { ligado: ligados.has(id), estado: s.estado, detalhe }];
      })),
    };
  }

  const publicar = () => aoMudar(estado());

  function reconciliar() {
    const querer = saidasDesejadas({ destinos: ids, ligados, obsAoVivo, privadoEm });
    for (const id of ids) {
      const s = saidas.get(id);
      if (querer.has(id)) {
        if (!s.proc && !s.timer) iniciarSaida(id);
      } else {
        pararSaida(id);
      }
    }
    publicar();
  }

  /* ---------- Receptor (OBS → painel) ---------- */

  function iniciarReceptor() {
    if (!ativo) return;
    const args = [
      '-hide_banner', '-loglevel', 'error', '-nostdin',
      '-listen', '1', '-i', `rtmp://127.0.0.1:${porta}/live/${chave}`,
      '-map', '0', '-c', 'copy',
      '-f', 'flv', '-flvflags', 'no_duration_filesize', 'pipe:1',
    ];
    let erro = '';
    const proc = spawn(ffmpeg, args, { stdio: ['ignore', 'pipe', 'pipe'] });
    receptor = proc;
    distribuidor.reiniciar();
    estadoReceptor = { estado: 'esperando', detalhe: 'Esperando o OBS' };
    publicar();

    proc.stderr.on('data', (d) => { erro = (erro + d).slice(-4000); });
    proc.stdout.on('data', (pedaco) => receberVideo(proc, pedaco));
    const fim = umaVez((mensagem) => {
      if (receptor === proc) receptor = null;
      const estavaAoVivo = obsAoVivo;
      obsAoVivo = false;
      if (!ativo) {
        estadoReceptor = { estado: 'parado', detalhe: 'Desligado' };
        reconciliar();
        return;
      }
      let atraso = 500;
      if (estavaAoVivo) {
        falhasReceptor = 0;
        estadoReceptor = { estado: 'esperando', detalhe: 'O OBS parou de transmitir' };
      } else {
        // Saiu sem nunca receber vídeo: porta ocupada, ffmpeg ausente...
        falhasReceptor++;
        atraso = Math.min(30_000, 1000 * 2 ** falhasReceptor);
        estadoReceptor = { estado: 'erro', detalhe: redigir(mensagem || ultimaLinha(erro)) || 'O receptor do OBS fechou' };
      }
      reconciliar();
      timerReceptor = setTimeout(iniciarReceptor, atraso);
    });
    proc.on('error', (err) => fim(err.message));
    proc.on('close', () => fim());
  }

  function receberVideo(proc, pedaco) {
    if (proc !== receptor) return;
    if (!obsAoVivo) {
      obsAoVivo = true;
      falhasReceptor = 0;
      estadoReceptor = { estado: 'ao-vivo', detalhe: 'Recebendo o OBS' };
      reconciliar(); // abre as saídas antes de entregar este pedaço
    }
    try {
      distribuidor.alimentar(pedaco);
    } catch (err) {
      estadoReceptor = { estado: 'erro', detalhe: err.message };
      proc.kill();
    }
  }

  /* ---------- Saídas (painel → sites) ---------- */

  function iniciarSaida(id) {
    const s = saidas.get(id);
    const args = [
      '-hide_banner', '-loglevel', 'error',
      '-f', 'flv', '-i', 'pipe:0',
      '-map', '0', '-c', 'copy',
      '-f', 'flv', '-flvflags', 'no_duration_filesize', destinos[id].url,
    ];
    let erro = '';
    const proc = spawn(ffmpeg, args, { stdio: ['pipe', 'ignore', 'pipe'] });
    s.proc = proc;
    s.estado = 'conectando';
    s.detalhe = 'Conectando ao site';
    let atrasado = false;
    s.cancelar = distribuidor.assinar((buffer) => {
      if (atrasado) return;
      if (proc.stdin.writableLength > LIMITE_ATRASO) {
        atrasado = true;
        setImmediate(() => { if (s.proc === proc) reiniciarSaida(id); });
        return;
      }
      proc.stdin.write(buffer);
    });

    proc.stdin.on('error', () => { /* o site caiu: o "close" abaixo cuida */ });
    proc.stderr.on('data', (d) => { erro = (erro + d).slice(-4000); });
    const timerNoAr = setTimeout(() => {
      if (s.proc !== proc) return;
      s.estado = 'no-ar';
      s.detalhe = 'No ar';
      s.falhas = 0;
      publicar();
    }, NO_AR_APOS_MS);

    const fim = umaVez((mensagem) => {
      clearTimeout(timerNoAr);
      if (s.proc !== proc) return; // foi parado de propósito
      s.cancelar?.();
      s.cancelar = null;
      s.proc = null;
      s.falhas++;
      s.estado = 'erro';
      s.detalhe = redigir(mensagem || ultimaLinha(erro)) || 'A conexão com o site caiu';
      const atraso = Math.min(30_000, 2000 * 2 ** (s.falhas - 1));
      s.timer = setTimeout(() => { s.timer = null; reconciliar(); }, atraso);
      publicar();
    });
    proc.on('error', (err) => fim(err.message));
    proc.on('close', () => fim());
  }

  function encerrar(s) {
    s.cancelar?.();
    s.cancelar = null;
    if (s.proc) {
      const proc = s.proc;
      s.proc = null;
      proc.stdin.end();
      proc.kill();
    }
  }

  function pararSaida(id) {
    const s = saidas.get(id);
    clearTimeout(s.timer);
    s.timer = null;
    encerrar(s);
    s.estado = 'parado';
    s.detalhe = '';
  }

  function reiniciarSaida(id) {
    encerrar(saidas.get(id));
    iniciarSaida(id);
    saidas.get(id).detalhe = 'A internet não deu conta: reconectando';
    publicar();
  }

  function ligarSemReconciliar(id, valor) {
    const s = saidas.get(id);
    if (valor) ligados.add(id); else ligados.delete(id);
    clearTimeout(s.timer); // quem religa quer tentar já, sem esperar
    s.timer = null;
    s.falhas = 0;
  }

  /* ---------- Comandos ---------- */

  return {
    estado,
    iniciar() {
      if (ativo) return;
      ativo = true;
      iniciarReceptor();
    },
    parar() {
      ativo = false;
      clearTimeout(timerReceptor);
      for (const id of ids) pararSaida(id);
      receptor?.kill();
      publicar();
    },
    ligar(id, valor) {
      if (!saidas.has(id)) return;
      ligarSemReconciliar(id, valor);
      reconciliar();
    },
    ligarTodos(valor) {
      for (const id of ids) ligarSemReconciliar(id, valor);
      reconciliar();
    },
    privado(id) {
      privadoEm = id && saidas.has(id) ? id : null;
      reconciliar();
    },
  };
}
