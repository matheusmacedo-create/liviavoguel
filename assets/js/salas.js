/*
 * CONFIGURAÇÃO DAS SALAS
 * O que a modelo salva em "Configurar sala" no painel: preço do show privado, meta de
 * gorjetas (com o progresso contado no servidor), mensagem de boas-vindas e palavras
 * bloqueadas no chat. Vem de api/salas.php; no modo demonstração, do que foi salvo no
 * painel neste mesmo navegador. Quando muda, as páginas recebem o evento "lv:sala".
 * O preço cobrado de verdade é sempre o do servidor (api/gastar.php); aqui é só o que a tela mostra.
 * Carregar depois de modelos.js e antes do script da página.
 */
(function () {
  const S = window.SITE;

  function aplicar(salas) {
    let mudou = false;
    for (const m of window.MODELOS) {
      const s = salas[m.slug];
      if (!s) continue;
      const novo = {
        precoPrivado: s.preco_privado ?? m.precoPrivado,
        meta: s.meta === undefined ? m.meta : s.meta,
        boasVindas: s.boas_vindas ?? m.boasVindas ?? "",
        bloqueadas: s.bloqueadas ?? m.bloqueadas ?? []
      };
      for (const [c, v] of Object.entries(novo)) {
        if (JSON.stringify(m[c]) === JSON.stringify(v)) continue;
        m[c] = v;
        mudou = true;
      }
    }
    if (mudou) document.dispatchEvent(new CustomEvent("lv:sala"));
    return mudou;
  }

  function salasDemo() {
    try { return JSON.parse(localStorage.getItem("lv_sala_demo") || "{}") || {}; } catch { return {}; }
  }

  async function carregar() {
    if (S.modoDemo) return aplicar(salasDemo());
    try {
      const resp = await fetch("api/salas.php", { credentials: "same-origin", headers: { Accept: "application/json" } });
      if (resp.ok) return aplicar((await resp.json()).modelos || {});
    } catch {}
    return false;
  }

  function salvarDemo(slug, sala) {
    const todas = salasDemo();
    todas[slug] = sala;
    try { localStorage.setItem("lv_sala_demo", JSON.stringify(todas)); } catch {}
    aplicar(todas);
  }

  /** Palavra bloqueada no texto? Devolve a palavra, ou null. Não diferencia maiúsculas nem acentos. */
  function palavraBloqueada(m, texto) {
    const simples = (t) => t.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
    const alvo = simples(texto);
    return (m.bloqueadas || []).find((p) => p && alvo.includes(simples(p))) || null;
  }

  window.LVSalas = { carregar, aplicar, salvarDemo, palavraBloqueada };
  document.addEventListener("DOMContentLoaded", carregar);
  setInterval(carregar, 60000); // a meta anda com as gorjetas de todo mundo
  window.addEventListener("storage", (e) => { if (e.key === "lv_sala_demo") carregar(); });
})();
