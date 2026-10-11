import { test } from 'node:test';
import assert from 'node:assert/strict';
import { interpretar } from '../src/core/leitura.js';
import { converterEventoChaturbate } from '../src/core/chaturbate.js';

const semSeletorGorjeta = { leitura: { modo: 'pagina', seletores: { mensagem: '.msg' } } };
const comSeletorGorjeta = { leitura: { modo: 'pagina', seletores: { mensagem: '.msg', gorjeta: '.tip' } } };

test('reconhece avisos de gorjeta em inglês e português', () => {
  assert.deepEqual(
    interpretar({ usuario: 'joao', textoCompleto: 'joao tipped 50 tokens' }, semSeletorGorjeta),
    { tipo: 'gorjeta', usuario: 'joao', tokens: 50, texto: '' },
  );
  assert.equal(interpretar({ textoCompleto: 'Maria deu 1.000 créditos' }, semSeletorGorjeta).tokens, 1000);
  assert.equal(interpretar({ textoCompleto: 'rafa enviou 25 moedas!' }, semSeletorGorjeta).tokens, 25);
  const comRecado = interpretar({ usuario: 'ana', texto: 'linda', textoCompleto: 'ana tipped 10 tokens linda' }, semSeletorGorjeta);
  assert.deepEqual([comRecado.tipo, comRecado.texto], ['gorjeta', 'linda']);
});

test('mensagem comum continua mensagem', () => {
  const ev = interpretar({ usuario: 'leo', texto: 'oi linda', textoCompleto: 'leo: oi linda' }, semSeletorGorjeta);
  assert.deepEqual(ev, { tipo: 'mensagem', usuario: 'leo', texto: 'oi linda' });
  assert.equal(interpretar({ textoCompleto: '   ' }, semSeletorGorjeta), null);
});

test('gorjeta falsa digitada no chat não conta', () => {
  const ev = interpretar(
    { usuario: 'troll', texto: 'tipped 1000 tokens kkk', textoCompleto: 'troll: tipped 1000 tokens kkk' },
    semSeletorGorjeta,
  );
  assert.equal(ev.tipo, 'mensagem');
});

test('com seletor de gorjeta, só o que o site marcou conta como gorjeta', () => {
  assert.equal(interpretar({ usuario: 'x', textoCompleto: 'x tipped 50 tokens' }, comSeletorGorjeta).tipo, 'mensagem');
  const marcada = interpretar({ usuario: 'x', textoCompleto: 'x 🎁 120', marcadoComoGorjeta: true }, comSeletorGorjeta);
  assert.deepEqual([marcada.tipo, marcada.tokens], ['gorjeta', 120]);
});

test('regex próprio do site, com nome de usuário dentro', () => {
  const site = { leitura: { seletores: {}, regexGorjeta: '(?<usuario>\\S+) mandou um presente de (?<tokens>\\d+)' } };
  const ev = interpretar({ textoCompleto: 'zeca mandou um presente de 30' }, site);
  assert.deepEqual([ev.tipo, ev.usuario, ev.tokens], ['gorjeta', 'zeca', 30]);
});

test('aviso de privado do site conta, pergunta no chat não', () => {
  assert.equal(interpretar({ textoCompleto: 'Show privado iniciado com joao' }, semSeletorGorjeta).tipo, 'privado');
  assert.equal(interpretar({ usuario: 'u', textoCompleto: 'vai ter show privado hoje?' }, semSeletorGorjeta).tipo, 'mensagem');
});

test('converte eventos da API do Chaturbate', () => {
  assert.deepEqual(
    converterEventoChaturbate({ method: 'tip', object: { tip: { tokens: 35, message: 'linda', isAnon: false }, user: { username: 'bob' } } }),
    { tipo: 'gorjeta', usuario: 'bob', tokens: 35, texto: 'linda' },
  );
  assert.equal(converterEventoChaturbate({ method: 'tip', object: { tip: { tokens: 10, isAnon: true }, user: { username: 'bob' } } }).usuario, null);
  assert.deepEqual(
    converterEventoChaturbate({ method: 'chatMessage', object: { message: { message: 'hi' }, user: { username: 'al' } } }),
    { tipo: 'mensagem', usuario: 'al', texto: 'hi' },
  );
  assert.equal(converterEventoChaturbate({ method: 'privateMessage', object: {} }), null);
  assert.equal(converterEventoChaturbate({ method: 'userEnter', object: {} }), null);
});
