<?php
/** POST → sai do painel da equipe. */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/saques.php';
require_once __DIR__ . '/lib/equipe.php';

lv_exigir_metodo('POST');
if (!lv_origem_do_site()) {
    lv_falhar(403, 'Origem não permitida.');
}
lv_equipe_deslogar();
lv_json(['ok' => true]);
