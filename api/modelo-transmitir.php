<?php
/** GET → { ok, configurada, rtmp_servidor, rtmp_chave } da modelo logada: o que ela cola no OBS. Só ela (e a equipe) vê a chave. */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/stream.php';

lv_exigir_metodo('GET');
$config = lv_stream_config((string) lv_exigir_modelo()['slug']);
lv_json(['ok' => true, 'configurada' => $config !== null, 'rtmp_servidor' => $config['rtmp_servidor'] ?? null, 'rtmp_chave' => $config['rtmp_chave'] ?? null]);
