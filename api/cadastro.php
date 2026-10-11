<?php
/** POST { email, senha, maior18: true } → cria a conta e já entra. Senha com 8+ caracteres, guardada com password_hash. */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

$b = lv_ler_json();
$email = lv_email($b['email'] ?? '');
$senha = is_string($b['senha'] ?? null) ? $b['senha'] : '';
if ($email === '') {
    lv_falhar(422, 'E-mail inválido.', ['campo' => 'email']);
}
if (strlen($senha) < 8 || strlen($senha) > 200) {
    lv_falhar(422, 'A senha precisa ter pelo menos 8 caracteres.', ['campo' => 'senha']);
}
if (($b['maior18'] ?? false) !== true) {
    lv_falhar(422, 'Confirme que você tem 18 anos ou mais.', ['campo' => 'maior18']);
}
if (lv_contar_eventos('cadastro', lv_ip(), 3600) >= 10) {
    lv_falhar(429, 'Muitos cadastros seguidos. Tente mais tarde.');
}
lv_registrar('cadastro', lv_ip());
try {
    lv_db()->prepare('INSERT INTO lv_usuarios (email, senha_hash, criado_em) VALUES (?, ?, ?)')
        ->execute([$email, password_hash($senha, PASSWORD_DEFAULT), lv_agora()]);
} catch (PDOException $e) {
    if (!lv_e_duplicado($e)) {
        throw $e;
    }
    lv_falhar(409, 'Este e-mail já tem conta. Entre com sua senha.', ['campo' => 'email']);
}
$id = (int) lv_db()->lastInsertId();
lv_logar($id);
lv_json(['ok' => true, 'email' => $email, 'saldo' => 0], 201);
