<?php
/**
 * POST { nome, cidade, bio, tags: [..], idiomas: [..] } → salva o perfil da modelo logada e devolve { ok, perfil }.
 * Erro de preenchimento: 422 { erro, campo }. A foto continua em assets/img/ por enquanto.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

$b = lv_ler_json();
$conta = lv_exigir_modelo();
[$perfil, $erro] = lv_perfil_validar($b);
if ($erro !== null) {
    lv_falhar(422, $erro[1], ['campo' => $erro[0]]);
}
$salvo = lv_perfil_salvar((string) $conta['slug'], $perfil);
lv_registrar('perfil', 'm:' . $conta['id'], lv_ip());
lv_json(['ok' => true, 'perfil' => $salvo]);
