// Converte um evento da API de eventos do Chaturbate no formato do painel.
// Mensagens privadas (DM) ficam de fora de propósito: o painel só mostra o chat público.

export function converterEventoChaturbate(ev) {
  const o = ev?.object ?? {};
  const usuario = o.user?.username ?? null;
  switch (ev?.method) {
    case 'tip': {
      const tokens = Number(o.tip?.tokens) || 0;
      if (tokens <= 0) return null;
      return { tipo: 'gorjeta', usuario: o.tip?.isAnon ? null : usuario, tokens, texto: o.tip?.message ?? '' };
    }
    case 'chatMessage':
      return { tipo: 'mensagem', usuario, texto: o.message?.message ?? '' };
    default:
      return null;
  }
}
