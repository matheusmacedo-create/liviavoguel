import { test } from 'node:test';
import assert from 'node:assert/strict';
import { analisar, coberturaPorHora, inicioDaHora, nomeHorario } from '../src/core/historico.js';
import { criarMetricas } from '../src/core/metricas.js';
import { historicoParaCSV } from '../src/core/csv.js';

const em = (dia, hora, minuto = 0) => new Date(2026, 9, dia, hora, minuto).getTime(); // outubro de 2026
const H = 3_600_000;

test('divide a live nas horas cheias do relógio', () => {
  assert.deepEqual(coberturaPorHora(em(10, 21, 30), em(10, 23, 15)), [
    { hora: em(10, 21), minutos: 30 },
    { hora: em(10, 22), minutos: 60 },
    { hora: em(10, 23), minutos: 15 },
  ]);
  assert.deepEqual(coberturaPorHora(em(10, 21), em(10, 21)), []);
  assert.equal(inicioDaHora(em(10, 21, 59)), em(10, 21));
  assert.equal(nomeHorario(6, 9), 'Sábado 09h');
});

test('R$/hora por site, mapa por dia e hora e melhores horários', () => {
  const sabado = {
    inicio: em(10, 21), fim: em(10, 23), // 10/10/2026 é sábado
    sites: [{ id: 'a', nome: 'A', reais: 300, gorjetas: 10, privados: 1 }, { id: 'b', nome: 'B', reais: 60, gorjetas: 2, privados: 0 }],
    porHora: { [em(10, 21)]: { a: 100, b: 60 }, [em(10, 22)]: { a: 200 } },
  };
  const terca = {
    inicio: em(13, 20), fim: em(13, 20, 20), // só 20 minutos: fica fora dos "melhores"
    sites: [{ id: 'a', nome: 'A', reais: 500, gorjetas: 1, privados: 0 }],
    porHora: { [em(13, 20)]: { a: 500 } },
  };
  const r = analisar([sabado, terca]);

  assert.equal(r.sessoes, 2);
  assert.equal(r.reaisTotais, 860);
  assert.ok(Math.abs(r.horasTotais - (2 + 1 / 3)) < 1e-9);

  const [a, b] = r.sites;
  assert.equal(a.id, 'a');
  assert.ok(Math.abs(a.reaisPorHora - 800 / (2 + 1 / 3)) < 1e-9);
  assert.equal(b.reaisPorHora, 30);
  assert.deepEqual([a.melhor.dia, a.melhor.hora, a.melhor.reaisPorHora], [6, 22, 200]);
  assert.deepEqual([b.melhor.dia, b.melhor.hora], [6, 21]);

  assert.deepEqual([r.grade[6][21].reais, r.grade[6][21].horas, r.grade[6][21].reaisPorHora], [160, 1, 160]);
  assert.equal(r.grade[2][20].reaisPorHora, 1500, 'terça 20h: R$ 500 em 20 minutos');
  assert.equal(r.grade[0][0].reaisPorHora, null, 'sem live: sem valor');
  assert.deepEqual(r.melhores.map((c) => [c.dia, c.hora]), [[6, 22], [6, 21]], 'a terça com 20 minutos não entra');
  assert.equal(r.maiorReaisPorHora, 200, 'a escala ignora os R$ 1500/h de 20 minutos');
});

test('métricas guardam quanto entrou em cada hora para o histórico', () => {
  let agora = em(10, 21, 50);
  const m = criarMetricas({ sites: [{ id: 'a', nome: 'A', valorToken: 1, moedaToken: 'BRL' }] }, () => agora);
  assert.equal(m.temDados(), false);
  m.registrar({ site: 'a', tipo: 'espectadores', quantidade: 10 });
  assert.equal(m.temDados(), false, 'só número de pessoas não conta como live');
  m.registrar({ site: 'a', tipo: 'gorjeta', usuario: 'x', tokens: 10 });
  agora += 0.25 * H;
  m.registrar({ site: 'a', tipo: 'gorjeta', usuario: 'x', tokens: 5 });
  const r = m.resumo();
  assert.equal(m.temDados(), true);
  assert.equal(r.id, r.inicio);
  assert.deepEqual(r.porHora, { [em(10, 21)]: { a: 10 }, [em(10, 22)]: { a: 5 } });
});

test('CSV do histórico tem uma linha por site em cada live, em ordem de data', () => {
  const live = (dia, reais) => ({ inicio: em(dia, 21), fim: em(dia, 22), sites: [{ nome: 'A', pico: 0, tokens: reais, reais, gorjetas: 1, privados: 0, mensagens: 3 }] });
  const linhas = historicoParaCSV([live(12, 20), live(10, 10)]).trim().split('\r\n');
  assert.equal(linhas.length, 3);
  assert.ok(linhas[1].startsWith('10/10/2026;21:00;22:00;A;'));
  assert.ok(linhas[2].startsWith('12/10/2026;'));
});
