<?php
/** POST { email, senha } → entra (sessão PHP) e devolve o estado da conta (o mesmo de eu.php). Depois de 10 erros em 15 min para o mesmo e-mail ou IP, espera. */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

$b = lv_ler_json();
$email = lv_email($b['email'] ?? '');
$senha = is_string($b['senha'] ?? null) ? $b['senha'] : '';
if (lv_contar_eventos('login_erro', 'e:' . $email, LV_JANELA_LOGIN) >= LV_ERROS_LOGIN
    || lv_contar_eventos('login_erro', 'ip:' . lv_ip(), LV_JANELA_LOGIN) >= 3 * LV_ERROS_LOGIN) {
    lv_falhar(429, 'Muitas tentativas. Aguarde 15 minutos e tente de novo.');
}
$stmt = lv_db()->prepare('SELECT id, senha_hash FROM lv_usuarios WHERE email = ?');
$stmt->execute([$email]);
$u = $email !== '' ? $stmt->fetch() : false;
if (!$u || !password_verify($senha, (string) $u['senha_hash'])) {
    lv_registrar('login_erro', 'e:' . $email);
    lv_registrar('login_erro', 'ip:' . lv_ip());
    lv_falhar(401, 'E-mail ou senha incorretos.');
}
$senhaHash = (string) $u['senha_hash'];
if (password_needs_rehash($senhaHash, PASSWORD_DEFAULT)) {
    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
    lv_db()->prepare('UPDATE lv_usuarios SET senha_hash = ? WHERE id = ?')->execute([$senhaHash, $u['id']]);
}
lv_logar((int) $u['id'], $senhaHash);
lv_json(lv_estado((int) $u['id']) + ['email' => $email]);
