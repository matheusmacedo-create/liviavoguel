<?php
/**
 * POST { pacote: <índice em SITE.pacotes>, metodo: "pix"|"cartao", nome, cpf, telefone?, cartao?: { numero, titular, validade "MM/AA" (ou mes + ano), cvv } }
 * O e-mail da cobrança é sempre o da conta logada (um `email` no corpo é ignorado).
 * Cria a cobrança na Unicopag para o usuário logado. O preço sai de lib/pacotes.php; nada de valor vem do navegador.
 * Pix: { id, status, creditos, pix: { qrcode_base64, qrcode_url, copia_cola, expira_em } }.
 * Cartão: { id, status, creditos, cartao: { bandeira, ultimos4 } } (402 com `erro` quando o banco recusa).
 * O cartão só passa por este processo a caminho da Unicopag: não é gravado nem vai para log.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

$b = lv_ler_json();
$usuarioId = lv_exigir_login();
$usuario = lv_usuario($usuarioId) ?? lv_falhar(401, 'Entre na sua conta para continuar.', ['login' => true]);

$pacote = $b['pacote'] ?? null;
if (!(is_int($pacote) || (is_string($pacote) && ctype_digit($pacote))) || !isset(LV_PACOTES[(int) $pacote])) {
    lv_falhar(422, 'Pacote inválido.', ['campo' => 'pacote']);
}
$pacote = (int) $pacote;
$metodo = $b['metodo'] ?? '';
if (!in_array($metodo, ['pix', 'cartao'], true)) {
    lv_falhar(422, 'Escolha Pix ou cartão.', ['campo' => 'metodo']);
}

$nome = lv_texto($b['nome'] ?? '', 120);
if (mb_strlen($nome) < 5 || !str_contains($nome, ' ')) {
    lv_falhar(422, 'Informe o nome completo.', ['campo' => 'nome']);
}
$cpf = lv_digitos(lv_texto($b['cpf'] ?? '', 20));
if (!lv_cpf_valido($cpf)) {
    lv_falhar(422, 'CPF inválido.', ['campo' => 'cpf']);
}
// Telefone é opcional (o checkout atual não pede); se vier, tem que ser válido.
$telefoneBruto = lv_texto($b['telefone'] ?? '', 25);
$telefone = lv_telefone($telefoneBruto);
if ($telefoneBruto !== '' && $telefone === '') {
    lv_falhar(422, 'Telefone inválido. Use DDD + número.', ['campo' => 'telefone']);
}

$cartao = null;
if ($metodo === 'cartao') {
    $c = is_array($b['cartao'] ?? null) ? $b['cartao'] : [];
    $numero = lv_digitos(lv_texto($c['numero'] ?? '', 30));
    $titular = lv_texto($c['titular'] ?? '', 80);
    // Validade em mes + ano, ou num campo só "MM/AA" (o checkout.html manda assim).
    if (!isset($c['mes']) && preg_match('#^\s*(\d{1,2})\s*/\s*(\d{2}|\d{4})\s*$#', lv_texto($c['validade'] ?? '', 10), $v)) {
        [$c['mes'], $c['ano']] = [$v[1], $v[2]];
    }
    $mes = (int) lv_digitos(lv_texto($c['mes'] ?? '', 2));
    $ano = (int) lv_digitos(lv_texto($c['ano'] ?? '', 4));
    $ano = $ano > 0 && $ano < 100 ? 2000 + $ano : $ano;
    $cvv = lv_digitos(lv_texto($c['cvv'] ?? '', 6));
    if (!lv_luhn_valido($numero)) {
        lv_falhar(422, 'Número do cartão inválido.', ['campo' => 'cartao_numero']);
    }
    if (mb_strlen($titular) < 3) {
        lv_falhar(422, 'Informe o nome impresso no cartão.', ['campo' => 'cartao_titular']);
    }
    if ($mes < 1 || $mes > 12 || $ano < (int) gmdate('Y') || $ano > (int) gmdate('Y') + 20 || ($ano === (int) gmdate('Y') && $mes < (int) gmdate('n'))) {
        lv_falhar(422, 'Validade do cartão inválida.', ['campo' => 'cartao_validade']);
    }
    if (strlen($cvv) < 3 || strlen($cvv) > 4) {
        lv_falhar(422, 'Código de segurança inválido.', ['campo' => 'cartao_cvv']);
    }
    $cartao = ['number' => $numero, 'holdername' => $titular, 'exp_month' => sprintf('%02d', $mes), 'exp_year' => (string) $ano, 'cvv' => $cvv];
    $ultimos4 = substr($numero, -4);
    unset($numero, $cvv, $c, $b['cartao']);
}

// Preço: o do pacote. Só as contas de TESTE_USUARIOS (ids de lv_usuarios) pagam PRECO_TESTE_CENTAVOS (compra de
// teste de R$ 1,00). Por id, e não por e-mail: o cadastro não confirma e-mail, então quem cadastrasse primeiro um
// e-mail da lista ganharia o preço de teste.
$valor = LV_PACOTES[$pacote]['centavos'];
$teste = (int) lv_cfg('PRECO_TESTE_CENTAVOS', 0);
if ($teste > 0 && in_array($usuarioId, array_map('intval', (array) lv_cfg('TESTE_USUARIOS', [])), true)) {
    $valor = $teste;
}
$creditos = LV_PACOTES[$pacote]['creditos'] + LV_PACOTES[$pacote]['bonus'];

