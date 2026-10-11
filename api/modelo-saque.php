<?php
/**
 * Saques da modelo logada.
 * GET → { ok, pix: { tipo, chave } | null, disponivel: { creditos, centavos }, minimo_centavos, repasse_centavos, saques: [...] }
 * POST { acao: "pix", tipo: cpf|email|celular|aleatoria, chave, senha_atual } → salva a chave Pix (pede a senha: com uma
 *      sessão roubada não dá para desviar o saque). Depois de cadastrar ou trocar, o saque espera 24 h (lib/saques.php).
 * POST { acao: "pedir" } → pede o saque de tudo o que está disponível (mínimo, chave salva, um pendente por vez)
 * A equipe paga pelo Pix e marca o pedido como pago (ou recusado) no phpMyAdmin; veja api/schema-modelos.sql.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/saques.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    lv_json(lv_saque_estado((string) lv_exigir_modelo()['slug']));
}

$b = lv_ler_json();
$conta = lv_exigir_modelo();
$slug = (string) $conta['slug'];
$acao = $b['acao'] ?? '';
if ($acao === 'pix') {
    $tipo = is_string($b['tipo'] ?? null) && in_array($b['tipo'], LV_PIX_TIPOS, true) ? $b['tipo'] : null;
    if ($tipo === null) {
        lv_falhar(422, 'Escolha o tipo da chave Pix.', ['campo' => 'tipo']);
    }
    $chave = lv_pix_chave($tipo, $b['chave'] ?? null);
    if ($chave === null) {
        $exemplo = ['cpf' => 'um CPF válido', 'email' => 'um e-mail válido', 'celular' => 'um celular com DDD (11 dígitos)',
            'aleatoria' => 'a chave aleatória completa (36 caracteres)'][$tipo];
        lv_falhar(422, "A chave não parece ser $exemplo.", ['campo' => 'chave']);
    }
    // Mesmo freio da troca de senha (modelo-senha.php): os erros contam juntos.
    if (lv_contar_eventos('modelo_senha_erro', 'm:' . $conta['id'], LV_JANELA_LOGIN) >= 5) {
        lv_falhar(429, 'Muitas tentativas. Aguarde 15 minutos e tente de novo.');
    }
    $senha = is_string($b['senha_atual'] ?? null) ? $b['senha_atual'] : '';
    if (!password_verify($senha, (string) $conta['senha_hash'])) {
        lv_registrar('modelo_senha_erro', 'm:' . $conta['id']);
        lv_falhar(400, 'Confirme com a sua senha para salvar a chave Pix.', ['campo' => 'senha_atual']);
    }
    lv_pix_salvar($slug, $tipo, $chave);
    lv_registrar('saque_pix', 'm:' . $conta['id'], $tipo);
} elseif ($acao === 'pedir') {
    [$saque, $erro] = lv_saque_pedir((int) $conta['id'], $slug);
    if ($erro !== null) {
        lv_falhar(409, $erro);
    }
    lv_registrar('saque_pedido', 'm:' . $conta['id'], (string) $saque['centavos']);
} else {
    lv_falhar(422, 'Ação inválida.');
}
lv_json(lv_saque_estado($slug));
