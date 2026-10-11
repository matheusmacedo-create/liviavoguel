<?php
/**
 * POST { apelido? , senha_atual?, senha_nova? } → muda o apelido e/ou a senha do usuário logado.
 * Trocar a senha exige a atual. Devolve o estado da conta (o mesmo de eu.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

$b = lv_ler_json();
$usuarioId = lv_exigir_login();
if (array_key_exists('apelido', $b)) {
    $apelido = lv_apelido($b['apelido']);
    if ($apelido === '') {
        lv_falhar(422, 'Apelido: de 3 a 20 letras, números ou _.', ['campo' => 'apelido']);
    }
    if (lv_apelido_reservado($apelido)) {
        lv_falhar(422, 'Esse apelido não pode ser usado. Escolha outro.', ['campo' => 'apelido']);
    }
    lv_db()->prepare('UPDATE lv_usuarios SET apelido = ? WHERE id = ?')->execute([$apelido, $usuarioId]);
}
if (array_key_exists('senha_nova', $b)) {
    $nova = is_string($b['senha_nova']) ? $b['senha_nova'] : '';
    $atual = is_string($b['senha_atual'] ?? null) ? $b['senha_atual'] : '';
    if (lv_contar_eventos('login_erro', 'u:' . $usuarioId, LV_JANELA_LOGIN) >= LV_ERROS_LOGIN) {
        lv_falhar(429, 'Muitas tentativas. Aguarde 15 minutos e tente de novo.');
    }
    $stmt = lv_db()->prepare('SELECT senha_hash FROM lv_usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    if (!password_verify($atual, (string) $stmt->fetchColumn())) {
        lv_registrar('login_erro', 'u:' . $usuarioId);
        lv_falhar(401, 'A senha atual não confere.', ['campo' => 'senha_atual']);
    }
    if (strlen($nova) < LV_SENHA_MINIMA || strlen($nova) > 200) {
        lv_falhar(422, 'A nova senha precisa ter pelo menos ' . LV_SENHA_MINIMA . ' caracteres.', ['campo' => 'senha_nova']);
    }
    $novoHash = password_hash($nova, PASSWORD_DEFAULT);
    lv_db()->prepare('UPDATE lv_usuarios SET senha_hash = ? WHERE id = ?')->execute([$novoHash, $usuarioId]);
    // Esta sessão continua; as outras (abertas com a senha antiga) caem na próxima requisição.
    lv_logar($usuarioId, $novoHash);
}
lv_json(lv_estado($usuarioId));
