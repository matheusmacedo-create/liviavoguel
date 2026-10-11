<?php
/**
 * GET ?periodo=7d|mes|mes_passado → ganhos da modelo logada (padrão: 7d).
 * { ok, repasse_centavos,
 *   resumo: { hoje, mes, total, privado_mes } (cada um { creditos, centavos, quantidade }),
 *   periodo: { nome, de, ate, soma: { creditos, centavos, quantidade }, lancamentos: [{ data, tipo, cliente, creditos, centavos }] },
 *   ultimas: os 5 lançamentos mais recentes, de qualquer data }
 * A modelo só vê os próprios ganhos: o slug sai da sessão, nunca do navegador.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

lv_exigir_metodo('GET');
$slug = (string) lv_exigir_modelo()['slug'];

$hoje = lv_modelo_inicio('today');
$mes = lv_modelo_inicio('first day of this month midnight');
$mesPassado = lv_modelo_inicio('first day of last month midnight');
$periodos = [
    '7d' => [lv_modelo_inicio('today -6 days'), null],
    'mes' => [$mes, null],
    'mes_passado' => [$mesPassado, $mes],
];
$nome = is_string($_GET['periodo'] ?? null) && isset($periodos[$_GET['periodo']]) ? $_GET['periodo'] : '7d';
[$de, $ate] = $periodos[$nome];
$utc = static fn(?string $t): ?string => $t === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($t . ' UTC'));

lv_json([
    'ok' => true,
    'repasse_centavos' => lv_modelo_repasse_centavos(),
    'resumo' => [
        'hoje' => lv_modelo_soma($slug, $hoje),
        'mes' => lv_modelo_soma($slug, $mes),
        'total' => lv_modelo_soma($slug),
        'privado_mes' => lv_modelo_soma($slug, $mes, null, 'privado'),
    ],
    'periodo' => [
        'nome' => $nome,
        'de' => $utc($de),
        'ate' => $utc($ate),
        'soma' => lv_modelo_soma($slug, $de, $ate),
        'lancamentos' => lv_modelo_lancamentos($slug, $de, $ate),
    ],
    'ultimas' => lv_modelo_lancamentos($slug, null, null, 5),
]);
