<?php
/** GET → { logado, equipe: { email } | null }. */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/saques.php';
require_once __DIR__ . '/lib/equipe.php';

lv_exigir_metodo('GET');
$conta = lv_equipe_logada();
lv_json(['logado' => $conta !== null, 'equipe' => $conta ? ['email' => (string) $conta['email']] : null]);
