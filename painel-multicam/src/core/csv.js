// Gera o CSV da sessão com as mesmas colunas da planilha da fase 1.
// Separador ";" e vírgula decimal, que é o que o Excel em português abre direto.

const escapar = (valor) => {
  const s = valor == null ? '' : String(valor);
  return /[;"\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};

export const numeroBR = (n, casas = 2) => n.toFixed(casas).replace('.', ',');

const data = (ts) => new Date(ts).toLocaleDateString('pt-BR');
const hora = (ts) => new Date(ts).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

export function paraCSV(colunas, linhas) {
  const cabecalho = colunas.map((c) => escapar(c.titulo)).join(';');
  const corpo = linhas.map((l) => colunas.map((c) => escapar(c.valor(l))).join(';'));
  return '﻿' + [cabecalho, ...corpo].join('\r\n') + '\r\n';
}

export function resumoParaCSV(resumo) {
  const colunas = [
    { titulo: 'Data', valor: () => data(resumo.inicio) },
    { titulo: 'Início', valor: () => hora(resumo.inicio) },
    { titulo: 'Fim', valor: () => hora(resumo.fim) },
    { titulo: 'Site', valor: (s) => s.nome },
    { titulo: 'Pico de pessoas', valor: (s) => s.pico || '' },
    { titulo: 'Ganho (tokens/créditos)', valor: (s) => s.tokens },
    { titulo: 'Ganho em R$', valor: (s) => numeroBR(s.reais) },
    { titulo: 'Gorjetas', valor: (s) => s.gorjetas },
    { titulo: 'Privados', valor: (s) => s.privados },
    { titulo: 'Mensagens', valor: (s) => s.mensagens },
    { titulo: 'Observação', valor: () => '' },
  ];
  return paraCSV(colunas, resumo.sites);
}
