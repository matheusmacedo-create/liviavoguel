<?php
/** POST → sai da área da modelo (o login de cliente, se houver, continua). */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

lv_exigir_metodo('POST');
if (!lv_origem_do_site()) {
    lv_falhar(403, 'Origem não permitida.');
}
lv_modelo_deslogar();
lv_json(['ok' => true]);
