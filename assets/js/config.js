/*
 * CONFIGURAÇÕES GERAIS DO SITE
 * Troque aqui nome, contato, pacotes de créditos e gorjetas.
 */
window.SITE = {
  nome: "Livia Voguel",
  slogan: "Ao vivo, só pra você",
  emailSuporte: "contato@seudominio.com.br",

  // true = login, saldo e pagamento simulados no navegador (para testar o visual).
  // Mude para false quando a pasta api/ (Unicopag) estiver no ar.
  // Fica true até o banco, o config.php e a compra de teste estarem prontos na Hostinger (README → Pagamentos).
  modoDemo: true,

  // Pacotes de créditos. O preço cobrado de verdade é conferido no servidor
  // (api/), então mudar aqui exige mudar lá também.
  pacotes: [
    { creditos: 50,   preco: 19.9,  bonus: 0 },
    { creditos: 120,  preco: 44.9,  bonus: 10, popular: true },
    { creditos: 300,  preco: 99.9,  bonus: 40 },
    { creditos: 700,  preco: 199.9, bonus: 120 }
  ],

  // Valores rápidos de gorjeta (em créditos) na sala
  gorjetas: [5, 10, 25, 50, 100],

  // Mínimo de minutos de saldo para começar um show privado
  privadoMinutosMinimos: 3
};
