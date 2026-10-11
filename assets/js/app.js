/* Funções compartilhadas por todas as páginas */
(function () {
  const S = window.SITE;

  const ler = (k, padrao) => { try { return localStorage.getItem(k) ?? padrao; } catch { return padrao; } };
  const gravar = (k, v) => { try { localStorage.setItem(k, v); } catch {} };
  const apagar = (k) => { try { localStorage.removeItem(k); } catch {} };
  const lerJSON = (k, padrao) => { try { return JSON.parse(ler(k, "")) ?? padrao; } catch { return padrao; } };

  const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
  const reais = (v) => v.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  const dataHora = (iso) => new Date(iso).toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" });

  /*
   * CONTA, SALDO E EXTRATO
   * Com SITE.modoDemo = true, tudo fica só no navegador (para testar o visual).
   * Com false, a fonte da verdade é o servidor (pasta api/): o app guarda uma cópia
   * em cache para as páginas lerem na hora, e a atualiza em api/eu.php a cada página.
   * Créditos só entram pelo servidor (compra confirmada pela Unicopag); gastos
   * (gorjeta, privado) são debitados em api/gastar.php.
   */
  const DEMO = !!S.modoDemo;
  const API = "api/";
  const CACHE = "lv_estado";

  async function api(caminho, corpo) {
    const opcoes = { credentials: "same-origin", headers: { Accept: "application/json" } };
    if (corpo !== undefined) {
      opcoes.method = "POST";
      opcoes.headers["Content-Type"] = "application/json";
      opcoes.body = JSON.stringify(corpo);
    }
    const resp = await fetch(API + caminho, opcoes);
    const dados = await resp.json().catch(() => ({}));
    if (!resp.ok) {
      const erro = new Error(dados.erro || "Não foi possível falar com o servidor. Tente de novo.");
      erro.status = resp.status;
      erro.campo = dados.campo || null;
      erro.dados = dados;
      throw erro;
    }
    return dados;
  }

  const estado = () => lerJSON(CACHE, null) || { logado: false, usuario: null, saldo: 0, extrato: [] };
  function guardarEstado(e) {
    gravar(CACHE, JSON.stringify({ logado: !!e.logado, usuario: e.usuario || null, saldo: Number(e.saldo) || 0, extrato: e.extrato || [] }));
    mostrarSaldo();
  }
  function mostrarSaldo() {
    const el = document.getElementById("saldo");
    if (el) el.textContent = saldo();
  }

  const usuario = () => DEMO ? lerJSON("lv_usuario", null) : estado().usuario;
  const extrato = () => DEMO ? lerJSON("lv_extrato", []) : estado().extrato;
  const saldo = () => DEMO ? extrato().reduce((s, l) => s + l.valor, 0) : estado().saldo;

  /**
   * Busca o estado no servidor. Se quem está logado mudou desde o cache (sessão
   * expirou, login em outra aba), recarrega a página uma vez para ela se montar certo.
   */
  async function sincronizar() {
    if (DEMO) return null;
    const antes = estado();
    try {
      const e = await api("eu.php");
      guardarEstado(e);
      const mudou = (antes.usuario && antes.usuario.id) !== (e.usuario && e.usuario.id);
      if (mudou) location.reload();
      document.dispatchEvent(new CustomEvent("lv:estado", { detail: e }));
      return e;
    } catch (err) {
      return null;
    }
  }

  // Demo: grava o usuário. Real: salva o apelido no servidor (o e-mail não muda por aqui).
  function entrar(u) {
    if (DEMO) { gravar("lv_usuario", JSON.stringify(u)); return Promise.resolve(u); }
    return api("conta.php", { apelido: u.apelido }).then((e) => { guardarEstado(e); return e.usuario; });
  }
  /** Login de verdade (ou simulado no demo). Rejeita com Error(.status, .campo). */
  function login(email, senha) {
    if (DEMO) {
      const salvo = usuario();
      return entrar({ apelido: salvo?.email === email ? salvo.apelido : email.split("@")[0], email });
    }
    return api("entrar.php", { email, senha }).then((e) => { guardarEstado(e); return e.usuario; });
  }
  /** Cadastro (o servidor exige 18+ e senha de 8+ caracteres). */
  function cadastrar({ apelido, email, senha }) {
    if (DEMO) return entrar({ apelido, email });
    return api("cadastro.php", { apelido, email, senha, maior18: true }).then((e) => { guardarEstado(e); return e.usuario; });
  }
  function trocarSenha(atual, nova) {
    if (DEMO) return Promise.resolve();
    return api("conta.php", { senha_atual: atual, senha_nova: nova }).then(guardarEstado);
  }
  function sair() {
    apagar("lv_usuario");
    apagar(CACHE);
    if (DEMO) return Promise.resolve();
    return fetch(API + "sair.php", { method: "POST", credentials: "same-origin", keepalive: true }).catch(() => {});
  }

  /**
   * Lança no extrato. Demo: grava no navegador. Real: só gastos (valor negativo), com
   * gasto = { tipo: "gorjeta"|"privado", modelo: slug, recado? }; o débito é feito no servidor
   * (e a gorjeta, com o recado, entra no chat da sala).
   * A tela já mostra o saldo novo e, se o servidor recusar, volta ao saldo real.
   * Devolve uma Promise que resolve com o saldo (ou rejeita com o erro).
   */
  function lancar(valor, descricao, gasto) {
    if (DEMO) {
      const lista = extrato();
      lista.unshift({ valor, descricao, data: new Date().toISOString() });
      gravar("lv_extrato", JSON.stringify(lista.slice(0, 200)));
      mostrarSaldo();
      return Promise.resolve(saldo());
    }
    if (valor >= 0 || !gasto) return Promise.reject(new Error("Créditos só entram pela compra confirmada."));
    const e = estado();
    e.saldo += valor;
    e.extrato.unshift({ valor, descricao, data: new Date().toISOString() });
    guardarEstado(e);
    return api("gastar.php", { tipo: gasto.tipo, modelo: gasto.modelo, creditos: -valor, ...(gasto.recado ? { recado: gasto.recado } : {}) })
      .then((r) => { sincronizar(); return r.saldo; })
      .catch((err) => {
        toast(err.status === 402 ? "Créditos insuficientes." : err.message);
        sincronizar();
        throw err;
      });
  }

  function toast(texto) {
    let el = document.querySelector(".toast");
    if (!el) { el = document.createElement("div"); el.className = "toast"; el.setAttribute("role", "status"); document.body.appendChild(el); }
    el.textContent = texto;
    el.classList.add("visivel");
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove("visivel"), 3200);
  }

  // Abre um modal. Fecha clicando fora, no [data-fechar] ou com Esc.
  function modal(html, { largo = false } = {}) {
    const fundo = document.createElement("div");
    fundo.className = "modal-fundo";
    fundo.innerHTML = `<div class="modal ${largo ? "modal-largo" : ""}" role="dialog" aria-modal="true">
      <button class="modal-x" data-fechar aria-label="Fechar">×</button>${html}</div>`;
    const fechar = () => { fundo.remove(); document.removeEventListener("keydown", esc_); document.body.style.overflow = ""; };
    const esc_ = (e) => { if (e.key === "Escape") fechar(); };
    fundo.addEventListener("click", (e) => { if (e.target === fundo || e.target.closest("[data-fechar]")) fechar(); });
    document.addEventListener("keydown", esc_);
    document.body.appendChild(fundo);
    document.body.style.overflow = "hidden";
    fundo.fechar = fechar;
    return fundo;
  }

  // Pede login antes de uma ação. Devolve true se já estiver logado.
  function exigirLogin(motivo) {
    if (usuario()) return true;
    modal(`
      <div class="modal-icone">🔐</div>
      <h2>Entre para continuar</h2>
      <p>${esc(motivo || "Você precisa estar logado para fazer isso.")}</p>
      <div class="acoes">
        <a class="btn btn-primario btn-bloco" href="entrar.html?volta=${encodeURIComponent(location.pathname.split("/").pop() + location.search)}">Entrar</a>
        <a class="btn btn-bloco" href="cadastro.html">Criar conta grátis</a>
      </div>`);
    return false;
  }

  function semCreditos(precisa) {
    modal(`
      <div class="modal-icone">●</div>
      <h2>Créditos insuficientes</h2>
      <p>Você precisa de <strong>${precisa}</strong> créditos e tem <strong>${saldo()}</strong>.
         Compre um pacote e volte para a sala.</p>
      <div class="acoes">
        <a class="btn btn-primario btn-bloco" href="creditos.html">Comprar créditos</a>
        <button class="btn btn-bloco" data-fechar>Agora não</button>
      </div>`);
  }

  function topo(ativo) {
    const unica = window.MODELOS.length === 1;
    const u = usuario();
    return `
    ${S.modoDemo ? `<div class="faixa-demo">Modo demonstração: login, saldo e pagamentos são simulados neste navegador.</div>` : ""}
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
        ${u
          ? `<div class="menu-conta">
               <button class="avatar-btn" aria-haspopup="true" aria-label="Minha conta">${esc(u.apelido.slice(0, 1).toUpperCase())}</button>
               <div class="menu-conta-lista">
                 <div class="menu-conta-nome">${esc(u.apelido)}<small>${esc(u.email)}</small></div>
                 <a href="conta.html">Minha conta</a>
                 <a href="conta.html#extrato">Extrato de créditos</a>
                 <a href="conta.html#seguindo">Seguindo</a>
                 <a href="creditos.html">Comprar créditos</a>
                 <button data-acao="sair">Sair</button>
               </div>
             </div>`
          : `<a class="btn btn-pequeno btn-entrar" href="entrar.html">Entrar</a>
             <a class="btn btn-primario btn-pequeno btn-comprar" href="cadastro.html">Criar conta</a>`}
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
        <a href="painel-modelo.html">Área da modelo</a>
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
      const conta = document.querySelector(".menu-conta");
      if (conta) conta.classList.toggle("aberto", !!e.target.closest(".avatar-btn") && !conta.classList.contains("aberto"));
      if (e.target.closest("[data-acao=sair]")) sair().finally(() => (location.href = "index.html"));
    });
    if (!document.body.dataset.semAviso) avisoIdade();
    sincronizar();
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

  window.LV = {
    esc, reais, dataHora, toast, modal, montar, cardModelo, ler, gravar,
    usuario, entrar, sair, saldo, extrato, lancar, exigirLogin, semCreditos,
    login, cadastrar, trocarSenha, sincronizar, api
  };
})();
