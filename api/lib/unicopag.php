<?php
/** Cliente da Unicopag (mesmo padrão da integração da Cruz Vermelha) e tradução de status. */
declare(strict_types=1);

class LvUnicopagErro extends RuntimeException
{
    public function __construct(public int $status, string $mensagem, public ?array $campos = null)
    {
        parent::__construct($mensagem);
    }

    /** Mensagem que faz sentido mostrar ao comprador: a primeira de campo, ou a geral de validação. */
    public function paraOComprador(): string
    {
        foreach ($this->campos ?? [] as $mensagens) {
            if (is_array($mensagens) && isset($mensagens[0]) && is_string($mensagens[0])) {
                return $mensagens[0];
            }
            if (is_string($mensagens)) {
                return $mensagens;
            }
        }
        if (in_array($this->status, [400, 402, 422], true) && $this->getMessage() !== '') {
            return $this->getMessage();
        }
        return 'Não foi possível processar o pagamento. Tente novamente.';
    }
}

/**
 * Chamada à API. `$corpo` pode conter o cartão: por isso nada do corpo entra em log ou exceção, só o método,
 * o caminho e o erro de transporte. A chave vai na query (api_token), como a Unicopag pede, e nunca sai do servidor.
 * Nos testes, $GLOBALS['LV_UNICOPAG_SIMULADA'] responde no lugar da rede.
 */
function lv_unicopag(string $metodo, string $caminho, ?array $corpo = null): array
{
    if (defined('LV_TESTE')) {
        $simulada = $GLOBALS['LV_UNICOPAG_SIMULADA'] ?? null;
        if (!is_callable($simulada)) {
            throw new LogicException('Teste sem Unicopag simulada.');
        }
        [$status, $dados] = $simulada($metodo, $caminho, $corpo);
        return lv_unicopag_resposta((int) $status, $dados);
    }
    $chave = (string) lv_cfg('UNICO_API_KEY', '');
    if ($chave === '') {
        throw new LvUnicopagErro(500, 'Gateway de pagamento não configurado.');
    }
    $url = rtrim((string) lv_cfg('UNICO_BASE_URL', 'https://api.cloud.unicopag.com.br'), '/') . $caminho
        . (str_contains($caminho, '?') ? '&' : '?') . 'api_token=' . rawurlencode($chave);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 55,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    }
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);
    if ($resposta === false) {
        error_log("[pagamentos] Unicopag inacessível ($metodo $caminho): $erroCurl");
        throw new LvUnicopagErro(502, 'Gateway de pagamento indisponível. Tente novamente.');
    }
    return lv_unicopag_resposta($status, json_decode((string) $resposta, true));
}

function lv_unicopag_resposta(int $status, mixed $dados): array
{
    if ($status >= 400 || !is_array($dados)) {
        $mensagem = is_array($dados) && !empty($dados['message']) ? (string) $dados['message'] : "Unicopag respondeu $status.";
        $campos = is_array($dados) && isset($dados['errors']) && is_array($dados['errors']) ? $dados['errors'] : null;
        throw new LvUnicopagErro($status, $mensagem, $campos);
    }
    return $dados;
}

/** Status da Unicopag nos do site. Desconhecido = pendente: o único palpite que não credita nem nega nada. */
function lv_traduzir_status(?string $origem): string
{
    static $mapa = [
        'waiting_payment' => 'pendente', 'pending' => 'pendente', 'processing' => 'pendente', 'antifraud' => 'pendente',
        'paid' => 'pago', 'pre_chargeback' => 'pago',
        'refused' => 'falhou', 'failed' => 'falhou',
        'cancelled' => 'expirado', 'canceled' => 'expirado', 'expired' => 'expirado',
        'refunded' => 'estornado', 'chargeback' => 'estornado',
    ];
    return $mapa[strtolower(trim((string) $origem))] ?? 'pendente';
}

/** Corpo do POST /public/v1/payments para um pacote. O valor vem do servidor ($valor), nunca do navegador. */
function lv_montar_cobranca(array $comprador, int $pacote, int $valor, string $codigo, string $metodo): array
{
    $p = LV_PACOTES[$pacote];
    $total = $p['creditos'] + $p['bonus'];
    return [
        'amount' => $valor,
        'payment_method' => $metodo === 'pix' ? 'pix' : 'credit_card',
        'installments' => 1,
        'customer' => array_filter([
            'name' => $comprador['nome'], 'email' => $comprador['email'],
            'phone_number' => $comprador['telefone'], 'document' => $comprador['cpf'],
        ], static fn(string $v): bool => $v !== ''),
        'cart' => [[
            'hash' => 'creditos-' . $pacote, 'title' => "Pacote de $total créditos",
            'price' => $valor, 'quantity' => 1, 'operation_type' => 1,
        ]],
        'postback_url' => lv_site_url() . '/api/webhook.php',
        'expire_in_days' => 1,
        'origin' => 'liviavoguel',
        'metadata' => ['pedido' => $codigo, 'pacote' => $pacote, 'creditos' => $total],
    ];
}

