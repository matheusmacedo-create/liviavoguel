import { test } from 'node:test';
import assert from 'node:assert/strict';
import { paraCSV, resumoParaCSV, numeroBR } from '../src/core/csv.js';

test('escapa ponto e vírgula, aspas e quebra de linha', () => {
  const csv = paraCSV([{ titulo: 'A', valor: (l) => l }], ['x;y', 'diz "oi"', 'linha\nnova', 'normal']);
  assert.equal(csv, '﻿A\r\n"x;y"\r\n"diz ""oi"""\r\n"linha\nnova"\r\nnormal\r\n');
});

test('usa vírgula decimal', () => {
  assert.equal(numeroBR(1234.5), '1234,50');
});

test('resumo sai com as colunas da planilha da fase 1', () => {
  const inicio = new Date(2026, 9, 12, 21, 0).getTime();
  const csv = resumoParaCSV({
    inicio, fim: inicio + 150 * 60_000,
    sites: [{ nome: 'Stripchat', pico: 340, tokens: 1200, reais: 324, gorjetas: 30, privados: 2, mensagens: 800 }],
  });
  const [cabecalho, linha] = csv.replace('﻿', '').trim().split('\r\n');
  assert.equal(cabecalho, 'Data;Início;Fim;Site;Pico de pessoas;Ganho (tokens/créditos);Ganho em R$;Gorjetas;Privados;Mensagens;Observação');
  assert.equal(linha, '12/10/2026;21:00;23:30;Stripchat;340;1200;324,00;30;2;800;');
});
