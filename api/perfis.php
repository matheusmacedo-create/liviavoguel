<?php
/**
 * GET → { ok, modelos: { <slug>: { nome, cidade, bio, tags, idiomas, atualizado_em, foto, galeria } } }. Público; só modelos
 * que já salvaram o perfil ou enviaram fotos no painel (cada campo aparece só se existir).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

require_once __DIR__ . '/lib/fotos.php';

lv_exigir_metodo('GET');
$modelos = lv_perfis();
// Fotos enviadas pelo painel: capa (foto) e galeria, também para quem ainda não salvou o perfil.
foreach (lv_fotos_publicas() as $slug => $fotos) {
    $modelos[$slug] = ($modelos[$slug] ?? []) + $fotos;
}
lv_json(['ok' => true, 'modelos' => $modelos]);
