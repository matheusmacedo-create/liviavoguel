<?php
/**
 * POST { senha, confirmacao: "EXCLUIR" } → exclui a conta do usuário logado e encerra a sessão.
 *
 * O que acontece (LGPD): os dados pessoais saem (e-mail e apelido viram anônimos, a senha deixa de funcionar, as
 * mensagens no chat perdem o texto e somem das salas). Pedidos e extrato ficam, sem dado pessoal, porque são
 * registro financeiro. Créditos não usados são perdidos. Com um pagamento ainda pendente, recusa (409): os créditos
 * cairiam numa conta excluída.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

$b = lv_ler_json();
$usuarioId = lv_exigir_login();
if (($b['confirmacao'] ?? '') !== 'EXCLUIR') {
    lv_falhar(422, 'Digite EXCLUIR para confirmar.', ['campo' => 'confirmacao']);
}
if (lv_contar_eventos('login_erro', 'u:' . $usuarioId, LV_JANELA_LOGIN) >= LV_ERROS_LOGIN) {
    lv_falhar(429, 'Muitas tentativas. Aguarde 15 minutos e tente de novo.');
}
$stmt = lv_db()->prepare('SELECT email, senha_hash FROM lv_usuarios WHERE id = ?');
$stmt->execute([$usuarioId]);
$conta = $stmt->fetch();
if (!$conta || !password_verify(is_string($b['senha'] ?? null) ? $b['senha'] : '', (string) $conta['senha_hash'])) {
    lv_registrar('login_erro', 'u:' . $usuarioId);
    lv_falhar(401, 'A senha não confere.', ['campo' => 'senha']);
}
$pendente = lv_db()->prepare("SELECT COUNT(*) FROM lv_pedidos WHERE usuario_id = ? AND status = 'pendente'");
$pendente->execute([$usuarioId]);
if ((int) $pendente->fetchColumn() > 0) {
    lv_falhar(409, 'Você tem um pagamento em andamento. Espere ele ser concluído ou vencer e tente de novo.');
}

$pdo = lv_db();
$pdo->beginTransaction();
try {
    // Conta: dados pessoais trocados por marcadores; a senha vira um hash de algo que ninguém conhece.
    $pdo->prepare('UPDATE lv_usuarios SET email = ?, apelido = ?, senha_hash = ? WHERE id = ?')->execute([
        'excluida-' . $usuarioId . '@conta-excluida.invalid',
        'excluida_' . $usuarioId,
        password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        $usuarioId,
    ]);
    // Chat: as mensagens perdem o texto e somem das salas; os avisos de gorjeta ficam, sem nome nem recado.
    $pdo->prepare("UPDATE lv_chat SET texto = '', apelido = 'conta excluída' WHERE usuario_id = ?")->execute([$usuarioId]);
    $msgs = $pdo->prepare("SELECT id, slug FROM lv_chat WHERE usuario_id = ? AND tipo = 'msg'
        AND id NOT IN (SELECT chat_id FROM lv_chat_apagadas)");
    $msgs->execute([$usuarioId]);
    $apagar = $pdo->prepare('INSERT INTO lv_chat_apagadas (chat_id, slug, apagada_em) VALUES (?, ?, ?)');
    foreach ($msgs->fetchAll() as $m) {
        $apagar->execute([(int) $m['id'], (string) $m['slug'], lv_agora()]);
    }
    $pdo->prepare('DELETE FROM lv_chat_bloqueios WHERE usuario_id = ?')->execute([$usuarioId]);
    // Registros de tentativa de login guardam o e-mail na chave: saem também.
    $pdo->prepare('DELETE FROM lv_eventos WHERE chave = ?')->execute(['e:' . $conta['email']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
lv_registrar('conta_excluida', 'u:' . $usuarioId);

lv_sessao();
$_SESSION = [];
if (!defined('LV_TESTE')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
}
lv_json(['ok' => true]);
