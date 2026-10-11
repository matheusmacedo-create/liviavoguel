// Modo demonstração: inventa mensagens e gorjetas para testar o painel sem estar ao vivo.
// De tempos em tempos um site "esquenta" e passa a receber mais gorjetas.

const NOMES = ['joao_sp', 'rafa77', 'gringo_tx', 'lucas.rj', 'mike_uk', 'paulinho', 'dan_cam', 'zeca10', 'tom_br', 'leo_bh', 'carlos_pt', 'andre.m'];
const FRASES = [
  'oi linda', 'que sorriso', 'de onde você é?', 'vai ter privado hoje?', 'linda demais', 'manda um beijo',
  'qual sua música favorita?', 'boa noite!', 'tá muito bonita hoje', 'adoro essa roupa', 'hello beautiful', 'you look amazing',
];
const GORJETAS = [5, 10, 10, 20, 25, 25, 50, 50, 100, 200];

export function criarSimulador(sites, emitir, { intervaloMs = 600, aleatorio = Math.random } = {}) {
  const sortear = (lista) => lista[Math.floor(aleatorio() * lista.length)];
  const pessoas = Object.fromEntries(sites.map((s) => [s.id, 30 + Math.floor(aleatorio() * 300)]));
  let quente = sortear(sites);
  let passo = 0;

  for (const s of sites) emitir({ site: s.id, tipo: 'espectadores', quantidade: pessoas[s.id] });

  const timer = setInterval(() => {
    passo++;
    if (passo % 80 === 0) quente = sortear(sites);
    const site = aleatorio() < 0.45 ? quente : sortear(sites);
    const usuario = sortear(NOMES);
    const sorte = aleatorio();
    const chanceGorjeta = site === quente ? 0.28 : 0.07;

    if (sorte < chanceGorjeta) {
      emitir({ site: site.id, tipo: 'gorjeta', usuario, tokens: sortear(GORJETAS), texto: '' });
    } else if (sorte > 0.995) {
      emitir({ site: site.id, tipo: 'privado', usuario, texto: `${usuario} pediu um show privado` });
    } else {
      emitir({ site: site.id, tipo: 'mensagem', usuario, texto: sortear(FRASES) });
    }

    if (passo % 10 === 0) {
      for (const s of sites) {
        const tendencia = s === quente ? 12 : -4;
        pessoas[s.id] = Math.max(5, pessoas[s.id] + tendencia + Math.round((aleatorio() - 0.5) * 20));
        emitir({ site: s.id, tipo: 'espectadores', quantidade: pessoas[s.id] });
      }
    }
  }, intervaloMs);

  return { parar: () => clearInterval(timer) };
}
