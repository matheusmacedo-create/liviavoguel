<?php
/** POST { senha_atual, senha_nova } → troca a senha da modelo logada. A nova precisa de LV_SENHA_MINIMA caracteres ou mais. */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

$b = lv_ler_json();
$conta = lv_exigir_modelo();
$atual = is_string($b['senha_atual'] ?? null) ? $b['senha_atual'] : '';
$nova = is_string($b['senha_nova'] ?? null) ? $b['senha_nova'] : '';

if (lv_contar_eventos('modelo_senha_erro', 'm:' . $conta['id'], LV_JANELA_LOGIN) >= 5) {
    lv_falhar(429, 'Muitas tentativas. Aguarde 15 minutos e tente de novo.');
}
if (!password_verify($atual, (string) $conta['senha_hash'])) {
    lv_registrar('modelo_senha_erro', 'm:' . $conta['id']);
    lv_falhar(400, 'A senha atual está incorreta.', ['campo' => 'senha_atual']);
}
if (mb_strlen($nova) < LV_SENHA_MINIMA || mb_strlen($nova) > 200) {
    lv_falhar(400, 'A nova senha precisa de ' . LV_SENHA_MINIMA . ' caracteres ou mais.', ['campo' => 'senha_nova']);
}
if (hash_equals($atual, $nova)) {
    lv_falhar(400, 'A nova senha precisa ser diferente da atual.', ['campo' => 'senha_nova']);
}
$novoHash = password_hash($nova, PASSWORD_DEFAULT);
lv_db()->prepare('UPDATE lv_modelos_contas SET senha_hash = ? WHERE id = ?')->execute([$novoHash, (int) $conta['id']]);
lv_registrar('modelo_senha', 'm:' . $conta['id'], lv_ip());
// Esta sessão continua (com a marca da senha nova); as outras, abertas com a senha antiga
// (ex.: computador emprestado, cookie roubado), deixam de valer na próxima requisição.
lv_modelo_logar((int) $conta['id'], $novoHash);
lv_json(['ok' => true]);
