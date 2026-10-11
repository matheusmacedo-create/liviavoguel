<?php
/** POST → encerra a sessão. */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

lv_exigir_metodo('POST');
if (!lv_origem_do_site()) {
    lv_falhar(403, 'Origem não permitida.');
}
lv_sessao();
$_SESSION = [];
if (!defined('LV_TESTE')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
}
lv_json(['ok' => true]);