/**
 * Reconsulta a transação na Unicopag e aplica o resultado. Webhook e status.php passam por aqui: o corpo do aviso
 * nunca decide nada. Pago com valor diferente do pedido não credita (fica pendente e registrado).
 */
function lv_sincronizar(array $pedido): array
{
    if (empty($pedido['unicopag_hash'])) {
        return $pedido;
    }
    $id = (int) $pedido['id'];
    try {
        $t = lv_unicopag('GET', '/public/v1/transactions/' . rawurlencode((string) $pedido['unicopag_hash']));
    } catch (LvUnicopagErro $e) {
        lv_registrar('consulta_falhou', (string) $id, $e->status . ' ' . $e->getMessage());
        lv_db()->prepare('UPDATE lv_pedidos SET consultado_em = ? WHERE id = ?')->execute([lv_agora(), $id]);
        return lv_pedido_por('id', (string) $id) ?? $pedido;
    }
    $origem = mb_substr((string) ($t['payment_status'] ?? ''), 0, 40);
    lv_db()->prepare('UPDATE lv_pedidos SET consultado_em = ?, unicopag_status = ? WHERE id = ?')->execute([lv_agora(), $origem, $id]);
    $novo = lv_traduzir_status($origem);
    // Só credita com o valor confirmado: sem `amount` na resposta, ou com valor diferente do pedido, não credita
    // (fica pendente e registrado para a equipe olhar). O amount é em centavos, como o enviado na cobrança.
    $amount = $t['amount'] ?? null;
    if ($novo === 'pago' && (!is_numeric($amount) || (int) round((float) $amount) !== (int) $pedido['valor_centavos'])) {
        lv_registrar('valor_divergente', (string) $id, 'amount ' . (is_scalar($amount) ? (string) $amount : 'ausente') . ' · pedido ' . $pedido['valor_centavos']);
        error_log("[pagamentos] pedido $id: a Unicopag diz pago sem valor ou com valor diferente do pedido; créditos NÃO lançados");
        return lv_pedido_por('id', (string) $id) ?? $pedido;
    }
    return lv_aplicar_status($id, $novo);
}

/**
 * Aviso de uma transação que nenhum pedido tem (a resposta da cobrança se perdeu antes de o site gravar o hash):
 * consulta a transação na API e acha o pedido pelo `metadata.pedido` que a API devolve. Liga o hash ao pedido (só se
 * o pedido ainda não tiver um) e sincroniza. Devolve o pedido, ou null se a transação não for de um pedido do site.
 * LvUnicopagErro de consulta (fora do ar) sobe para o webhook pedir reenvio; transação inexistente (404) é null.
 */
function lv_pedido_pela_transacao(string $hash): ?array
{
    try {
        $t = lv_unicopag('GET', '/public/v1/transactions/' . rawurlencode($hash));
    } catch (LvUnicopagErro $e) {
        if ($e->status === 404) {
            return null;
        }
        throw $e;
    }
    $meta = $t['metadata'] ?? null;
    if (is_string($meta)) {
        $meta = json_decode($meta, true);
    }
    $codigo = is_array($meta) && is_string($meta['pedido'] ?? null) ? $meta['pedido'] : '';
    if (!preg_match('/^[0-9a-f]{32}$/', $codigo)) {
        return null;
    }
    $pedido = lv_pedido_por('codigo', $codigo);
    if (!$pedido) {
        return null;
    }
    if (empty($pedido['unicopag_hash'])) {
        lv_db()->prepare('UPDATE lv_pedidos SET unicopag_hash = ?, atualizado_em = ? WHERE id = ? AND unicopag_hash IS NULL')
            ->execute([mb_substr($hash, 0, 64), lv_agora(), (int) $pedido['id']]);
        $pedido = lv_pedido_por('id', (string) $pedido['id']) ?? $pedido;
    }
    if ($pedido['unicopag_hash'] !== $hash) {
        return null; // o pedido já está ligado a outra transação
    }
    lv_registrar('webhook_recuperado', (string) $pedido['id'], $hash);
    return lv_sincronizar($pedido);
}
