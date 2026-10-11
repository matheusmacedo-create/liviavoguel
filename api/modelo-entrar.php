<?php
/**
 * POST { email, senha } → entra na área da modelo e devolve { ok, modelo: { slug, nome, email } }.
 * Depois de 5 erros em 15 min para o mesmo e-mail (ou 15 no mesmo IP), espera.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

$b = lv_ler_json();
$email = lv_email($b['email'] ?? '');
$senha = is_string($b['senha'] ?? null) ? $b['senha'] : '';
if (lv_contar_eventos('modelo_login_erro', 'e:' . $email, LV_JANELA_LOGIN) >= LV_MODELO_ERROS_LOGIN
    || lv_contar_eventos('modelo_login_erro', 'ip:' . lv_ip(), LV_JANELA_LOGIN) >= 3 * LV_MODELO_ERROS_LOGIN) {
    lv_falhar(429, 'Muitas tentativas. Aguarde 15 minutos e tente de novo.');
}

$conta = false;
if ($email !== '') {
    $stmt = lv_db()->prepare('SELECT id, slug, email, senha_hash, ativa FROM lv_modelos_contas WHERE email = ?');
    $stmt->execute([$email]);
    $conta = $stmt->fetch();
}
// Sempre compara uma senha, exista a conta ou não, para o tempo de resposta não revelar quais e-mails têm conta.
$senhaOk = password_verify($senha, $conta ? (string) $conta['senha_hash'] : lv_modelo_hash_falso());
if (!$conta || !$senhaOk || !(int) $conta['ativa']) {
    lv_registrar('modelo_login_erro', 'e:' . $email);
    lv_registrar('modelo_login_erro', 'ip:' . lv_ip());
    lv_falhar(401, 'E-mail ou senha incorretos.');
}

$id = (int) $conta['id'];
if (password_needs_rehash((string) $conta['senha_hash'], PASSWORD_DEFAULT)) {
    lv_db()->prepare('UPDATE lv_modelos_contas SET senha_hash = ? WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
}
lv_db()->prepare('UPDATE lv_modelos_contas SET ultimo_login_em = ? WHERE id = ?')->execute([lv_agora(), $id]);
lv_registrar('modelo_login', 'm:' . $id, lv_ip());
lv_modelo_logar($id);
lv_json(['ok' => true, 'modelo' => lv_modelo_publico($conta)]);
