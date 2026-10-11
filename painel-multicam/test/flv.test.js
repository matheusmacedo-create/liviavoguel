import { test } from 'node:test';
import assert from 'node:assert/strict';
import { criarDistribuidorFLV, criarLeitorFLV, infoDaTag, tempoDaTag, comTempo } from '../src/core/flv.js';

const CABECALHO = Buffer.from([0x46, 0x4c, 0x56, 1, 5, 0, 0, 0, 9, 0, 0, 0, 0]);

function tag(tipo, tempo, dados) {
  const d = Buffer.from(dados);
  const t = Buffer.alloc(11 + d.length + 4);
  t[0] = tipo;
  t.writeUIntBE(d.length, 1, 3);
  t[4] = (tempo >>> 16) & 0xff; t[5] = (tempo >>> 8) & 0xff; t[6] = tempo & 0xff; t[7] = (tempo >>> 24) & 0xff;
  d.copy(t, 11);
  t.writeUInt32BE(11 + d.length, 11 + d.length);
  return t;
}

const meta = (ms) => tag(18, ms, [2, 0, 10, 0x6f]);
const seqVideo = (ms) => tag(9, ms, [0x17, 0, 0, 0, 0, 1]);
const seqAudio = (ms) => tag(8, ms, [0xaf, 0, 0x12]);
const chave = (ms) => tag(9, ms, [0x17, 1, 0, 0, 0, 9]);
const quadro = (ms) => tag(9, ms, [0x27, 1, 0, 0, 0, 8]);
const audio = (ms) => tag(8, ms, [0xaf, 1, 7]);

function juntar(pedacos) {
  return Buffer.concat(pedacos);
}

function tagsDe(buffer) {
  const tags = [];
  let cab = null;
  criarLeitorFLV({ aoCabecalho: (c) => { cab = c; }, aoTag: (t) => tags.push(t) })(buffer);
  return { cab, tags };
}

test('identifica quadro-chave e pacotes de configuração', () => {
  assert.deepEqual(infoDaTag(chave(40)), { tipo: 9, tempo: 40, chave: true, sequencia: false });
  assert.equal(infoDaTag(seqVideo(0)).sequencia, true);
  assert.equal(infoDaTag(quadro(0)).chave, false);
  assert.equal(infoDaTag(seqAudio(0)).sequencia, true);
  assert.equal(infoDaTag(audio(0)).sequencia, false);
  // Enhanced RTMP: bit alto ligado, tipo de quadro em 3 bits, tipo de pacote nos 4 bits de baixo.
  assert.deepEqual([infoDaTag(tag(9, 0, [0x90, 0x68, 0x76, 0x63, 0x31])).sequencia, infoDaTag(tag(9, 0, [0x91, 1, 2, 3, 4])).chave], [true, true]);
});

test('tempo acima de 24 bits usa o byte alto', () => {
  const t = comTempo(chave(0), 20_000_000);
  assert.equal(tempoDaTag(t), 20_000_000);
});

test('leitor junta tags quebradas em pedaços', () => {
  const fluxo = juntar([CABECALHO, meta(0), chave(0), quadro(33)]);
  const tags = [];
  let cab = null;
  const ler = criarLeitorFLV({ aoCabecalho: (c) => { cab = c; }, aoTag: (t) => tags.push(t) });
  for (let i = 0; i < fluxo.length; i += 5) ler(fluxo.subarray(i, i + 5));
  assert.deepEqual(cab, CABECALHO);
  assert.equal(tags.length, 3);
  assert.throws(() => criarLeitorFLV({ aoCabecalho() {}, aoTag() {} })(Buffer.from('NAOFLV...')));
});

test('site que entra no meio recebe cabeçalho e configuração e começa num quadro-chave, com tempo do zero', () => {
  const dist = criarDistribuidorFLV();
  dist.alimentar(juntar([CABECALHO, meta(0), seqVideo(0), seqAudio(0), chave(0), audio(10), quadro(33)]));

  const recebido = [];
  dist.assinar((b) => recebido.push(b));
  dist.alimentar(juntar([quadro(66), audio(70)])); // antes do quadro-chave: não entra
  assert.equal(recebido.length, 0);
  dist.alimentar(juntar([chave(2000), audio(2010), quadro(2033)]));

  const { cab, tags } = tagsDe(juntar(recebido));
  assert.deepEqual(cab, CABECALHO);
  const resumo = tags.map((t) => [t[0], tempoDaTag(t), infoDaTag(t).sequencia, infoDaTag(t).chave]);
  assert.deepEqual(resumo, [
    [18, 0, false, false],
    [9, 0, true, true], // o pacote de configuração do vídeo vem marcado como quadro-chave
    [8, 0, true, false],
    [9, 0, false, true],
    [8, 10, false, false],
    [9, 33, false, false],
  ]);
});

test('cancelar para de enviar e reiniciar exige novo quadro-chave', () => {
  const dist = criarDistribuidorFLV();
  const a = [];
  const b = [];
  const cancelarA = dist.assinar((x) => a.push(x));
  dist.assinar((x) => b.push(x));
  dist.alimentar(juntar([CABECALHO, seqVideo(0), chave(0)]));
  const tamA = a.length;
  cancelarA();
  dist.alimentar(quadro(33));
  assert.equal(a.length, tamA);

  dist.reiniciar(); // o OBS parou e voltou
  const antes = b.length;
  dist.alimentar(juntar([CABECALHO, seqVideo(0), quadro(10)]));
  assert.equal(b.length, antes, 'espera o quadro-chave do envio novo');
  dist.alimentar(chave(500));
  assert.ok(b.length > antes);
});
