<?php
/**
 * POST { tipo: "gorjeta"|"privado", modelo: <slug>, creditos? } → { saldo } (402 sem saldo).
 * Gorjeta: `creditos` inteiro de 1 a LV_GORJETA_MAXIMA. Privado: um minuto, pelo preço de lib/modelos.php
 * (o `creditos` do navegador é ignorado). O débito é feito aqui, nunca no navegador.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

$b = lv_ler_json();
$usuarioId = lv_exigir_login();
$tipo = $b['tipo'] ?? '';
$slug = lv_texto($b['modelo'] ?? '', 40);
if (!in_array($tipo, ['gorjeta', 'privado'], true)) {
    lv_falhar(422, 'Tipo de gasto inválido.');
}
$modelo = LV_MODELOS[$slug] ?? lv_falhar(422, 'Modelo não encontrada.');
if ($tipo === 'gorjeta') {
    $creditos = $b['creditos'] ?? null;
    if (!is_int($creditos) || $creditos < 1 || $creditos > LV_GORJETA_MAXIMA) {
        lv_falhar(422, 'Valor da gorjeta inválido.');
    }
    $descricao = 'Gorjeta para ' . $modelo['nome'];
} else {
    $creditos = (int) $modelo['preco_privado'];
    $descricao = 'Show privado com ' . $modelo['nome'] . ' (1 minuto)';
}
$saldo = lv_debitar($usuarioId, $creditos, $tipo, $descricao, $slug);
if ($saldo === null) {
    lv_falhar(402, 'Créditos insuficientes.', ['saldo' => lv_saldo($usuarioId), 'precisa' => $creditos]);
}
lv_json(['ok' => true, 'creditos' => $creditos, 'saldo' => $saldo]);
