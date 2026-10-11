// Transforma uma mensagem lida da página de um site em evento do painel: mensagem, gorjeta ou privado.

// Frases comuns de aviso de gorjeta, em inglês e português: "joao tipped 50 tokens", "maria deu 1.000 créditos".
export const PADRAO_GORJETA =
  /(?:^|\s)(?:tipped|tips?|deu|enviou|mandou|presenteou)\s*:?\s*(?<tokens>\d{1,3}(?:[.,]\d{3})+|\d+)\s*(?:tokens?|tks?|cr[ée]ditos?|moedas?|coins?)(?![\p{L}\d])/iu;

// Só frases de aviso do site. "vai ter show privado?" digitado no chat não pode contar como privado.
export const PADRAO_PRIVADO = /privado (?:iniciado|come[çc]ou)|show privado (?:iniciado|come[çc]ou)|started a private|private show (?:has )?started/i;

const NUMERO = /\d{1,3}(?:[.,]\d{3})+|\d+/;

export function paraNumero(texto) {
  return parseInt(String(texto).replace(/[.,]/g, ''), 10);
}

function compilar(regex) {
  if (!regex) return null;
  if (regex instanceof RegExp) return regex;
  try { return new RegExp(regex, 'iu'); } catch { return null; }
}

const limpar = (s) => (s ?? '').replace(/\s+/g, ' ').trim();

// bruto: { usuario, texto, textoCompleto, marcadoComoGorjeta } vindo do leitor da página.
// texto é só o que a pessoa escreveu (quando o seletor "texto" existe); textoCompleto é a linha inteira.
// Na gorjeta, o texto devolvido é só o recado da pessoa (pode ficar vazio), não a linha de aviso.
export function interpretar(bruto, site) {
  const leitura = site.leitura ?? {};
  const textoCompleto = limpar(bruto.textoCompleto || bruto.texto);
  const escrito = limpar(bruto.texto);
  const usuario = limpar(bruto.usuario) || null;
  const texto = escrito || textoCompleto;
  if (!textoCompleto) return null;

  const regexGorjeta = compilar(leitura.regexGorjeta) ?? PADRAO_GORJETA;
  const m = textoCompleto.match(regexGorjeta);

  if (bruto.marcadoComoGorjeta) {
    const tokens = paraNumero(m?.groups?.tokens ?? textoCompleto.match(NUMERO)?.[0] ?? 0);
    if (tokens > 0) return { tipo: 'gorjeta', usuario: usuario ?? m?.groups?.usuario ?? null, tokens, texto: escrito };
  } else if (m && !leitura.seletores?.gorjeta) {
    // Sem seletor de gorjeta, a gorjeta é reconhecida pela frase. Mas se a frase está dentro do que
    // a pessoa digitou ("tipped 1000 tokens" escrito no chat), é brincadeira e não conta.
    const digitadoPeloUsuario = escrito && escrito !== textoCompleto && regexGorjeta.test(escrito);
    const tokens = paraNumero(m.groups?.tokens ?? m[1] ?? 0);
    if (!digitadoPeloUsuario && tokens > 0) {
      return { tipo: 'gorjeta', usuario: usuario ?? m.groups?.usuario ?? null, tokens, texto: escrito };
    }
  }

  const regexPrivado = compilar(leitura.regexPrivado) ?? PADRAO_PRIVADO;
  if (!escrito && regexPrivado.test(textoCompleto)) return { tipo: 'privado', usuario, texto };

  return { tipo: 'mensagem', usuario, texto };
}
