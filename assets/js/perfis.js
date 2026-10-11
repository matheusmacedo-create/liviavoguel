/*
 * PERFIL DAS MODELOS
 * O que a modelo salva em "Meu perfil" no painel (nome artístico, cidade, sobre mim,
 * interesses, idiomas e as fotos enviadas: capa e galeria) vale sobre o que está em modelos.js. Vem de api/perfis.php;
 * no modo demonstração, do que foi salvo no painel neste mesmo navegador.
 * Quando chega, as páginas recebem o evento "lv:perfil" e se redesenham.
 * Carregar depois de modelos.js e antes do script da página.
 */
(function () {
  const S = window.SITE;
  const CAMPOS = ["nome", "cidade", "bio", "tags", "idiomas", "foto", "galeria"];

  function aplicar(perfis) {
    let mudou = false;
    for (const m of window.MODELOS) {
      const p = perfis[m.slug];
      if (!p) continue;
      for (const c of CAMPOS) {
        if (p[c] === undefined || JSON.stringify(m[c]) === JSON.stringify(p[c])) continue;
        m[c] = p[c];
        mudou = true;
      }
    }
    if (mudou) document.dispatchEvent(new CustomEvent("lv:perfil"));
    return mudou;
  }

  function perfisDemo() {
    try { return JSON.parse(localStorage.getItem("lv_perfil_demo") || "{}") || {}; } catch { return {}; }
  }

  async function carregar() {
    if (S.modoDemo) return aplicar(perfisDemo());
    try {
      const resp = await fetch("api/perfis.php", { credentials: "same-origin", headers: { Accept: "application/json" } });
      if (resp.ok) return aplicar((await resp.json()).modelos || {});
    } catch {}
    return false;
  }

  // Modo demonstração: o painel salva aqui e a página aplica na hora.
  function salvarDemo(slug, perfil) {
    const todos = perfisDemo();
    todos[slug] = perfil;
    try { localStorage.setItem("lv_perfil_demo", JSON.stringify(todos)); } catch {}
    aplicar(todos);
  }

  window.LVPerfis = { carregar, aplicar, salvarDemo };
  document.addEventListener("DOMContentLoaded", carregar);
  window.addEventListener("storage", (e) => { if (e.key === "lv_perfil_demo") carregar(); });
})();
