<?php
/**
 * Moderação do chat pela modelo logada (só na própria sala).
 * GET ?depois=<id> → { ok, mensagens (com `usuario`), ultimo_id, apagadas, bloqueados: [{ usuario, apelido, desde }] }
 * POST { acao: "apagar", id }          → apaga a mensagem para todos
 * POST { acao: "bloquear", id }        → bloqueia quem escreveu a mensagem e apaga as mensagens dessa pessoa na sala
 * POST { acao: "desbloquear", usuario } → volta a deixar a pessoa escrever
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/chat.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $slug = (string) lv_exigir_modelo()['slug'];
    $depois = is_string($_GET['depois'] ?? null) && ctype_digit($_GET['depois']) ? (int) $_GET['depois'] : 0;
    $mensagens = lv_chat_listar($slug, $depois, true);
    lv_json(['ok' => true, 'mensagens' => $mensagens, 'ultimo_id' => $mensagens ? end($mensagens)['id'] : $depois,
        'apagadas' => lv_chat_apagadas_recentes($slug), 'bloqueados' => lv_chat_bloqueados($slug)]);
}

$b = lv_ler_json();
$conta = lv_exigir_modelo();
$slug = (string) $conta['slug'];
$acao = $b['acao'] ?? '';
if ($acao === 'desbloquear') {
    if (!is_int($b['usuario'] ?? null)) {
        lv_falhar(422, 'Informe quem desbloquear.');
    }
    lv_chat_desbloquear($slug, $b['usuario']);
} elseif ($acao === 'apagar' || $acao === 'bloquear') {
    $msg = is_int($b['id'] ?? null) ? lv_chat_mensagem($slug, $b['id']) : null;
    if ($msg === null) {
        lv_falhar(404, 'Mensagem não encontrada.');
    }
    if ($acao === 'apagar') {
        lv_chat_apagar($slug, (int) $msg['id']);
    } else {
        if ($msg['usuario_id'] === null) {
            lv_falhar(422, 'Essa mensagem não tem autor para bloquear.');
        }
        lv_chat_bloquear($slug, (int) $msg['usuario_id']);
    }
} else {
    lv_falhar(422, 'Ação inválida.');
}
lv_registrar('chat_moderacao', 'm:' . $conta['id'], $acao);
lv_json(['ok' => true, 'bloqueados' => lv_chat_bloqueados($slug), 'apagadas' => lv_chat_apagadas_recentes($slug)]);
