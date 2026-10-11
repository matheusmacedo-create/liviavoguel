<?php
/** GET → { ok, modelos: { <slug>: { online, desde } } }. Público: a página inicial e a sala usam para o selo "Ao vivo". */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

lv_exigir_metodo('GET');
lv_json(['ok' => true, 'modelos' => lv_ao_vivo_todas()]);
