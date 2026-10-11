<?php
/**
 * POST { email, senha, apelido?, nascimento: "AAAA-MM-DD", maior18: true } → cria a conta e já entra. A idade (18+)
 * é conferida de novo aqui, e a declaração (data, IP, quando) fica em lv_usuarios_declaracoes. Senha com LV_SENHA_MINIMA+ caracteres,
 * guardada com password_hash. Sem apelido, usa o começo do e-mail. Devolve o estado da conta (o mesmo de eu.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

$b = lv_ler_json();
$email = lv_email($b['email'] ?? '');
$senha = is_string($b['senha'] ?? null) ? $b['senha'] : '';
if ($email === '') {
    lv_falhar(422, 'E-mail inválido.', ['campo' => 'email']);
}
if (strlen($senha) < LV_SENHA_MINIMA || strlen($senha) > 200) {
    lv_falhar(422, 'A senha precisa ter pelo menos ' . LV_SENHA_MINIMA . ' caracteres.', ['campo' => 'senha']);
}
$padrao = str_pad(substr(preg_replace('/\W+/', '_', (string) strstr($email, '@', true)) ?? '', 0, 20), 3, '_');
if (lv_apelido_reservado($padrao)) {
    $padrao = 'fa_' . random_int(1000, 99999); // o começo do e-mail imita uma modelo: apelido neutro
}
$apelido = lv_apelido(array_key_exists('apelido', $b) ? $b['apelido'] : $padrao);
if ($apelido === '') {
    lv_falhar(422, 'Apelido: de 3 a 20 letras, números ou _.', ['campo' => 'apelido']);
}
if (lv_apelido_reservado($apelido)) {
    lv_falhar(422, 'Esse apelido não pode ser usado. Escolha outro.', ['campo' => 'apelido']);
}
if (($b['maior18'] ?? false) !== true) {
    lv_falhar(422, 'Confirme que você tem 18 anos ou mais.', ['campo' => 'maior18']);
}
$nascimento = lv_nascimento_maior($b['nascimento'] ?? null);
if ($nascimento === null) {
    lv_falhar(422, isset($b['nascimento']) && is_string($b['nascimento']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $b['nascimento'])
        ? 'O site é só para maiores de 18 anos.' : 'Informe sua data de nascimento.', ['campo' => 'nascimento']);
}
if (lv_contar_eventos('cadastro', lv_ip(), 3600) >= 10) {
    lv_falhar(429, 'Muitos cadastros seguidos. Tente mais tarde.');
}
lv_registrar('cadastro', lv_ip());
$senhaHash = password_hash($senha, PASSWORD_DEFAULT);
try {
    lv_db()->prepare('INSERT INTO lv_usuarios (email, apelido, senha_hash, criado_em) VALUES (?, ?, ?, ?)')
        ->execute([$email, $apelido, $senhaHash, lv_agora()]);
} catch (PDOException $e) {
    if (!lv_e_duplicado($e)) {
        throw $e;
    }
    lv_falhar(409, 'Este e-mail já tem conta. Entre com sua senha.', ['campo' => 'email']);
}
$id = (int) lv_db()->lastInsertId();
lv_db()->prepare('INSERT INTO lv_usuarios_declaracoes (usuario_id, nascimento, ip, declarado_em) VALUES (?, ?, ?, ?)')
    ->execute([$id, $nascimento, lv_ip(), lv_agora()]);
lv_logar($id, $senhaHash);
lv_json(lv_estado($id) + ['email' => $email], 201);
