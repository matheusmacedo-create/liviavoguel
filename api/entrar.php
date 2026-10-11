<?php
/**
 * POST { email, senha } → entra (sessão PHP) e devolve o estado da conta (o mesmo de eu.php). Depois de 10 erros em
 * 15 min para o mesmo e-mail no mesmo IP (30 no IP), espera: ver lv_login_tentar.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

$b = lv_ler_json();
$email = lv_email($b['email'] ?? '');
$senha = is_string($b['senha'] ?? null) ? $b['senha'] : '';
$tentativa = lv_login_tentar('login_erro', $email, LV_ERROS_LOGIN);
$stmt = lv_db()->prepare('SELECT id, senha_hash FROM lv_usuarios WHERE email = ?');
$stmt->execute([$email]);
$u = $email !== '' ? $stmt->fetch() : false;
// Sempre compara uma senha, exista a conta ou não, para o tempo de resposta não revelar quais e-mails têm conta.
$senhaOk = password_verify($senha, $u ? (string) $u['senha_hash'] : lv_hash_falso());
if (!$u || !$senhaOk) {
    lv_falhar(401, 'E-mail ou senha incorretos.');
}
lv_login_acertou('login_erro', $email, $tentativa);
$senhaHash = (string) $u['senha_hash'];
if (password_needs_rehash($senhaHash, PASSWORD_DEFAULT)) {
    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
    lv_db()->prepare('UPDATE lv_usuarios SET senha_hash = ? WHERE id = ?')->execute([$senhaHash, $u['id']]);
}
lv_logar((int) $u['id'], $senhaHash);
lv_json(lv_estado((int) $u['id']) + ['email' => $email]);
