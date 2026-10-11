<?php
/**
 * Aviso do servidor de vídeo: a modelo começou ou parou de transmitir no OBS. A sala entra e sai do ao vivo sozinha.
 * Não é chamado pelo navegador: quem chama é o servidor de vídeo (servidor próprio ou serviço), sempre por POST.
 * A própria chave de transmissão identifica a modelo, então só quem tem a chave consegue avisar.
 *
 * Formatos aceitos (formulário ou JSON):
 *   nginx-rtmp: on_publish / on_publish_done / on_update apontando para esta URL → call=publish|publish_done|update_publish, name=<chave>
 *   genérico (MediaMTX runOnReady/runOnNotReady, webhook de serviço): evento=inicio|fim|aviso, chave=<chave>  ("live/<chave>" também vale)
 *
 * 200 = aceito. 403 = chave desconhecida ou conta bloqueada (o nginx-rtmp então recusa a transmissão). 429 = chaves erradas demais.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/stream.php';

lv_exigir_metodo('POST');
$tipo = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
$bruto = lv_corpo_bruto();
if (str_starts_with($tipo, 'application/json')) {
    $b = json_decode($bruto, true);
    $b = is_array($b) ? $b : [];
} else {
    parse_str($bruto, $b);
}
$texto = static fn(string $k): string => is_string($b[$k] ?? null) ? $b[$k] : '';

$evento = match ($texto('call') !== '' ? $texto('call') : $texto('evento')) {
    'publish', 'inicio' => 'inicio',
    'update_publish', 'update', 'aviso' => 'aviso',
    'publish_done', 'fim' => 'fim',
    default => null,
};
if ($evento === null) {
    lv_falhar(422, 'Evento desconhecido. Use evento=inicio|fim|aviso ou o call do nginx-rtmp.');
}

$ip = lv_ip();
if (lv_contar_eventos('stream_chave_errada', $ip, 900) >= LV_STREAM_ERROS) {
    lv_falhar(429, 'Chaves erradas demais. Tente de novo mais tarde.');
}
$slug = lv_stream_dona_da_chave($texto('name') !== '' ? $texto('name') : $texto('chave'));
if ($slug === null) {
    lv_registrar('stream_chave_errada', $ip);
    lv_falhar(403, 'Chave de transmissão não reconhecida.');
}

if ($evento === 'fim') {
    if (lv_stream_parou($slug)) {
        lv_registrar('ao_vivo', 's:' . $slug, 'obs parou');
    }
} elseif (lv_stream_publicando($slug)) {
    lv_registrar('ao_vivo', 's:' . $slug, 'obs começou');
}
lv_json(['ok' => true, 'online' => lv_ao_vivo_todas()[$slug]['online'] ?? false]);
