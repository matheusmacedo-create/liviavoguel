/*
 * PAGAMENTOS (Unicopag) — ponte entre as telas e a pasta api/ (PHP).
 * Nada de preço nem de chave aqui: o navegador manda só o índice do pacote em SITE.pacotes,
 * e o servidor decide o valor. Todas as funções devolvem Promises com o JSON do servidor;
 * em erro, rejeitam com um Error que tem .status, .campo (campo do formulário com problema)
 * e .login (true quando é preciso entrar na conta).
 *
 * Uso na tela de checkout:
 *   const r = await Pagamento.criarCobranca({ pacote: 1, metodo: "pix", nome, cpf, telefone });
 *   img.src = Pagamento.qrSrc(r.pix);           // ou gere o QR a partir de r.pix.copia_cola
 *   Pagamento.acompanhar(r.id, (s) => { if (s.status === "pago") ... });
 * Cartão: metodo "cartao" e cartao: { numero, titular, mes, ano, cvv }.
 */
(function () {
  const BASE = "api/";
  const FINAIS = ["pago", "falhou", "expirado", "estornado"];

  async function chamar(caminho, opcoes) {
    const resp = await fetch(BASE + caminho, Object.assign({ credentials: "same-origin", headers: { Accept: "application/json" } }, opcoes));
    let dados = {};
    try { dados = await resp.json(); } catch (e) { /* resposta sem JSON */ }
    if (!resp.ok && !(resp.status === 402 && dados.status)) {
      const erro = new Error(dados.erro || "Não foi possível falar com o servidor. Tente de novo.");
      erro.status = resp.status;
      erro.campo = dados.campo || null;
      erro.login = !!dados.login;
      throw erro;
    }
    return dados;
  }

  const postar = (caminho, corpo) => chamar(caminho, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify(corpo || {})
  });

  // Saldo exibido no topo (#saldo, de app.js) passa a vir do servidor.
  function mostrarSaldo(saldo) {
    const el = document.getElementById("saldo");
    if (el && typeof saldo === "number") el.textContent = saldo;
    return saldo;
  }

  const Pagamento = {
    cadastrar: (email, senha, maior18) => postar("cadastro.php", { email, senha, maior18: maior18 === true }),
    entrar: (email, senha) => postar("entrar.php", { email, senha }),
    sair: () => postar("sair.php"),

    /** { saldo, email } ou null se não estiver logado. */
    async saldo() {
      try {
        const r = await chamar("saldo.php");
        mostrarSaldo(r.saldo);
        return r;
      } catch (e) {
        if (e.login) return null;
        throw e;
      }
    },

    /** dados: { pacote, metodo: "pix"|"cartao", nome, cpf, telefone, cartao? }. */
    criarCobranca: (dados) => postar("cobranca.php", dados),

    status: (id) => chamar("status.php?id=" + encodeURIComponent(id)),

    /**
     * Consulta o status a cada `intervaloMs` (padrão 4 s) até pago/falhou/expirado/estornado.
     * Chama aoMudar(resposta) a cada consulta. Devolve uma função que para o acompanhamento.
     */
    acompanhar(id, aoMudar, intervaloMs) {
      let parado = false;
      let timer = null;
      const rodar = async () => {
        if (parado) return;
        try {
          const r = await Pagamento.status(id);
          if (typeof r.saldo === "number") mostrarSaldo(r.saldo);
          aoMudar && aoMudar(r);
          if (FINAIS.includes(r.status)) return;
        } catch (e) {
          aoMudar && aoMudar({ status: "erro", erro: e.message });
          if (e.status === 401 || e.status === 404) return;
        }
        timer = setTimeout(rodar, intervaloMs || 4000);
      };
      rodar();
      return () => { parado = true; clearTimeout(timer); };
    },

    /** src pronto para <img> a partir do objeto pix da cobrança (ou null: gere o QR do copia_cola). */
    qrSrc(pix) {
      if (!pix) return null;
      if (pix.qrcode_url) return pix.qrcode_url;
      return pix.qrcode_base64 ? "data:image/png;base64," + pix.qrcode_base64 : null;
    }
  };

  window.Pagamento = Pagamento;
})();
