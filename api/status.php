<?php
/**
 * GET ?id=<id do pedido> → { status: "pendente"|"pago"|"falhou"|"expirado"|"estornado", creditos, ... }.
 * Só o dono do pedido consulta. Enquanto pendente, reconsulta a Unicopag (no máximo a cada 5 s): se o aviso
 * (webhook) se perder, o crédito cai mesmo assim enquanto a tela estiver aberta.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

lv_exigir_metodo('GET');
$usuarioId = lv_exigir_login();
$codigo = lv_texto($_GET['id'] ?? '', 32);
$pedido = preg_match('/^[0-9a-f]{32}$/', $codigo) ? lv_pedido_por('codigo', $codigo) : null;
if (!$pedido || (int) $pedido['usuario_id'] !== $usuarioId) {
    lv_falhar(404, 'Pedido não encontrado.');
}
$pedido = lv_atualizar_pendente($pedido);
lv_json(lv_pedido_publico($pedido) + ['saldo' => lv_saldo($usuarioId)]);
