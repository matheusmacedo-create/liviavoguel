import { test } from 'node:test';
import assert from 'node:assert/strict';
import { criarMetricas, valorEmReais } from '../src/core/metricas.js';

const MIN = 60_000;
const config = {
  cotacao: { USD: 5 },
  janelaRankingMin: 5,
  alertas: { janelaMin: 2, minGorjetas: 3, minValorBRL: 1000, intervaloMin: 3 },
  sites: [
    { id: 'a', nome: 'Site A', valorToken: 0.05, moedaToken: 'USD' },
    { id: 'b', nome: 'Site B', valorToken: 1, moedaToken: 'BRL' },
  ],
};

function relogio(inicio = 1_000_000) {
  let t = inicio;
  return { agora: () => t, avancar: (ms) => { t += ms; } };
}

test('converte tokens em reais pela moeda do site', () => {
  assert.equal(valorEmReais(config.sites[0], 100, config.cotacao), 25);
  assert.equal(valorEmReais(config.sites[1], 100, config.cotacao), 100);
  assert.equal(valorEmReais({ valorToken: 1, moedaToken: 'EUR' }, 100, config.cotacao), 0);
});

test('ranking ordena por R$/min da janela e esquece gorjetas antigas', () => {
  const r = relogio();
  const m = criarMetricas(config, r.agora);
  m.registrar({ site: 'b', tipo: 'gorjeta', usuario: 'x', tokens: 50 });
  r.avancar(1 * MIN);
  m.registrar({ site: 'a', tipo: 'gorjeta', usuario: 'y', tokens: 100 }); // R$ 25

  let [primeiro, segundo] = m.ranking();
  assert.equal(primeiro.id, 'b');
  assert.equal(primeiro.reaisPorMinuto, 10);
  assert.equal(segundo.reaisPorMinuto, 5);

  r.avancar(4.5 * MIN); // a gorjeta do site B saiu da janela de 5 min, a do A ainda não
  [primeiro, segundo] = m.ranking();
  assert.equal(primeiro.id, 'a');
  assert.equal(segundo.reaisPorMinuto, 0);
  assert.equal(segundo.reais, 50, 'o total da sessão não some com a janela');
});

test('alerta quando o site esquenta, com intervalo entre alertas', () => {
  const r = relogio();
  const m = criarMetricas(config, r.agora);
  const gorjeta = () => m.registrar({ site: 'b', tipo: 'gorjeta', usuario: 'z', tokens: 10 });

  assert.equal(gorjeta().alerta, undefined);
  r.avancar(30_000);
  assert.equal(gorjeta().alerta, undefined);
  r.avancar(30_000);
  const terceira = gorjeta();
  assert.equal(terceira.alerta.quantidade, 3);
  assert.equal(terceira.alerta.nome, 'Site B');

  r.avancar(30_000);
  assert.equal(gorjeta().alerta, undefined, 'não repete antes do intervalo');
  r.avancar(3 * MIN);
  gorjeta(); gorjeta();
  assert.ok(gorjeta().alerta, 'volta a avisar depois do intervalo');
});

test('soma quanto cada fã deu, sem diferenciar maiúsculas', () => {
  const m = criarMetricas(config, relogio().agora);
  m.registrar({ site: 'b', tipo: 'gorjeta', usuario: 'Joao', tokens: 10 });
  const msg = m.registrar({ site: 'b', tipo: 'mensagem', usuario: 'joao', texto: 'oi' });
  m.registrar({ site: 'b', tipo: 'gorjeta', usuario: 'JOAO', tokens: 5 });
  assert.equal(msg.fa.reais, 10);
  assert.equal(m.fa('b', 'joao').tokens, 15);
  assert.equal(m.fa('a', 'joao'), null, 'fã é por site');
  assert.equal(m.registrar({ site: 'b', tipo: 'mensagem', usuario: 'outro', texto: 'oi' }).fa, undefined);
});

test('resumo guarda pico de pessoas, privados e totais', () => {
  const r = relogio();
  const m = criarMetricas(config, r.agora);
  m.registrar({ site: 'a', tipo: 'espectadores', quantidade: 120 });
  m.registrar({ site: 'a', tipo: 'espectadores', quantidade: 80 });
  m.registrar({ site: 'a', tipo: 'privado', usuario: 'k' });
  r.avancar(10 * MIN);
  m.registrar({ site: 'a', tipo: 'gorjeta', usuario: 'k', tokens: 200 });

  const resumo = m.resumo();
  const a = resumo.sites.find((s) => s.id === 'a');
  assert.deepEqual([a.pico, a.privados, a.tokens, a.reais], [120, 1, 200, 50]);
  assert.equal(resumo.fim - resumo.inicio, 10 * MIN);
  assert.equal(m.totalReais(), 50);
  assert.equal(m.registrar({ site: 'nao-existe', tipo: 'mensagem' }), null);
});
