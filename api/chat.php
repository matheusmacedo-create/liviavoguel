<?php
/**
 * GET ?modelo=<slug>&depois=<id> → { ok, mensagens: [{ id, tipo, apelido, texto, creditos, data }], ultimo_id, apagadas: [ids] }.
 * Público (quem só assiste também lê). Sem `depois`: as últimas mensagens. A sala consulta a cada poucos segundos e tira
 * da tela os ids de `apagadas` (o que a modelo apagou nos últimos minutos).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/chat.php';

lv_exigir_metodo('GET');
$slug = is_string($_GET['modelo'] ?? null) ? $_GET['modelo'] : '';
if (!isset(LV_MODELOS[$slug])) {
    lv_falhar(422, 'Modelo não encontrada.');
}
$depois = is_string($_GET['depois'] ?? null) && ctype_digit($_GET['depois']) ? (int) $_GET['depois'] : 0;
$mensagens = lv_chat_listar($slug, $depois);
lv_json(['ok' => true, 'mensagens' => $mensagens, 'ultimo_id' => $mensagens ? end($mensagens)['id'] : $depois,
    'apagadas' => lv_chat_apagadas_recentes($slug)]);
