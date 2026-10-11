<?php
/**
 * GET ?modelo=<slug> → { ok, online, hls_url } para quem está logado. O link só vem com a modelo ao vivo e a
 * transmissão configurada (senão hls_url = null). Sem login: 401 (a sala pede para entrar antes de assistir).
 * Observação: o link HLS em si não é secreto; proteção de verdade (link assinado e com validade) depende do serviço.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/stream.php';

lv_exigir_metodo('GET');
lv_exigir_login();
$slug = is_string($_GET['modelo'] ?? null) ? $_GET['modelo'] : '';
if (!isset(LV_MODELOS[$slug])) {
    lv_falhar(422, 'Modelo não encontrada.');
}
$online = (bool) (lv_ao_vivo_todas()[$slug]['online'] ?? false);
$config = $online ? lv_stream_config($slug) : null;
lv_json(['ok' => true, 'online' => $online, 'hls_url' => $config['hls_url'] ?? null]);
