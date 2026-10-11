<?php
/** GET → { ok, modelos: { <slug>: { online, desde } } }. Público: a página inicial e a sala usam para o selo "Ao vivo". */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

lv_exigir_metodo('GET');
// Só online e desde: se foi o botão ou o OBS que ligou é assunto da modelo.
lv_json(['ok' => true, 'modelos' => array_map(static fn(array $m): array => ['online' => $m['online'], 'desde' => $m['desde']], lv_ao_vivo_todas())]);
