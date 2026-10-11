/*
 * STATUS "AO VIVO"
 * Fora do modo demonstração, quem diz se a modelo está ao vivo é o servidor (api/ao-vivo.php),
 * ligado pelo botão do painel da modelo. O campo `online` de modelos.js deixa de valer:
 * todas começam offline até a resposta chegar, para o site nunca prometer uma sala vazia.
 * No modo demonstração, vale o botão do painel aberto neste mesmo navegador.
 * Quando o status muda, as páginas recebem o evento "lv:ao-vivo" e se redesenham.
 * Carregar depois de modelos.js e antes do script da página.
 */
(function () {
  const S = window.SITE;
  const DEMO = !!S.modoDemo;
  if (!DEMO) window.MODELOS.forEach((m) => (m.online = false));

  function statusDemo() {
    let valor = null;
    try { valor = localStorage.getItem("lv_painel_online"); } catch {}
    if (valor === null) return null; // painel nunca usado neste navegador: fica o que está em modelos.js
    return { [window.MODELOS[0].slug]: { online: valor === "1" } };
  }

  async function carregar() {
    let status = null;
    if (DEMO) {
      status = statusDemo();
    } else {
      try {
        const resp = await fetch("api/ao-vivo.php", { credentials: "same-origin", headers: { Accept: "application/json" } });
        if (resp.ok) status = (await resp.json()).modelos;
      } catch {}
    }
    if (!status) return;
    let mudou = false;
    for (const m of window.MODELOS) {
      const online = !!(status[m.slug] && status[m.slug].online);
      if (m.online !== online) { m.online = online; mudou = true; }
    }
    if (mudou) document.dispatchEvent(new CustomEvent("lv:ao-vivo"));
  }

  window.LVAoVivo = { carregar };
  // Espera a página montar antes da primeira consulta, para o evento achar quem escuta.
  document.addEventListener("DOMContentLoaded", carregar);
  setInterval(carregar, 60000);
  // Painel aberto em outra aba deste navegador (demonstração): atualiza na hora.
  window.addEventListener("storage", (e) => { if (e.key === "lv_painel_online") carregar(); });
})();
