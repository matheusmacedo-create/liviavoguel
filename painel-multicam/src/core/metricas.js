// O "daytrade": quanto cada site rende agora (R$/min) e na sessão inteira.

const MINUTO = 60_000;

export function valorEmReais(site, tokens, cotacao = {}) {
  const moeda = site.moedaToken ?? 'BRL';
  const taxa = moeda === 'BRL' ? 1 : (cotacao[moeda] ?? 0);
  return tokens * (site.valorToken ?? 0) * taxa;
}

export function criarMetricas(config, agora = () => Date.now()) {
  const alertas = { janelaMin: 2, minGorjetas: 3, minValorBRL: 20, intervaloMin: 3, ...config.alertas };
  const janelaRankingMin = config.janelaRankingMin ?? 5;
  const guardarMs = Math.max(janelaRankingMin, alertas.janelaMin) * MINUTO;
  const inicio = agora();
  let ultimoEvento = inicio;

  const estado = new Map();
  for (const site of config.sites) {
    estado.set(site.id, {
      site, recentes: [], tokens: 0, reais: 0, gorjetas: 0, mensagens: 0, privados: 0,
      espectadores: null, pico: 0, ultimoAlerta: -Infinity,
    });
  }
  // Quanto cada pessoa já deu nesta sessão, por site.
  const fas = new Map();
  const chaveFa = (site, usuario) => `${site}\n${usuario.toLowerCase()}`;

  function descartarAntigas(e, t) {
    while (e.recentes.length && t - e.recentes[0].ts > guardarMs) e.recentes.shift();
  }

  function somaJanela(e, t, minutos) {
    let quantidade = 0, reais = 0;
    for (const g of e.recentes) {
      if (t - g.ts <= minutos * MINUTO) { quantidade++; reais += g.reais; }
    }
    return { quantidade, reais };
  }

  function registrar(ev) {
    const e = estado.get(ev.site);
    if (!e) return null;
    const t = agora();
    ultimoEvento = t;
    const saida = { ...ev, ts: t };

    if (ev.tipo === 'mensagem') e.mensagens++;
    else if (ev.tipo === 'privado') e.privados++;
    else if (ev.tipo === 'espectadores') {
      e.espectadores = ev.quantidade;
      e.pico = Math.max(e.pico, ev.quantidade);
    } else if (ev.tipo === 'gorjeta') {
      const reais = valorEmReais(e.site, ev.tokens, config.cotacao);
      e.tokens += ev.tokens;
      e.reais += reais;
      e.gorjetas++;
      e.recentes.push({ ts: t, reais });
      descartarAntigas(e, t);
      saida.reais = reais;

      if (ev.usuario) {
        const k = chaveFa(ev.site, ev.usuario);
        const fa = fas.get(k) ?? { site: ev.site, usuario: ev.usuario, tokens: 0, reais: 0 };
        fa.tokens += ev.tokens;
        fa.reais += reais;
        fas.set(k, fa);
      }

      const janela = somaJanela(e, t, alertas.janelaMin);
      const quente = janela.quantidade >= alertas.minGorjetas || janela.reais >= alertas.minValorBRL;
      if (quente && t - e.ultimoAlerta >= alertas.intervaloMin * MINUTO) {
        e.ultimoAlerta = t;
        saida.alerta = { site: ev.site, nome: e.site.nome, minutos: alertas.janelaMin, ...janela };
      }
    }

    if (ev.usuario) {
      const fa = fas.get(chaveFa(ev.site, ev.usuario));
      if (fa) saida.fa = { ...fa };
    }
    return saida;
  }

  function fa(site, usuario) {
    if (!usuario) return null;
    const f = fas.get(chaveFa(site, usuario));
    return f ? { ...f } : null;
  }

  function ranking() {
    const t = agora();
    const linhas = [];
    for (const e of estado.values()) {
      descartarAntigas(e, t);
      const janela = somaJanela(e, t, janelaRankingMin);
      linhas.push({
        id: e.site.id, nome: e.site.nome, cor: e.site.cor,
        reaisPorMinuto: janela.reais / janelaRankingMin,
        gorjetasJanela: janela.quantidade,
        reais: e.reais, tokens: e.tokens, gorjetas: e.gorjetas,
        mensagens: e.mensagens, privados: e.privados,
        espectadores: e.espectadores, pico: e.pico,
      });
    }
    return linhas.sort((a, b) => b.reaisPorMinuto - a.reaisPorMinuto || b.reais - a.reais);
  }

  function resumo() {
    return {
      inicio, fim: ultimoEvento,
      sites: [...estado.values()].map((e) => ({
        id: e.site.id, nome: e.site.nome, pico: e.pico, tokens: e.tokens, reais: e.reais,
        gorjetas: e.gorjetas, privados: e.privados, mensagens: e.mensagens,
      })),
    };
  }

  const totalReais = () => [...estado.values()].reduce((s, e) => s + e.reais, 0);

  return { registrar, fa, ranking, resumo, totalReais, inicio };
}