// Pix do mesmo pacote ainda aberto (menos de 20 h): devolve o mesmo, em vez de abrir outra cobrança.
if ($metodo === 'pix') {
    $stmt = lv_db()->prepare("SELECT * FROM lv_pedidos WHERE usuario_id = ? AND metodo = 'pix' AND status = 'pendente' AND pacote = ?
        AND valor_centavos = ? AND pix_copia_cola IS NOT NULL AND criado_em > ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$usuarioId, $pacote, $valor, gmdate('Y-m-d H:i:s', time() - 20 * 3600)]);
    if ($aberto = $stmt->fetch()) {
        $aberto = lv_sincronizar($aberto);
        if ($aberto['status'] === 'pendente') {
            lv_json(lv_pedido_publico($aberto) + ['reaproveitado' => true]);
        }
    }
}

if (lv_contar_eventos('cobranca', (string) $usuarioId, 3600) >= LV_COBRANCAS_POR_HORA) {
    lv_falhar(429, 'Muitas tentativas de pagamento. Aguarde alguns minutos.');
}
lv_registrar('cobranca', (string) $usuarioId, $metodo . ' · pacote ' . $pacote);

// O pedido é gravado ANTES da cobrança: se a Unicopag aprovar e a resposta se perder no caminho (timeout, queda),
// o pedido já existe, e o aviso dela acha o pedido pelo `metadata.pedido` (webhook.php) e credita.
$codigo = bin2hex(random_bytes(16));
$agora = lv_agora();
lv_db()->prepare("INSERT INTO lv_pedidos (codigo, usuario_id, pacote, creditos, valor_centavos, metodo, status, ultimos4, criado_em, atualizado_em)
    VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?, ?, ?)")
    ->execute([$codigo, $usuarioId, $pacote, $creditos, $valor, $metodo, $metodo === 'cartao' ? $ultimos4 : null, $agora, $agora]);
$pedidoId = (int) lv_db()->lastInsertId();

$comprador = ['nome' => $nome, 'email' => $usuario['email'], 'telefone' => $telefone, 'cpf' => $cpf];
$payload = lv_montar_cobranca($comprador, $pacote, $valor, $codigo, $metodo);
if ($cartao !== null) {
    $payload['card'] = $cartao;
}
try {
    $cobranca = lv_unicopag('POST', '/public/v1/payments', $payload);
} catch (LvUnicopagErro $e) {
    lv_registrar('unicopag_recusou', (string) $usuarioId, $e->status . ' ' . $e->getMessage());
    $recusada = in_array($e->status, [400, 402, 422], true);
    if ($recusada) {
        // A Unicopag respondeu que não aceitou: nada foi cobrado.
        lv_aplicar_status($pedidoId, 'falhou');
    }
    // Sem resposta clara (fora do ar, timeout), o pedido fica pendente: se a cobrança tiver passado, o aviso
    // da Unicopag credita; se não, ele vence sozinho em 25 h (lib/creditos.php).
    lv_falhar($recusada ? 422 : 502, $e->paraOComprador());
} finally {
    unset($payload, $cartao);
}

$statusOrigem = mb_substr((string) ($cobranca['payment_status'] ?? ''), 0, 40);
$status = lv_traduzir_status($statusOrigem);
$pix = $metodo === 'pix' && is_array($cobranca['pix'] ?? null) ? $cobranca['pix'] : [];
$expira = null;
if ($metodo === 'pix') {
    $quando = strtotime((string) ($pix['expiration_date'] ?? $cobranca['expiration_date'] ?? ''));
    $expira = gmdate('Y-m-d H:i:s', $quando ?: time() + 24 * 3600);
}
$limitar = static fn(mixed $v, int $n): ?string => mb_substr((string) ($v ?? ''), 0, $n) ?: null;
$agora = lv_agora();
// O aviso da Unicopag pode ter chegado antes desta resposta e já ter pago o pedido (webhook.php): o hash só entra
// se ainda não houver, e o status só muda se ainda estiver pendente. Pago na hora passa por lv_aplicar_status abaixo.
$novoStatus = $status === 'pago' ? 'pendente' : $status;
lv_db()->prepare("UPDATE lv_pedidos SET unicopag_hash = COALESCE(unicopag_hash, ?), unicopag_status = ?,
        status = CASE WHEN status = 'pendente' THEN ? ELSE status END,
        pix_copia_cola = ?, pix_imagem = ?, expira_em = ?, bandeira = ?, atualizado_em = ?, consultado_em = ?
    WHERE id = ?")
    ->execute([
        $limitar($cobranca['hash'] ?? $cobranca['id'] ?? null, 64), $statusOrigem ?: null, $novoStatus,
        $pix ? ($pix['pix_qr_code'] ?? null) : null,
        $pix ? ($pix['pix_base64'] ?? $pix['pix_url'] ?? null) : null,
        $expira,
        $metodo === 'cartao' ? $limitar($cobranca['card_brand'] ?? null, 30) : null,
        $agora, $agora, $pedidoId,
    ]);
$pedido = lv_pedido_por('id', (string) $pedidoId);

if ($status === 'pago') {
    // Cartão aprovado na hora: credita pela porta única (a mesma do webhook).
    $pedido = lv_aplicar_status((int) $pedido['id'], 'pago');
}
if ($pedido['status'] === 'falhou') {
    $recusa = 'O pagamento não passou: o banco recusou a cobrança. Tente outro cartão ou pague com Pix.';
    lv_json(array_replace(lv_pedido_publico($pedido), ['ok' => false, 'erro' => $recusa, 'mensagem' => $recusa]), 402);
}
lv_json(lv_pedido_publico($pedido), 201);
