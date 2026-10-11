/*
 * CADASTRO DE MODELOS
 * --------------------------------------------------------------
 * Para adicionar uma nova modelo, copie um bloco { ... } inteiro,
 * cole abaixo do último (separado por vírgula) e troque os dados.
 * O site inteiro (home, sala, busca) se monta a partir desta lista.
 *
 * Campos:
 *  slug        → identificador na URL (sem espaço/acento): sala.html?m=slug
 *  nome        → nome artístico
 *  idade       → idade declarada (precisa ser 18+ e verificada com documento)
 *  cidade      → cidade/estado exibidos no perfil
 *  online      → true quando está ao vivo (depois isso vem do servidor de streaming)
 *  foto        → imagem de capa (assets/img/...)
 *  galeria     → lista de fotos de prévia
 *  bio         → texto "Sobre mim"
 *  tags        → categorias/interesses (aparecem como etiquetas)
 *  idiomas     → idiomas falados
 *  agenda      → horários fixos de transmissão
 *  precoPrivado→ créditos por minuto no show privado
 *  streamUrl   → URL do player (iframe) do serviço de streaming. Vazio = mostra "offline"
 *  redes       → links externos opcionais
 */
window.MODELOS = [
  {
    slug: "livia-voguel",
    nome: "Livia Voguel",
    idade: 24,
    cidade: "Rio de Janeiro, RJ",
    online: true,
    destaque: true,
    foto: "assets/img/placeholder-capa.svg",
    galeria: [
      "assets/img/placeholder-galeria.svg",
      "assets/img/placeholder-galeria.svg",
      "assets/img/placeholder-galeria.svg",
      "assets/img/placeholder-galeria.svg"
    ],
    bio: "Oi, eu sou a Livia! Carioca, apaixonada por música, conversa boa e gente educada. Aqui na minha sala a gente conversa, ri e se diverte. Chega mais e diz oi no chat 💋",
    tags: ["Morena", "Conversa", "Dança", "Cosplay"],
    idiomas: ["Português", "Espanhol"],
    agenda: [
      { dia: "Segunda a Sexta", hora: "21h às 00h" },
      { dia: "Sábado", hora: "22h às 02h" }
    ],
    precoPrivado: 20,
    streamUrl: "",
    redes: { instagram: "", twitter: "" }
  }

  // , { slug: "nova-modelo", nome: "Nova Modelo", ... }
];
