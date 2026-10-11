<?php
/**
 * POST { email, senha } → entra na área da modelo e devolve { ok, modelo: { slug, nome, email } }.
 * Depois de 5 erros em 15 min para o mesmo e-mail no mesmo IP (ou 15 no IP), espera: ver lv_login_tentar.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

$b = lv_ler_json();
$email = lv_email($b['email'] ?? '');
$senha = is_string($b['senha'] ?? null) ? $b['senha'] : '';
// Erros contam por e-mail+IP: quem chuta a senha dela de outro lugar não tranca a modelo fora (lv_login_tentar).
$tentativa = lv_login_tentar('modelo_login_erro', $email, LV_MODELO_ERROS_LOGIN);

$conta = false;
if ($email !== '') {
    $stmt = lv_db()->prepare('SELECT id, slug, email, senha_hash, ativa FROM lv_modelos_contas WHERE email = ?');
    $stmt->execute([$email]);
    $conta = $stmt->fetch();
}
// Sempre compara uma senha, exista a conta ou não, para o tempo de resposta não revelar quais e-mails têm conta.
$senhaOk = password_verify($senha, $conta ? (string) $conta['senha_hash'] : lv_hash_falso());
if (!$conta || !$senhaOk || !(int) $conta['ativa']) {
    lv_falhar(401, 'E-mail ou senha incorretos.');
}
lv_login_acertou('modelo_login_erro', $email, $tentativa);

$id = (int) $conta['id'];
$senhaHash = (string) $conta['senha_hash'];
if (password_needs_rehash($senhaHash, PASSWORD_DEFAULT)) {
    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
    lv_db()->prepare('UPDATE lv_modelos_contas SET senha_hash = ? WHERE id = ?')->execute([$senhaHash, $id]);
}
lv_db()->prepare('UPDATE lv_modelos_contas SET ultimo_login_em = ? WHERE id = ?')->execute([lv_agora(), $id]);
lv_registrar('modelo_login', 'm:' . $id, lv_ip());
lv_modelo_logar($id, $senhaHash);
lv_json(['ok' => true, 'modelo' => lv_modelo_publico($conta)]);
