<?php
/**
 * Aviso (postback) da Unicopag. O corpo é só gatilho: o hash serve para reconsultar a transação na API, e é essa
 * resposta que decide o status. Creditar é idempotente (lib/creditos.php): o mesmo aviso duas vezes credita uma vez.
 * Transação que o site ainda não ligou a um pedido (a resposta da cobrança se perdeu): consulta a transação na API e
 * acha o pedido pelo `metadata.pedido` que a própria API devolve (lv_pedido_pela_transacao), nunca pelo corpo do aviso.
 * Responde 200 sempre que o aviso foi compreendido, inclusive para transação desconhecida (não precisa reenviar).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

lv_exigir_metodo('POST');
$b = json_decode(lv_corpo_bruto(), true);
if (!is_array($b)) {
    $b = $_POST;
}
$hash = lv_texto($b['hash'] ?? $b['id'] ?? $b['transaction'] ?? '', 64);
if ($hash === '') {
    lv_falhar(400, 'Aviso sem hash da transação.');
}
$pedido = lv_pedido_por('unicopag_hash', $hash);
if (!$pedido) {
    // Cada aviso desconhecido custa uma consulta à Unicopag: um freio por IP para ninguém usar isso de alavanca.
    if (lv_contar_eventos('webhook_desconhecido', lv_ip(), 60) >= 30) {
        lv_falhar(429, 'Muitos avisos. Tente de novo em instantes.');
    }
    lv_registrar('webhook_desconhecido', lv_ip(), $hash);
    try {
        $pedido = lv_pedido_pela_transacao($hash);
    } catch (LvUnicopagErro $e) {
        // A consulta falhou (não "não existe"): responde erro para a Unicopag reenviar o aviso mais tarde.
        lv_falhar(502, 'Não foi possível consultar a transação agora.');
    }
    if (!$pedido) {
        lv_json(['ok' => true, 'ignorado' => 'transação desconhecida']);
    }
}
lv_registrar('webhook', (string) $pedido['id'], lv_texto($b['event'] ?? '', 40) . ' · ' . lv_texto($b['payment_status'] ?? '', 40));
$pedido = lv_sincronizar($pedido);
lv_json(['ok' => true, 'status' => $pedido['status'] ?? null]);
