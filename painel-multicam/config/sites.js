// Configuração do Painel Multicam.
// Valores marcados com CONFERIR são exemplos: troque pelos números reais antes de confiar no R$.

export default {
  // Quantos reais vale 1 dólar. Usado nos sites que pagam o token em dólar. CONFERIR.
  cotacao: { USD: 5.40 },

  // O ranking de R$/min olha as gorjetas destes últimos minutos.
  janelaRankingMin: 5,

  // Alerta de "site esquentou": dispara quando, dentro de janelaMin minutos, chegam
  // minGorjetas gorjetas OU minValorBRL reais. Depois espera intervaloMin para avisar de novo.
  alertas: { janelaMin: 2, minGorjetas: 3, minValorBRL: 20, intervaloMin: 3 },

  // Transmissão pelo painel (fase 3): o OBS manda para rtmp://127.0.0.1:<porta>/live e o painel repassa.
  // As chaves de transmissão de cada site ficam em config/segredos.local.json.
  // privadoAutomatico: true faz o painel pausar os outros sites sozinho quando lê um aviso de privado
  // no chat. Com false (padrão), ele só avisa e você clica.
  transmissao: { porta: 1935, privadoAutomatico: false },

  sites: [
    {
      id: 'cameraprive',
      nome: 'CameraPrivê',
      cor: '#22c55e',
      // Depois de entrar na conta, troque pelo endereço da página onde ela transmite.
      url: 'https://www.cameraprive.com/',
      ativo: true,
      valorToken: 1.00, moedaToken: 'BRL', // quanto 1 crédito vale para a modelo. CONFERIR.
      leitura: {
        modo: 'pagina',
        // Seletores CSS do chat na página do site. Veja "Configurar a leitura do chat" no README.
        seletores: { mensagem: '', usuario: '', texto: '', gorjeta: '', espectadores: '' },
      },
    },
    {
      id: 'stripchat',
      nome: 'Stripchat',
      cor: '#ef4444',
      url: 'https://stripchat.com/',
      ativo: true,
      valorToken: 0.05, moedaToken: 'USD', // CONFERIR.
      leitura: {
        modo: 'pagina',
        seletores: { mensagem: '', usuario: '', texto: '', gorjeta: '', espectadores: '' },
      },
    },
    {
      id: 'cam4',
      nome: 'Cam4',
      cor: '#3b82f6',
      url: 'https://www.cam4.com/',
      ativo: true,
      valorToken: 0.05, moedaToken: 'USD', // CONFERIR.
      leitura: {
        modo: 'pagina',
        seletores: { mensagem: '', usuario: '', texto: '', gorjeta: '', espectadores: '' },
      },
    },
    {
      id: 'chaturbate',
      nome: 'Chaturbate',
      cor: '#f59e0b',
      url: 'https://chaturbate.com/',
      ativo: true,
      valorToken: 0.05, moedaToken: 'USD', // CONFERIR.
      // O Chaturbate tem uma API oficial de eventos para quem transmite: mais confiável que ler a página.
      // O link secreto vai em config/segredos.local.json (veja segredos.exemplo.json).
      leitura: { modo: 'chaturbate-api', segredo: 'chaturbateEventsUrl' },
    },
  ],
};
