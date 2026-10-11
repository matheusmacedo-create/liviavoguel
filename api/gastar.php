<?php
/**
 * POST { tipo: "gorjeta"|"privado", modelo: <slug>, creditos?, recado? } → { saldo } (402 sem saldo).
 * Gorjeta: `creditos` inteiro de 1 a LV_GORJETA_MAXIMA. Privado: um minuto, pelo preço que a modelo definiu
 * em "Configurar sala" (ou o padrão de lib/modelos.php); o `creditos` do navegador é ignorado. O débito é feito aqui, nunca no navegador.
 * A gorjeta aparece no chat da sala com o recado (opcional, até LV_RECADO_MAX caracteres, sem palavra bloqueada).
 * O privado não aparece no chat.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/chat.php';

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
    // O recado é conferido antes de debitar: recusado, a gorjeta não acontece.
    $recado = lv_texto(is_string($b['recado'] ?? null) ? $b['recado'] : '', 1000);
    if (mb_strlen($recado) > LV_RECADO_MAX) {
        lv_falhar(422, 'O recado pode ter até ' . LV_RECADO_MAX . ' caracteres.', ['campo' => 'recado']);
    }
    if ($recado !== '' && lv_chat_bloqueada($slug, $recado) !== null) {
        lv_falhar(422, $modelo['nome'] . ' bloqueou uma das palavras desse recado.', ['campo' => 'recado']);
    }
} else {
    $creditos = lv_preco_privado($slug);
    $descricao = 'Show privado com ' . $modelo['nome'] . ' (1 minuto)';
}
$saldo = lv_debitar($usuarioId, $creditos, $tipo, $descricao, $slug);
if ($saldo === null) {
    lv_falhar(402, 'Créditos insuficientes.', ['saldo' => lv_saldo($usuarioId), 'precisa' => $creditos]);
}
if ($tipo === 'gorjeta') {
    lv_chat_postar($slug, $usuarioId, (string) (lv_usuario($usuarioId)['apelido'] ?? ''), 'gorjeta', $recado, $creditos);
}
lv_json(['ok' => true, 'creditos' => $creditos, 'saldo' => $saldo]);
