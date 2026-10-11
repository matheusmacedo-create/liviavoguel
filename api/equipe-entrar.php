<?php
/** POST { email, senha } → entra no painel da equipe. 5 erros em 15 min por e-mail (15 por IP) → espera. */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/saques.php';
require_once __DIR__ . '/lib/equipe.php';

$b = lv_ler_json();
$email = lv_email($b['email'] ?? '');
$senha = is_string($b['senha'] ?? null) ? $b['senha'] : '';
if (lv_contar_eventos('equipe_login_erro', 'e:' . $email, LV_JANELA_LOGIN) >= LV_EQUIPE_ERROS_LOGIN
    || lv_contar_eventos('equipe_login_erro', 'ip:' . lv_ip(), LV_JANELA_LOGIN) >= 3 * LV_EQUIPE_ERROS_LOGIN) {
    lv_falhar(429, 'Muitas tentativas. Aguarde 15 minutos e tente de novo.');
}
$conta = false;
if ($email !== '') {
    $stmt = lv_db()->prepare('SELECT id, email, senha_hash, ativa FROM lv_equipe_contas WHERE email = ?');
    $stmt->execute([$email]);
    $conta = $stmt->fetch();
}
// Compara sempre, exista a conta ou não (mesmo tempo de resposta).
$ok = password_verify($senha, $conta ? (string) $conta['senha_hash'] : lv_modelo_hash_falso());
if (!$conta || !$ok || !(int) $conta['ativa']) {
    lv_registrar('equipe_login_erro', 'e:' . $email);
    lv_registrar('equipe_login_erro', 'ip:' . lv_ip());
    lv_falhar(401, 'E-mail ou senha incorretos.');
}
lv_db()->prepare('UPDATE lv_equipe_contas SET ultimo_login_em = ? WHERE id = ?')->execute([lv_agora(), (int) $conta['id']]);
lv_registrar('equipe_login', 'q:' . $conta['id'], lv_ip());
lv_equipe_logar((int) $conta['id']);
lv_json(['ok' => true, 'equipe' => ['email' => $email]]);
