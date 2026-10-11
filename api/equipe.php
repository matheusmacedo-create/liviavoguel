<?php
/**
 * Painel da equipe (só com o login da equipe).
 * GET → { ok, saques: { pendentes, resolvidos }, modelos: [{ id, slug, nome, email, ativa, online, ultimo_login, disponivel }] }
 * POST { acao: "saque_pago", id, observacao? }      → marca um saque pendente como pago (depois de fazer o Pix)
 * POST { acao: "saque_recusado", id, observacao }   → recusa (os créditos voltam ao disponível da modelo)
 * POST { acao: "modelo_bloquear" | "modelo_reativar", id } → bloqueia (sessão cai, sala desliga) ou reativa a conta
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/saques.php';
require_once __DIR__ . '/lib/equipe.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    lv_exigir_equipe();
    lv_json(['ok' => true, 'saques' => lv_equipe_saques(), 'modelos' => lv_equipe_modelos()]);
}

$b = lv_ler_json();
$conta = lv_exigir_equipe();
$acao = $b['acao'] ?? '';
$id = $b['id'] ?? null;
if (!is_int($id)) {
    lv_falhar(422, 'Informe o id.');
}
$observacao = lv_texto(is_string($b['observacao'] ?? null) ? $b['observacao'] : '', 190);
$erro = match ($acao) {
    'saque_pago' => lv_equipe_resolver_saque($id, 'pago', $observacao !== '' ? $observacao : null),
    'saque_recusado' => $observacao === '' ? 'Diga o motivo da recusa (a modelo vê).' : lv_equipe_resolver_saque($id, 'recusado', $observacao),
    'modelo_bloquear' => lv_equipe_ativar_modelo($id, false),
    'modelo_reativar' => lv_equipe_ativar_modelo($id, true),
    default => 'Ação inválida.',
};
if ($erro !== null) {
    lv_falhar(in_array($erro, ['Ação inválida.', 'Diga o motivo da recusa (a modelo vê).'], true) ? 422 : (str_contains($erro, 'não encontrad') ? 404 : 409), $erro);
}
lv_registrar('equipe_acao', 'q:' . $conta['id'], $acao . ' ' . $id);
lv_json(['ok' => true, 'saques' => lv_equipe_saques(), 'modelos' => lv_equipe_modelos()]);
