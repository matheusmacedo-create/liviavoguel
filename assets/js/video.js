/*
 * PLAYER AO VIVO (HLS)
 * Toca o link .m3u8 da transmissão: nativo no Safari/iPhone; nos outros navegadores pela
 * biblioteca hls.js (assets/js/vendor/hls.light.min.js, licença Apache-2.0), carregada só
 * quando precisa. Se a transmissão ainda não começou ou cair, tenta de novo sozinho.
 *   const p = LVVideo.tocar(elementoVideo, url, { aoMudar: (estado) => … });  // "carregando" | "tocando" | "esperando"
 *   p.parar();
 */
(function () {
  let carregando = null;
  function carregarHls() {
    if (window.Hls) return Promise.resolve(window.Hls);
    carregando ??= new Promise((ok, erro) => {
      const s = document.createElement("script");
      s.src = "assets/js/vendor/hls.light.min.js";
      s.onload = () => ok(window.Hls);
      s.onerror = () => { carregando = null; erro(new Error("player")); };
      document.head.appendChild(s);
    });
    return carregando;
  }

  function tocar(video, url, { aoMudar = () => {} } = {}) {
    let parado = false;
    let hls = null;
    let espera = null;
    const tentarDeNovo = () => {
      if (parado) return;
      aoMudar("esperando");
      clearTimeout(espera);
      espera = setTimeout(iniciar, 8000);
    };
    video.addEventListener("playing", () => aoMudar("tocando"));

    async function iniciar() {
      if (parado) return;
      aoMudar("carregando");
      if (hls) { hls.destroy(); hls = null; }
      // Safari e iPhone tocam HLS sozinhos.
      if (video.canPlayType("application/vnd.apple.mpegurl") && !window.MediaSource) {
        video.src = url;
        video.onerror = tentarDeNovo;
        video.play().catch(() => {});
        return;
      }
      let Hls;
      try { Hls = await carregarHls(); } catch { return tentarDeNovo(); }
      if (parado) return;
      if (!Hls.isSupported()) {
        video.src = url; // última tentativa: o navegador que se vire
        video.onerror = tentarDeNovo;
        return;
      }
      hls = new Hls({ lowLatencyMode: true, liveSyncDurationCount: 3, manifestLoadingMaxRetry: 2 });
      hls.on(Hls.Events.MANIFEST_PARSED, () => video.play().catch(() => {}));
      hls.on(Hls.Events.ERROR, (_e, dados) => {
        if (!dados.fatal) return;
        if (dados.type === Hls.ErrorTypes.MEDIA_ERROR) { hls.recoverMediaError(); return; }
        tentarDeNovo(); // rede: transmissão ainda não começou ou caiu
      });
      hls.loadSource(url);
      hls.attachMedia(video);
    }
    iniciar();

    return {
      parar() {
        parado = true;
        clearTimeout(espera);
        if (hls) hls.destroy();
        video.removeAttribute("src");
        video.load();
      }
    };
  }

  window.LVVideo = { tocar };
})();
