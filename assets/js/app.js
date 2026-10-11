/* Funções compartilhadas por todas as páginas */
(function () {
  const S = window.SITE;

  const ler = (k, padrao) => { try { return localStorage.getItem(k) ?? padrao; } catch { return padrao; } };
  const gravar = (k, v) => { try { localStorage.setItem(k, v); } catch {} };

  const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const reais = (v) => v.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });

  // Saldo de créditos. Hoje é só visual (sempre começa em 0).
  // Quando o login + pagamento estiverem prontos, o saldo vem do servidor.
  const saldo = () => Number(ler("lv_saldo", 0));

  function toast(texto) {
    let el = document.querySelector(".toast");
    if (!el) { el = document.createElement("div"); el.className = "toast"; document.body.appendChild(el); }
    el.textContent = texto;
    el.classList.add("visivel");
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove("visivel"), 3200);
  }

  function topo(ativo) {
    const unica = window.MODELOS.length === 1;
    return `
    <header class="topo"><div class="container">
      <a class="marca" href="index.html"><img src="assets/img/logo.svg" alt="">${esc(S.nome)}</a>
      <nav class="menu">
        <a href="index.html" class="${ativo === "inicio" ? "ativo" : ""}">Início</a>
        ${unica
          ? `<a href="sala.html?m=${window.MODELOS[0].slug}" class="${ativo === "sala" ? "ativo" : ""}">Sala ao vivo</a>`
          : `<a href="index.html#modelos" class="${ativo === "modelos" ? "ativo" : ""}">Modelos</a>`}
        <a href="creditos.html" class="${ativo === "creditos" ? "ativo" : ""}">Créditos</a>
        <a href="index.html#como-funciona">Como funciona</a>
      </nav>
      <div class="topo-acoes">
        <a class="saldo" href="creditos.html" title="Seus créditos"><span class="moeda">●</span> <span id="saldo">${saldo()}</span> <span style="color:var(--rosa)">+</span></a>
        <button class="btn btn-pequeno btn-entrar" data-acao="entrar">Entrar</button>
        <a class="btn btn-primario btn-pequeno btn-comprar" href="creditos.html">Comprar créditos</a>
      </div>
    </div></header>`;
  }

  function rodape() {
    return `
    <footer class="rodape"><div class="container">
      <div>© ${new Date().getFullYear()} ${esc(S.nome)} · Conteúdo exclusivo para maiores de 18 anos.</div>
      <nav>
        <a href="termos.html">Termos de uso</a>
        <a href="termos.html#privacidade">Privacidade</a>
        <a href="mailto:${esc(S.emailSuporte)}">Suporte</a>
      </nav>
    </div></footer>`;
  }

  function avisoIdade() {
    if (ler("lv_maior18") === "sim") return;
    const fundo = document.createElement("div");
    fundo.className = "modal-fundo";
    fundo.innerHTML = `
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="t18">
        <div class="selo18">18+</div>
        <h2 id="t18">Conteúdo adulto</h2>
        <p>Este site contém material destinado exclusivamente a maiores de 18 anos.
           Ao entrar, você confirma ter 18 anos ou mais e concorda com os
           <a href="termos.html" style="text-decoration:underline">Termos de uso</a>.</p>
        <div class="acoes">
          <button class="btn btn-primario btn-bloco" data-r="sim">Tenho 18 anos ou mais — Entrar</button>
          <button class="btn btn-bloco" data-r="nao">Sair</button>
        </div>
      </div>`;
    document.body.appendChild(fundo);
    document.body.style.overflow = "hidden";
    fundo.addEventListener("click", (e) => {
      const r = e.target.dataset.r;
      if (r === "sim") { gravar("lv_maior18", "sim"); fundo.remove(); document.body.style.overflow = ""; }
      if (r === "nao") location.href = "https://www.google.com";
    });
  }

  function montar(ativo) {
    document.body.insertAdjacentHTML("afterbegin", topo(ativo));
    document.body.insertAdjacentHTML("beforeend", rodape());
    document.addEventListener("click", (e) => {
      if (e.target.closest("[data-acao=entrar]")) toast("Login e cadastro serão ativados na próxima etapa.");
    });
    if (!document.body.dataset.semAviso) avisoIdade();
  }

  function cardModelo(m) {
    return `
      <a class="card-modelo" href="sala.html?m=${encodeURIComponent(m.slug)}">
        <div class="thumb">
          <img src="${esc(m.foto)}" alt="${esc(m.nome)}" loading="lazy">
          <span class="${m.online ? "badge-online" : "badge-offline"}">${m.online ? "Ao vivo" : "Offline"}</span>
        </div>
        <div class="info"><strong>${esc(m.nome)}</strong><small>${m.idade} anos</small></div>
      </a>`;
  }

  window.LV = { esc, reais, saldo, toast, montar, cardModelo, ler, gravar };
})();
