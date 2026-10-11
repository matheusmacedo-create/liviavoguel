<?php
/**
 * POST { email_novo, senha } → troca o e-mail de login do usuário logado e devolve o estado da conta (o mesmo de eu.php).
 * Exige a senha atual (com o mesmo freio de tentativas da troca de senha) e um e-mail que nenhuma outra conta use.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

$b = lv_ler_json();
$usuarioId = lv_exigir_login();
if (lv_contar_eventos('login_erro', 'u:' . $usuarioId, LV_JANELA_LOGIN) >= LV_ERROS_LOGIN) {
    lv_falhar(429, 'Muitas tentativas. Aguarde 15 minutos e tente de novo.');
}
$stmt = lv_db()->prepare('SELECT email, senha_hash FROM lv_usuarios WHERE id = ?');
$stmt->execute([$usuarioId]);
$atual = $stmt->fetch();
if (!$atual || !password_verify(is_string($b['senha'] ?? null) ? $b['senha'] : '', (string) $atual['senha_hash'])) {
    lv_registrar('login_erro', 'u:' . $usuarioId);
    lv_falhar(401, 'A senha não confere.', ['campo' => 'senha']);
}
$novo = lv_email($b['email_novo'] ?? '');
if ($novo === '') {
    lv_falhar(422, 'Informe um e-mail válido.', ['campo' => 'email_novo']);
}
if ($novo === $atual['email']) {
    lv_falhar(422, 'Esse já é o e-mail da sua conta.', ['campo' => 'email_novo']);
}
try {
    lv_db()->prepare('UPDATE lv_usuarios SET email = ? WHERE id = ?')->execute([$novo, $usuarioId]);
} catch (PDOException $e) {
    if (!lv_e_duplicado($e)) {
        throw $e;
    }
    lv_falhar(409, 'Esse e-mail já é usado por outra conta.', ['campo' => 'email_novo']);
}
lv_registrar('conta_email', 'u:' . $usuarioId);
lv_json(lv_estado($usuarioId));
