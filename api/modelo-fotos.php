<?php
/**
 * Fotos da modelo logada.
 * GET → { ok, fotos: [{ id, url, capa, largura, altura }], max }
 * POST multipart (campo `foto`, cabeçalho X-LV-Envio: 1) → envia uma foto (JPG, PNG ou WebP, até 5 MB, mínimo 300 px).
 *   O servidor regrava em JPG (até 1600 px, sem EXIF). A primeira foto vira a capa.
 * POST JSON { acao: "capa", id } → usa como capa;  { acao: "apagar", id } → apaga (do banco e do disco)
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/fotos.php';

$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
$tipo = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if ($metodo === 'GET') {
    $slug = (string) lv_exigir_modelo()['slug'];
    lv_json(['ok' => true, 'fotos' => lv_fotos_da_modelo($slug), 'max' => LV_FOTO_MAX_POR_MODELO]);
}

if ($metodo === 'POST' && str_starts_with($tipo, 'multipart/form-data')) {
    // Envio de arquivo não é JSON: contra envio a partir de outro site (CSRF), confere a origem e exige um cabeçalho
    // próprio, que o navegador só deixa outro site mandar com permissão (CORS) que este site não dá.
    if (!lv_origem_do_site() || ($_SERVER['HTTP_X_LV_ENVIO'] ?? '') !== '1') {
        lv_falhar(403, 'Origem não permitida.');
    }
    $conta = lv_exigir_modelo();
    $slug = (string) $conta['slug'];
    if (lv_fotos_contar($slug) >= LV_FOTO_MAX_POR_MODELO) {
        lv_falhar(409, 'Você já tem ' . LV_FOTO_MAX_POR_MODELO . ' fotos. Apague uma para enviar outra.');
    }
    if (lv_contar_eventos('foto', 'm:' . $conta['id'], 3600) >= 60) {
        lv_falhar(429, 'Muitas fotos em pouco tempo. Tente de novo mais tarde.');
    }
    [$arquivo, $largura, $altura, $erro] = lv_foto_processar(is_array($_FILES['foto'] ?? null) ? $_FILES['foto'] : [], lv_fotos_pasta($slug));
    if ($erro !== null) {
        lv_falhar(422, $erro, ['campo' => 'foto']);
    }
    lv_foto_adicionar($slug, $arquivo, $largura, $altura);
    lv_registrar('foto', 'm:' . $conta['id'], $arquivo);
    lv_json(['ok' => true, 'fotos' => lv_fotos_da_modelo($slug), 'max' => LV_FOTO_MAX_POR_MODELO]);
}

$b = lv_ler_json();
$conta = lv_exigir_modelo();
$slug = (string) $conta['slug'];
$foto = is_int($b['id'] ?? null) ? lv_foto_da_modelo($slug, $b['id']) : null;
$acao = $b['acao'] ?? '';
if (!in_array($acao, ['capa', 'apagar'], true)) {
    lv_falhar(422, 'Ação inválida.');
}
if ($foto === null) {
    lv_falhar(404, 'Foto não encontrada.');
}
if ($acao === 'capa') {
    lv_foto_capa($slug, (int) $foto['id']);
} else {
    lv_foto_apagar($slug, $foto);
}
lv_json(['ok' => true, 'fotos' => lv_fotos_da_modelo($slug), 'max' => LV_FOTO_MAX_POR_MODELO]);
