<?php
/** GET → { saldo, email } do usuário logado (401 { login: true } sem sessão). O saldo é a soma do extrato. */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

lv_exigir_metodo('GET');
$usuarioId = lv_exigir_login();
$usuario = lv_usuario($usuarioId) ?? lv_falhar(401, 'Entre na sua conta para continuar.', ['login' => true]);
lv_json(['ok' => true, 'saldo' => lv_saldo($usuarioId), 'email' => $usuario['email']]);
