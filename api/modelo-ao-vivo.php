<?php
/**
 * POST { online: true|false } → liga ou desliga a sala da modelo logada e devolve { ok, online, desde }.
 * O painel manda { online: true } a cada minuto enquanto está ao vivo; sem esse aviso por
 * LV_AO_VIVO_VALIDADE segundos, o site passa a mostrar a modelo offline.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

$b = lv_ler_json();
$conta = lv_exigir_modelo();
if (!is_bool($b['online'] ?? null)) {
    lv_falhar(422, 'Informe online: true ou false.');
}
$slug = (string) $conta['slug'];
$antes = lv_ao_vivo_todas()[$slug]['online'] ?? false;
$status = lv_ao_vivo_definir($slug, $b['online']);
if ($antes !== $status['online']) {
    lv_registrar('ao_vivo', 'm:' . $conta['id'], $status['online'] ? 'ligou' : 'desligou');
}
lv_json(['ok' => true] + $status);
