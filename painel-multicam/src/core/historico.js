// Fase 4: a partir das lives guardadas, mostra qual site e qual horário rendem mais.
//
// Cada live guardada tem início, fim, totais por site e quanto entrou em cada hora cheia do relógio
// (porHora: { [início da hora em ms]: { [siteId]: reais } }). O R$/hora de um site considera
// a live inteira, ou seja, supõe que o site ficou no ar a live toda.

const HORA = 3_600_000;
export const DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

export function inicioDaHora(ts) {
  const d = new Date(ts);
  d.setMinutes(0, 0, 0);
  return d.getTime();
}

// Quantos minutos da live caem em cada hora cheia do relógio.
export function coberturaPorHora(inicio, fim) {
  const horas = [];
  for (let h = inicioDaHora(inicio); h < fim; h = inicioDaHora(h + HORA + 60_000)) {
    const de = Math.max(h, inicio);
    const ate = Math.min(inicioDaHora(h + HORA + 60_000), fim);
    if (ate > de) horas.push({ hora: h, minutos: (ate - de) / 60_000 });
  }
  return horas;
}

export const nomeHorario = (dia, hora) => `${DIAS[dia]} ${String(hora).padStart(2, '0')}h`;

export function analisar(sessoes, { minimoHoras = 0.5 } = {}) {
  const sites = new Map();
  const grade = Array.from({ length: 7 }, () => Array.from({ length: 24 }, () => ({ reais: 0, horas: 0, sites: {} })));
  let horasTotais = 0;
  let reaisTotais = 0;

  for (const s of sessoes) {
    const horasSessao = Math.max(0, s.fim - s.inicio) / HORA;
    horasTotais += horasSessao;
    for (const site of s.sites ?? []) {
      const acc = sites.get(site.id) ?? { id: site.id, nome: site.nome, reais: 0, gorjetas: 0, privados: 0, horas: 0 };
      acc.nome = site.nome;
      acc.reais += site.reais ?? 0;
      acc.gorjetas += site.gorjetas ?? 0;
      acc.privados += site.privados ?? 0;
      acc.horas += horasSessao;
      sites.set(site.id, acc);
      reaisTotais += site.reais ?? 0;
    }
    for (const { hora, minutos } of coberturaPorHora(s.inicio, s.fim)) {
      const d = new Date(hora);
      const celula = grade[d.getDay()][d.getHours()];
      celula.horas += minutos / 60;
      for (const [id, reais] of Object.entries(s.porHora?.[hora] ?? {})) {
        celula.reais += reais;
        celula.sites[id] = (celula.sites[id] ?? 0) + reais;
      }
    }
  }

  // Horário com pouca live (menos de minimoHoras) não entra nos "melhores": seria sorte, não padrão.
  const celulas = [];
  grade.forEach((linha, dia) => linha.forEach((c, hora) => {
    c.reaisPorHora = c.horas > 0 ? c.reais / c.horas : null;
    if (c.horas >= minimoHoras) celulas.push({ dia, hora, ...c });
  }));
  const melhores = [...celulas].sort((a, b) => b.reaisPorHora - a.reaisPorHora).slice(0, 5);
  // Topo da escala de cores: só horários confiáveis, senão alguns minutos de sorte apagam o resto do mapa.
  const confiaveis = celulas.length ? celulas : grade.flat().filter((c) => c.horas > 0);
  const maiorReaisPorHora = Math.max(0, ...confiaveis.map((c) => c.reaisPorHora));

  const listaSites = [...sites.values()].map((s) => {
    let melhor = null;
    for (const c of celulas) {
      const rph = (c.sites[s.id] ?? 0) / c.horas;
      if (rph > 0 && (!melhor || rph > melhor.reaisPorHora)) melhor = { dia: c.dia, hora: c.hora, reaisPorHora: rph };
    }
    return { ...s, reaisPorHora: s.horas > 0 ? s.reais / s.horas : 0, melhor };
  }).sort((a, b) => b.reaisPorHora - a.reaisPorHora);

  return { sessoes: sessoes.length, horasTotais, reaisTotais, sites: listaSites, grade, melhores, maiorReaisPorHora, minimoHoras };
}
