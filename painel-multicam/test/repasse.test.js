import { test } from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { PassThrough } from 'node:stream';
import { criarRepasse } from '../repasse.js';
import { saidasDesejadas, motivoParado } from '../src/core/transmissao.js';

test('regra do privado: só o site do privado continua', () => {
  const destinos = ['a', 'b', 'c'];
  const ligados = new Set(['a', 'b']);
  const ids = (s) => [...s].sort();
  assert.deepEqual(ids(saidasDesejadas({ destinos, ligados, obsAoVivo: false, privadoEm: null })), []);
  assert.deepEqual(ids(saidasDesejadas({ destinos, ligados, obsAoVivo: true, privadoEm: null })), ['a', 'b']);
  assert.deepEqual(ids(saidasDesejadas({ destinos, ligados, obsAoVivo: true, privadoEm: 'b' })), ['b']);
  assert.deepEqual(ids(saidasDesejadas({ destinos, ligados, obsAoVivo: true, privadoEm: 'c' })), ['c'], 'privado liga o site mesmo desligado');
  assert.deepEqual(ids(saidasDesejadas({ destinos, ligados, obsAoVivo: true, privadoEm: 'x' })), []);
  assert.equal(motivoParado({ id: 'a', ligados, obsAoVivo: true, privadoEm: 'b' }), 'Pausado: privado em outro site');
  assert.equal(motivoParado({ id: 'c', ligados, obsAoVivo: true, privadoEm: null }), 'Desligado');
  assert.equal(motivoParado({ id: 'a', ligados, obsAoVivo: false, privadoEm: null }), 'Esperando o OBS');
});

class ProcessoFalso extends EventEmitter {
  constructor(args) {
    super();
    this.args = args;
    this.stdout = new PassThrough();
    this.stderr = new PassThrough();
    this.stdin = new PassThrough();
    this.recebido = [];
    this.stdin.on('data', (d) => this.recebido.push(d));
    this.morto = false;
  }
  kill() {
    if (this.morto) return;
    this.morto = true;
    setImmediate(() => this.emit('close', null));
  }
}

const esperar = (ms = 20) => new Promise((r) => setTimeout(r, ms));

function flv() {
  const tag = (tipo, dados) => {
    const t = Buffer.alloc(11 + dados.length + 4);
    t[0] = tipo;
    t.writeUIntBE(dados.length, 1, 3);
    Buffer.from(dados).copy(t, 11);
    return t;
  };
  return Buffer.concat([
    Buffer.from([0x46, 0x4c, 0x56, 1, 5, 0, 0, 0, 9, 0, 0, 0, 0]),
    tag(9, [0x17, 0, 0, 0, 0, 1]),
    tag(9, [0x17, 1, 0, 0, 0, 9]),
  ]);
}

function montar() {
  const processos = [];
  const estados = [];
  const repasse = criarRepasse({
    ffmpeg: 'ffmpeg',
    destinos: {
      a: { url: 'rtmp://site-a/app/SEGREDO-A', chave: 'SEGREDO-A' },
      b: { url: 'rtmp://site-b/app/SEGREDO-B', chave: 'SEGREDO-B' },
    },
    spawn: (_cmd, args) => { const p = new ProcessoFalso(args); processos.push(p); return p; },
    aoMudar: (e) => estados.push(e),
  });
  const saidaDe = (id) => processos.filter((p) => p.args.at(-1).includes(`site-${id}`)).at(-1);
  return { repasse, processos, estados, saidaDe, receptor: () => processos.find((p) => p.args.includes('-listen')) };
}

test('abre um envio por site quando o OBS começa e pausa os outros no privado', async () => {
  const { repasse, receptor, saidaDe } = montar();
  repasse.iniciar();
  assert.equal(repasse.estado().receptor.estado, 'esperando');
  assert.ok(receptor().args.includes('rtmp://127.0.0.1:1935/live/painel'));

  receptor().stdout.write(flv());
  await esperar();
  assert.equal(repasse.estado().obsAoVivo, true);
  assert.ok(saidaDe('a').recebido.length > 0, 'site A recebeu vídeo');
  assert.ok(saidaDe('b').recebido.length > 0, 'site B recebeu vídeo');

  const b = saidaDe('b');
  repasse.privado('a');
  await esperar();
  assert.equal(b.morto, true);
  assert.equal(saidaDe('a').morto, false);
  assert.equal(repasse.estado().saidas.b.detalhe, 'Pausado: privado em outro site');

  repasse.privado(null);
  await esperar();
  assert.notEqual(saidaDe('b'), b, 'abriu um envio novo para o B');
  assert.equal(saidaDe('b').morto, false);

  repasse.ligar('b', false);
  await esperar();
  assert.equal(saidaDe('b').morto, true);
  assert.equal(repasse.estado().saidas.b.detalhe, 'Desligado');
  repasse.parar();
});

test('erro do site aparece sem a chave e tenta de novo', async () => {
  const { repasse, receptor, saidaDe } = montar();
  repasse.iniciar();
  receptor().stdout.write(flv());
  await esperar();
  const a = saidaDe('a');
  a.stderr.write('rtmp://site-a/app/SEGREDO-A: Connection refused (SEGREDO-A)\n');
  await esperar();
  a.emit('close', 1);
  await esperar();
  const detalhe = repasse.estado().saidas.a.detalhe;
  assert.equal(repasse.estado().saidas.a.estado, 'erro');
  assert.ok(!detalhe.includes('SEGREDO'), detalhe);
  assert.ok(detalhe.includes('Connection refused'));

  repasse.ligar('a', true); // religar tenta na hora
  await esperar();
  assert.notEqual(saidaDe('a'), a);
  repasse.parar();
});

test('quando o OBS para, fecha os envios e volta a esperar', async () => {
  const { repasse, receptor, saidaDe, processos } = montar();
  repasse.iniciar();
  const r = receptor();
  r.stdout.write(flv());
  await esperar();
  r.emit('close', 0);
  await esperar();
  assert.equal(saidaDe('a').morto, true);
  assert.equal(repasse.estado().receptor.detalhe, 'O OBS parou de transmitir');
  await esperar(600);
  assert.equal(processos.filter((p) => p.args.includes('-listen')).length, 2, 'abriu um receptor novo');
  repasse.parar();
});
