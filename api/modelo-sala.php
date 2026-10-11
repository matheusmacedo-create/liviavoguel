<?php
/**
 * POST { preco_privado, meta_titulo, meta_alvo, boas_vindas, bloqueadas: [..] } → salva a sala da modelo logada
 * e devolve { ok, sala }. Meta: título e valor juntos, ou os dois vazios (sem meta). Erro: 422 { erro, campo }.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

$b = lv_ler_json();
$conta = lv_exigir_modelo();
[$config, $erro] = lv_sala_validar($b);
if ($erro !== null) {
    lv_falhar(422, $erro[1], ['campo' => $erro[0]]);
}
$sala = lv_sala_salvar((string) $conta['slug'], $config);
lv_registrar('sala', 'm:' . $conta['id'], 'privado ' . $config['preco_privado']);
lv_json(['ok' => true, 'sala' => $sala]);
