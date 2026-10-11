/*
 * CONFIGURAÇÕES GERAIS DO SITE
 * Troque aqui nome, contato e os links de pagamento.
 */
window.SITE = {
  nome: "Livia Voguel",
  slogan: "Ao vivo, só pra você",
  emailSuporte: "contato@seudominio.com.br",

  // Pacotes de créditos. "checkoutUrl" = link de pagamento do gateway
  // (ex.: link de pagamento de um processador que aceite conteúdo adulto).
  // Enquanto estiver vazio, o botão avisa que o pagamento ainda não está ativo.
  pacotes: [
    { creditos: 50,   preco: 19.9,  bonus: 0,   checkoutUrl: "" },
    { creditos: 120,  preco: 44.9,  bonus: 10,  checkoutUrl: "", popular: true },
    { creditos: 300,  preco: 99.9,  bonus: 40,  checkoutUrl: "" },
    { creditos: 700,  preco: 199.9, bonus: 120, checkoutUrl: "" }
  ],

  // Valores rápidos de gorjeta (em créditos) na sala
  gorjetas: [5, 10, 25, 50, 100]
};
