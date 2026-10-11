<?php
/**
 * Testes do backend de pagamentos (api/): sem rede, sem MySQL e sem segredos.
 *
 * Uso:  php scripts/testar_pagamentos.php
 * Sai com código 1 se algum teste falhar. A Unicopag é simulada ($GLOBALS['LV_UNICOPAG_SIMULADA']), o banco é um
 * SQLite em memória com as mesmas tabelas de api/schema.sql, e a configuração é um arquivo descartável.
 */
declare(strict_types=1);

// Só pela linha de comando: pelo navegador (se o .htaccess faltar), responde 404 e não roda nada.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const LV_TESTE = true;
$raiz = dirname(__DIR__);
$configTeste = tempnam(sys_get_temp_dir(), 'lv-config-');
/** Configuração dos testes; $extra entra por cima (ex.: TESTE_USUARIOS, PRIVADO_ATIVO). Relida a cada chamada. */
function configurar(array $extra = []): void
{
    global $configTeste;
    $config = ['SITE_URL' => 'https://exemplo.com.br', 'UNICO_API_KEY' => 'nao-usada-nos-testes', 'PRECO_TESTE_CENTAVOS' => 100,
        'MODELO_ESPERA_SAQUE_DIAS' => 0, 'PRIVADO_ATIVO' => true] + [];
    file_put_contents($configTeste, '<?php return ' . var_export(array_replace($config, $extra), true) . ';');
}
configurar();
putenv("LV_CONFIG_ARQUIVO=$configTeste");
register_shutdown_function(static fn() => @unlink($configTeste));
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/api/lib/base.php';
require_once $raiz . '/api/lib/modelo-conta.php';
require_once $raiz . '/api/lib/saques.php';

$falhas = 0;
$total = 0;
function verificar(string $nome, mixed $obtido, mixed $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido === $esperado) {
        return;
    }
    $falhas++;
    fwrite(STDERR, sprintf("FALHOU %s\n  esperado: %s\n  obtido:   %s\n", $nome, var_export($esperado, true), var_export($obtido, true)));
}

/** Sessão de cliente como o login (lv_logar) deixa: com a marca da senha atual. */
function sessao_cliente(int $id): array
{
    $hash = (string) lv_db()->query('SELECT senha_hash FROM lv_usuarios WHERE id = ' . $id)->fetchColumn();
    return ['usuario_id' => $id, 'usuario_marca' => lv_marca_senha($hash)];
}

// ----------------------------------------------------------------------------- banco de teste (SQLite)
function banco_novo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    // schema-modelos.sql também: o api/gastar.php lê o preço do privado da configuração da sala.
    $sql = (string) file_get_contents(dirname(__DIR__) . '/api/schema.sql') . "\n" . file_get_contents(dirname(__DIR__) . '/api/schema-modelos.sql');
    // Do dialeto do MySQL para o do SQLite, só o necessário: sem ENGINE, sem KEY avulsa, sem FOREIGN KEY nomeada.
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    $sql = preg_replace('/\)\s*ENGINE=[^;]+;/', ');', $sql);
    $sql = str_replace('INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/,\s*KEY \w+ \([^)]*\)/', '', $sql);
    $sql = preg_replace('/,\s*CONSTRAINT \w+ FOREIGN KEY[^,)]*\)[^,)]*\([^)]*\)/', '', $sql);
    $sql = preg_replace('/UNIQUE KEY \w+ /', 'UNIQUE ', $sql);
    $pdo->exec($sql);
    return $pdo;
}

// ----------------------------------------------------------------------------- Unicopag simulada
$unico = ['transacoes' => [], 'chamadas' => [], 'proximo' => null, 'seq' => 0, 'perder_resposta' => false, 'consulta_fora' => false];
$GLOBALS['LV_UNICOPAG_SIMULADA'] = static function (string $metodo, string $caminho, ?array $corpo) use (&$unico): array {
    $unico['chamadas'][] = [$metodo, $caminho, $corpo];
    if ($metodo === 'POST' && $caminho === '/public/v1/payments') {
        if ($unico['proximo'] !== null) {
            [$status, $resposta] = $unico['proximo'];
            $unico['proximo'] = null;
            if ($status >= 400) {
                return [$status, $resposta];
            }
        }
        $hash = 'tx' . (++$unico['seq']);
        $pix = $corpo['payment_method'] === 'pix';
        $t = ['hash' => $hash, 'amount' => $corpo['amount'], 'payment_method' => $corpo['payment_method'],
            'payment_status' => $resposta['payment_status'] ?? ($pix ? 'waiting_payment' : 'paid'), 'metadata' => $corpo['metadata']];
        if ($pix) {
            $t['pix'] = ['pix_qr_code' => '00020126PIXCOPIAECOLA' . $hash, 'pix_base64' => 'iVBORw0KGgoAAAANSUhEUg' . $hash];
        } else {
            $t['card_brand'] = 'visa';
        }
        $unico['transacoes'][$hash] = $t;
        if ($unico['perder_resposta']) {
            // A cobrança foi criada (e o cartão aprovado), mas a resposta não chegou ao site.
            $unico['perder_resposta'] = false;
            return [502, ['message' => 'Bad Gateway']];
        }
        return [200, $t];
    }
    if ($metodo === 'GET' && $unico['consulta_fora']) {
        return [500, ['message' => 'fora do ar']];
    }
    if ($metodo === 'GET' && preg_match('#^/public/v1/transactions/(\w+)$#', $caminho, $m)) {
        return isset($unico['transacoes'][$m[1]]) ? [200, $unico['transacoes'][$m[1]]] : [404, ['message' => 'Not found']];
    }
    return [404, ['message' => 'rota simulada desconhecida']];
};

/** Roda um endpoint como o Apache rodaria e devolve [status, corpo]. */
function chamar(string $arquivo, string $metodo = 'GET', ?array $corpo = null, array $get = [], array $servidor = []): array
{
    // $servidor vem primeiro: o que o teste passar (IP, origem, tipo do corpo) vale sobre o padrão.
    $_SERVER = $servidor + ['REQUEST_METHOD' => $metodo, 'REMOTE_ADDR' => '203.0.113.7', 'CONTENT_TYPE' => $corpo !== null ? 'application/json' : ''];
    $_GET = $get;
    $_POST = [];
    $GLOBALS['LV_CORPO_TESTE'] = $corpo !== null ? json_encode($corpo) : ($servidor['_corpo'] ?? '');
    try {
        include dirname(__DIR__) . '/api/' . $arquivo;
    } catch (LvResposta $r) {
        return [$r->status, $r->corpo];
    }
    throw new LogicException("$arquivo terminou sem resposta");
}

function pagar(string $hash, string $status = 'paid'): void
{
    global $unico;
    $unico['transacoes'][$hash]['payment_status'] = $status;
}

$comprador = ['nome' => 'Fulano de Tal', 'cpf' => '529.982.247-25', 'telefone' => '(21) 99999-8888'];
$cartaoOk = ['numero' => '4111 1111 1111 1111', 'titular' => 'FULANO DE TAL', 'mes' => '12', 'ano' => (string) ((int) gmdate('Y') + 2), 'cvv' => '123'];

// ============================================================================= funções puras
verificar('cpf válido', lv_cpf_valido('529.982.247-25'), true);
verificar('cpf dígito errado', lv_cpf_valido('52998224726'), false);
verificar('cpf repetido', lv_cpf_valido('11111111111'), false);
verificar('luhn válido', lv_luhn_valido('4111111111111111'), true);
verificar('luhn inválido', lv_luhn_valido('4111111111111112'), false);
verificar('telefone celular', lv_telefone('(21) 99999-8888'), '21999998888');
verificar('telefone com 55', lv_telefone('+55 21 3333-4444'), '2133334444');
verificar('telefone curto', lv_telefone('9999-8888'), '');
verificar('status paid', lv_traduzir_status('paid'), 'pago');
verificar('status waiting', lv_traduzir_status('waiting_payment'), 'pendente');
verificar('status refused', lv_traduzir_status('refused'), 'falhou');
verificar('status expired', lv_traduzir_status('expired'), 'expirado');
verificar('status refunded', lv_traduzir_status('refunded'), 'estornado');
verificar('status desconhecido = pendente', lv_traduzir_status('qualquer'), 'pendente');

// Os pacotes do servidor batem com SITE.pacotes do config.js (mesma ordem, créditos, bônus e preço).
$js = (string) file_get_contents($raiz . '/assets/js/config.js');
preg_match_all('/\{\s*creditos:\s*(\d+),\s*preco:\s*([\d.]+),\s*bonus:\s*(\d+)/', $js, $m, PREG_SET_ORDER);
verificar('mesma quantidade de pacotes que config.js', count($m), count(LV_PACOTES));
foreach ($m as $i => $p) {
    verificar("pacote $i igual ao config.js", [(int) $p[1], (int) $p[3], (int) round((float) $p[2] * 100)],
        [LV_PACOTES[$i]['creditos'] ?? null, LV_PACOTES[$i]['bonus'] ?? null, LV_PACOTES[$i]['centavos'] ?? null]);
}

// ============================================================================= cadastro, login, sessão
lv_db(banco_novo());
$_SESSION = [];
[$s, $r] = chamar('saldo.php');
verificar('saldo sem login = 401', [$s, $r['login'] ?? null], [401, true]);
[$s] = chamar('cadastro.php', 'POST', ['email' => 'ana@exemplo.com.br', 'senha' => 'curta', 'maior18' => true]);
verificar('senha curta recusada', $s, 422);
[$s] = chamar('cadastro.php', 'POST', ['email' => 'ana@exemplo.com.br', 'senha' => 'segredo123']);
verificar('cadastro sem 18+ recusado', $s, 422);
[$s, $r] = chamar('cadastro.php', 'POST', ['email' => 'Ana@Exemplo.com.br', 'senha' => 'segredo123', 'maior18' => true]);
verificar('cadastro ok', [$s, $r['email'] ?? null], [201, 'ana@exemplo.com.br']);
$hash = lv_db()->query("SELECT senha_hash FROM lv_usuarios WHERE email = 'ana@exemplo.com.br'")->fetchColumn();
verificar('senha guardada com password_hash', password_verify('segredo123', (string) $hash) && $hash !== 'segredo123', true);
[$s] = chamar('cadastro.php', 'POST', ['email' => 'ana@exemplo.com.br', 'senha' => 'outra12345', 'maior18' => true]);
verificar('e-mail repetido = 409', $s, 409);
[$s] = chamar('cadastro.php', 'POST', ['email' => 'x@exemplo.com.br', 'senha' => 'segredo123', 'maior18' => true], [], ['HTTP_ORIGIN' => 'https://malicioso.example']);
verificar('outra origem = 403', $s, 403);
[$s] = chamar('cadastro.php', 'POST', null, [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', '_corpo' => 'email=a']);
verificar('formulário (não JSON) = 415', $s, 415);
[$s] = chamar('cadastro.php', 'POST', ['email' => 'www@exemplo.com.br', 'senha' => 'segredo123', 'maior18' => true], [], ['HTTP_ORIGIN' => 'https://www.exemplo.com.br']);
verificar('origem www aceita', $s, 201);

[$s] = chamar('sair.php', 'POST');
verificar('sair ok', [$s, $_SESSION], [200, []]);
[$s] = chamar('entrar.php', 'POST', ['email' => 'ana@exemplo.com.br', 'senha' => 'errada123']);
verificar('senha errada = 401', $s, 401);
[$s, $r] = chamar('entrar.php', 'POST', ['email' => 'ana@exemplo.com.br', 'senha' => 'segredo123']);
verificar('entrar ok', [$s, $r['saldo'] ?? null], [200, 0]);
$ana = $_SESSION['usuario_id'];
[$s, $r] = chamar('saldo.php');
verificar('saldo inicial 0', [$s, $r['saldo'] ?? null], [200, 0]);

// ============================================================================= cobrança Pix + webhook
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 9, 'metodo' => 'pix'] + $comprador);
verificar('pacote inexistente = 422', [$s, $r['campo'] ?? null], [422, 'pacote']);
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 1, 'metodo' => 'boleto'] + $comprador);
verificar('método inválido = 422', [$s, $r['campo'] ?? null], [422, 'metodo']);
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 1, 'metodo' => 'pix', 'cpf' => '111.111.111-11'] + $comprador);
verificar('cpf inválido = 422', [$s, $r['campo'] ?? null], [422, 'cpf']);
verificar('nenhuma cobrança com dados inválidos', count($unico['chamadas']), 0);

[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 1, 'metodo' => 'pix', 'preco' => 0.01, 'valor' => 1, 'amount' => 1] + $comprador);
verificar('pix criado = 201', $s, 201);
verificar('pix pendente', $r['status'] ?? null, 'pendente');
verificar('pix créditos com bônus', $r['creditos'] ?? null, 130);
verificar('pix copia e cola', $r['pix']['copia_cola'] ?? null, '00020126PIXCOPIAECOLAtx1');
verificar('pix qrcode base64', $r['pix']['qrcode_base64'] ?? null, 'iVBORw0KGgoAAAANSUhEUgtx1');
verificar('pix expira em ISO', (bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) ($r['pix']['expira_em'] ?? '')), true);
verificar('id público de 32 hex', (bool) preg_match('/^[0-9a-f]{32}$/', (string) ($r['id'] ?? '')), true);
$enviado = $unico['chamadas'][0][2];
verificar('preço do navegador ignorado: amount = 4490', $enviado['amount'], 4490);
verificar('método pix na Unicopag', $enviado['payment_method'], 'pix');
verificar('postback_url', $enviado['postback_url'], 'https://exemplo.com.br/api/webhook.php');
verificar('cliente na Unicopag', $enviado['customer'], ['name' => 'Fulano de Tal', 'email' => 'ana@exemplo.com.br', 'phone_number' => '21999998888', 'document' => '52998224725']);
$pixId = $r['id'];

[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 1, 'metodo' => 'pix'] + $comprador);
verificar('pix aberto reaproveitado', [$s, $r['id'] ?? null, $r['reaproveitado'] ?? null], [200, $pixId, true]);
verificar('reaproveitar não cria outra cobrança', count(array_filter($unico['chamadas'], static fn($c) => $c[0] === 'POST')), 1);

[$s, $r] = chamar('status.php', 'GET', null, ['id' => $pixId]);
verificar('status pendente', [$s, $r['status'] ?? null], [200, 'pendente']);

// Webhook mentiroso: o corpo diz "paid", mas a Unicopag ainda diz waiting_payment. Não credita.
[$s, $r] = chamar('webhook.php', 'POST', ['hash' => 'tx1', 'payment_status' => 'paid', 'event' => 'transaction.paid']);
verificar('webhook não confia no corpo', [$s, $r['status'] ?? null], [200, 'pendente']);
verificar('saldo continua 0', lv_saldo($ana), 0);

pagar('tx1');
[$s, $r] = chamar('webhook.php', 'POST', ['hash' => 'tx1', 'payment_status' => 'paid']);
verificar('webhook paga', [$s, $r['status'] ?? null], [200, 'pago']);
verificar('créditos lançados', lv_saldo($ana), 130);
[$s, $r] = chamar('webhook.php', 'POST', ['hash' => 'tx1', 'payment_status' => 'paid']);
verificar('webhook repetido ok', [$s, $r['status'] ?? null], [200, 'pago']);
verificar('webhook repetido não credita de novo', lv_saldo($ana), 130);
verificar('um lançamento só no extrato', (int) lv_db()->query('SELECT COUNT(*) FROM lv_extrato')->fetchColumn(), 1);
// Status voltou a pendente por fora (ex.: edição à mão): a chave única segura o segundo lançamento.
$pedidoId = (int) lv_pedido_por('codigo', $pixId)['id'];
lv_db()->exec("UPDATE lv_pedidos SET status = 'pendente' WHERE id = $pedidoId");
lv_aplicar_status($pedidoId, 'pago');
verificar('corrida: chave única impede crédito duplo', lv_saldo($ana), 130);
verificar('corrida: pedido volta a pago', lv_pedido_por('id', (string) $pedidoId)['status'], 'pago');

[$s, $r] = chamar('webhook.php', 'POST', ['hash' => 'desconhecido']);
verificar('webhook de transação desconhecida = 200', [$s, $r['ignorado'] ?? null], [200, 'transação desconhecida']);
[$s] = chamar('webhook.php', 'POST', ['event' => 'x']);
verificar('webhook sem hash = 400', $s, 400);
[$s, $r] = chamar('webhook.php', 'POST', null, [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', '_corpo' => '']);
verificar('webhook em formulário vazio = 400', $s, 400);

[$s, $r] = chamar('status.php', 'GET', null, ['id' => $pixId]);
verificar('status pago com saldo', [$s, $r['status'] ?? null, $r['saldo'] ?? null], [200, 'pago', 130]);
[$s, $r] = chamar('saldo.php');
verificar('saldo.php = 130', $r['saldo'] ?? null, 130);

// Pago com valor diferente do pedido: não credita.
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'pix'] + $comprador);
$h = lv_pedido_por('codigo', $r['id'])['unicopag_hash'];
$unico['transacoes'][$h]['amount'] = 1;
pagar($h);
chamar('webhook.php', 'POST', ['hash' => $h]);
verificar('valor divergente não credita', [lv_pedido_por('unicopag_hash', $h)['status'], lv_saldo($ana)], ['pendente', 130]);
unset($unico['transacoes'][$h]['amount']);
chamar('webhook.php', 'POST', ['hash' => $h]);
verificar('pago sem valor na resposta não credita', [lv_pedido_por('unicopag_hash', $h)['status'], lv_saldo($ana)], ['pendente', 130]);
$unico['transacoes'][$h]['amount'] = '19.90';
chamar('webhook.php', 'POST', ['hash' => $h]);
verificar('valor em reais (não centavos) não credita', [lv_pedido_por('unicopag_hash', $h)['status'], lv_saldo($ana)], ['pendente', 130]);

// O webhook se perdeu: a consulta da tela (status.php) credita mesmo assim.
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 2, 'metodo' => 'pix'] + $comprador);
$id3 = $r['id'];
$h3 = lv_pedido_por('codigo', $id3)['unicopag_hash'];
pagar($h3);
lv_db()->exec("UPDATE lv_pedidos SET consultado_em = '2000-01-01 00:00:00' WHERE codigo = '$id3'");
[$s, $r] = chamar('status.php', 'GET', null, ['id' => $id3]);
verificar('status.php credita sem webhook', [$r['status'] ?? null, $r['saldo'] ?? null], ['pago', 130 + 340]);

// Pix vencido: depois de 25 h pendente, expira.
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 3, 'metodo' => 'pix'] + $comprador);
$id4 = $r['id'];
lv_db()->exec("UPDATE lv_pedidos SET criado_em = '2000-01-01 00:00:00', consultado_em = '2000-01-01 00:00:00' WHERE codigo = '$id4'");
[$s, $r] = chamar('status.php', 'GET', null, ['id' => $id4]);
verificar('pix vencido = expirado', $r['status'] ?? null, 'expirado');
pagar(lv_pedido_por('codigo', $id4)['unicopag_hash']);
chamar('webhook.php', 'POST', ['hash' => lv_pedido_por('codigo', $id4)['unicopag_hash']]);
verificar('pago depois de expirado ainda credita', lv_pedido_por('codigo', $id4)['status'], 'pago');
verificar('saldo com o pacote pago tarde', lv_saldo($ana), 130 + 340 + 820);

// ============================================================================= cartão
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'cartao', 'cartao' => ['numero' => '4111111111111112'] + $cartaoOk] + $comprador);
verificar('cartão com número inválido = 422', [$s, $r['campo'] ?? null], [422, 'cartao_numero']);
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'cartao', 'cartao' => ['ano' => '2001'] + $cartaoOk] + $comprador);
verificar('cartão vencido = 422', [$s, $r['campo'] ?? null], [422, 'cartao_validade']);

$antes = lv_saldo($ana);
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'cartao', 'cartao' => $cartaoOk] + $comprador);
verificar('cartão aprovado = 201 pago', [$s, $r['status'] ?? null], [201, 'pago']);
verificar('cartão: bandeira e final', $r['cartao'] ?? null, ['bandeira' => 'visa', 'ultimos4' => '1111']);
verificar('cartão aprovado credita na hora', lv_saldo($ana), $antes + 50);
$ultimo = end($unico['chamadas'])[2];
verificar('cartão enviado à Unicopag', [$ultimo['payment_method'], $ultimo['card']['number'], $ultimo['card']['exp_month']], ['credit_card', '4111111111111111', '12']);
$linha = lv_pedido_por('codigo', $r['id']);
verificar('número do cartão não é gravado', str_contains(json_encode($linha), '4111111111111111') || str_contains(json_encode($linha), '"123"'), false);
$cartaoId = $r['id'];
chamar('webhook.php', 'POST', ['hash' => $linha['unicopag_hash']]);
verificar('webhook do cartão já pago não credita de novo', lv_saldo($ana), $antes + 50);

$unico['proximo'] = [200, ['payment_status' => 'refused']];
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 1, 'metodo' => 'cartao', 'cartao' => $cartaoOk] + $comprador);
verificar('cartão recusado = 402 falhou', [$s, $r['status'] ?? null, $r['ok'] ?? null], [402, 'falhou', false]);
verificar('cartão recusado traz erro e mensagem', ($r['erro'] ?? null) !== null && ($r['mensagem'] ?? null) === ($r['erro'] ?? null), true);
verificar('recusado não credita', lv_saldo($ana), $antes + 50);

$unico['proximo'] = [422, ['message' => 'Dados inválidos', 'errors' => ['card.number' => ['Cartão não aceito.']]]];
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 1, 'metodo' => 'cartao', 'cartao' => $cartaoOk] + $comprador);
verificar('erro de validação da Unicopag chega ao comprador', [$s, $r['erro'] ?? null], [422, 'Cartão não aceito.']);
$ultimoPedido = static fn(): array => lv_db()->query('SELECT * FROM lv_pedidos ORDER BY id DESC LIMIT 1')->fetch();
verificar('recusada pela Unicopag: o pedido fica como falhou', [$ultimoPedido()['status'], $ultimoPedido()['unicopag_hash']], ['falhou', null]);
$unico['proximo'] = [500, ['message' => 'boom']];
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 1, 'metodo' => 'cartao', 'cartao' => $cartaoOk] + $comprador);
verificar('Unicopag fora = 502 com mensagem genérica', [$s, $r['erro'] ?? null], [502, 'Não foi possível processar o pagamento. Tente novamente.']);
verificar('sem resposta clara: o pedido fica pendente', $ultimoPedido()['status'], 'pendente');

// A Unicopag aprova o cartão, mas a resposta se perde: o pedido já existe e o aviso dela credita.
$antes = lv_saldo($ana);
$unico['perder_resposta'] = true;
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'cartao', 'cartao' => $cartaoOk] + $comprador);
$perdido = $ultimoPedido();
$hp = 'tx' . $unico['seq'];
verificar('resposta perdida: 502, pedido pendente sem hash, nada creditado', [$s, $perdido['status'], $perdido['unicopag_hash'], lv_saldo($ana)], [502, 'pendente', null, $antes]);
$unico['consulta_fora'] = true;
[$s] = chamar('webhook.php', 'POST', ['hash' => $hp]);
verificar('aviso desconhecido com a consulta fora do ar: 502 (a Unicopag reenvia)', $s, 502);
$unico['consulta_fora'] = false;
[$s, $r] = chamar('webhook.php', 'POST', ['hash' => $hp, 'payment_status' => 'paid']);
verificar('aviso acha o pedido pelo metadata da API e credita', [$s, $r['status'] ?? null, lv_pedido_por('id', (string) $perdido['id'])['unicopag_hash'], lv_saldo($ana)],
    [200, 'pago', $hp, $antes + 50]);
chamar('webhook.php', 'POST', ['hash' => $hp]);
verificar('aviso repetido não credita de novo', lv_saldo($ana), $antes + 50);
// Transação de outro pedido (metadata de um pedido que já tem outro hash) não é religada.
$unico['transacoes']['txfalso'] = ['hash' => 'txfalso', 'amount' => 1990, 'payment_status' => 'paid', 'metadata' => ['pedido' => $perdido['codigo']]];
[$s, $r] = chamar('webhook.php', 'POST', ['hash' => 'txfalso']);
verificar('transação que aponta para pedido já ligado: ignorada', [$s, $r['ignorado'] ?? null, lv_saldo($ana)], [200, 'transação desconhecida', $antes + 50]);

// Contrato do checkout.html: validade "MM/AA", sem telefone, com um e-mail no corpo (ignorado).
$aa = substr((string) ((int) gmdate('Y') + 3), 2);
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'cartao', 'nome' => 'Fulano de Tal', 'cpf' => '52998224725',
    'email' => 'outro@exemplo.com.br', 'cartao' => ['numero' => '4111111111111111', 'titular' => 'FULANO', 'validade' => "07/$aa", 'cvv' => '123']]);
verificar('checkout.html: cartão com validade MM/AA', [$s, $r['status'] ?? null], [201, 'pago']);
$ultimo = end($unico['chamadas'])[2];
verificar('checkout.html: validade convertida', [$ultimo['card']['exp_month'], $ultimo['card']['exp_year']], ['07', "20$aa"]);
verificar('checkout.html: sem telefone, sem phone_number', array_key_exists('phone_number', $ultimo['customer']), false);
verificar('checkout.html: e-mail é o da conta', $ultimo['customer']['email'], 'ana@exemplo.com.br');
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'cartao', 'nome' => 'Fulano de Tal', 'cpf' => '52998224725',
    'cartao' => ['numero' => '4111111111111111', 'titular' => 'FULANO', 'validade' => '13/30', 'cvv' => '123']]);
verificar('checkout.html: validade MM/AA inválida', [$s, $r['campo'] ?? null], [422, 'cartao_validade']);
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'pix', 'telefone' => '123'] + $comprador);
verificar('telefone informado e inválido = 422', [$s, $r['campo'] ?? null], [422, 'telefone']);

// Estorno: tira os créditos da compra, uma vez só.
$hc = lv_pedido_por('codigo', $cartaoId)['unicopag_hash'];
$antes = lv_saldo($ana);
pagar($hc, 'refunded');
chamar('webhook.php', 'POST', ['hash' => $hc]);
chamar('webhook.php', 'POST', ['hash' => $hc]);
verificar('estorno tira os créditos uma vez', [lv_pedido_por('codigo', $cartaoId)['status'], lv_saldo($ana)], ['estornado', $antes - 50]);
$estornosDe = static fn(int $pedidoId): array => lv_db()->query("SELECT slug, creditos FROM lv_modelos_estornos WHERE pedido_id = $pedidoId ORDER BY slug")->fetchAll(PDO::FETCH_NUM);
verificar('estorno de créditos ainda não gastos não desconta das modelos', $estornosDe((int) lv_pedido_por('codigo', $cartaoId)['id']), []);

// Estorno (chargeback) de uma compra já gasta em gorjetas: o que ficou sem dinheiro sai do saque das modelos.
$sessaoAna = $_SESSION;
$_SESSION = [];
chamar('cadastro.php', 'POST', ['email' => 'golpe@exemplo.com.br', 'senha' => 'segredo123', 'maior18' => true]);
$golpe = $_SESSION['usuario_id'];
[, $r] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'cartao', 'cartao' => $cartaoOk] + $comprador); // +50
$pedidoGolpe = lv_pedido_por('codigo', $r['id']);
$dispAntes = lv_saque_disponivel('livia-voguel')['creditos'];
lv_debitar($golpe, 30, 'gorjeta', 'Gorjeta para Livia', 'livia-voguel');
lv_debitar($golpe, 15, 'gorjeta', 'Gorjeta para outra', 'outra-modelo'); // sobram 5
verificar('gorjetas entram no disponível da modelo', lv_saque_disponivel('livia-voguel')['creditos'], $dispAntes + 30);
pagar($pedidoGolpe['unicopag_hash'], 'chargeback');
chamar('webhook.php', 'POST', ['hash' => $pedidoGolpe['unicopag_hash']]);
chamar('webhook.php', 'POST', ['hash' => $pedidoGolpe['unicopag_hash']]);
verificar('chargeback: comprador negativo', lv_saldo($golpe), -45);
verificar('chargeback: os 45 sem dinheiro saem das modelos, do gasto mais recente', $estornosDe((int) $pedidoGolpe['id']), [['livia-voguel', 30], ['outra-modelo', 15]]);
verificar('chargeback: o disponível da modelo volta ao de antes', lv_saque_disponivel('livia-voguel')['creditos'], $dispAntes);
verificar('chargeback: aparece como estornado no saque', lv_saque_disponivel('livia-voguel')['estornados']['creditos'] >= 30, true);
// Negativo, não gasta mais.
verificar('saldo negativo não gasta', lv_debitar($golpe, 1, 'gorjeta', 'x', 'livia-voguel'), null);
// Compra nova paga a dívida; se ela também for estornada, só o gasto feito depois dela é descontado.
[, $r] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'cartao', 'cartao' => $cartaoOk] + $comprador); // -45 + 50 = 5
$pedidoGolpe2 = lv_pedido_por('codigo', $r['id']);
lv_debitar($golpe, 5, 'gorjeta', 'Gorjeta para Livia', 'livia-voguel');
pagar($pedidoGolpe2['unicopag_hash'], 'refunded');
chamar('webhook.php', 'POST', ['hash' => $pedidoGolpe2['unicopag_hash']]);
verificar('segundo estorno: só os 5 gastos depois da compra, sem descontar de novo os 45', $estornosDe((int) $pedidoGolpe2['id']), [['livia-voguel', 5]]);
$_SESSION = $sessaoAna;

// ============================================================================= gastos (gorjeta / privado)
$antes = lv_saldo($ana);
verificar('gorjeta debita', lv_debitar($ana, 25, 'gorjeta', 'Gorjeta para Livia'), $antes - 25);
verificar('sem saldo não debita', lv_debitar($ana, 1_000_000, 'privado', 'Privado'), null);
verificar('saldo depois dos gastos', lv_saldo($ana), $antes - 25);

// ============================================================================= app.js: eu, gastar, conta
[$s, $r] = chamar('eu.php');
verificar('eu.php logado', [$s, $r['logado'] ?? null, $r['usuario']['email'] ?? null, $r['saldo'] ?? null], [200, true, 'ana@exemplo.com.br', lv_saldo($ana)]);
verificar('eu.php: apelido padrão do e-mail', $r['usuario']['apelido'] ?? null, 'ana');
verificar('eu.php: extrato do mais novo ao mais velho', [$r['extrato'][0]['valor'] ?? null, $r['extrato'][0]['descricao'] ?? null], [-25, 'Gorjeta para Livia']);
verificar('eu.php: data em ISO', (bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) ($r['extrato'][0]['data'] ?? '')), true);

$antes = lv_saldo($ana);
[$s, $r] = chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => 10]);
verificar('gorjeta pela API', [$s, $r['saldo'] ?? null], [200, $antes - 10]);
[$s, $r] = chamar('gastar.php', 'POST', ['tipo' => 'privado', 'modelo' => 'livia-voguel', 'creditos' => 1]);
verificar('privado com a modelo offline: 409, nada cobrado', [$s, lv_saldo($ana)], [409, $antes - 10]);
lv_ao_vivo_definir('livia-voguel', true);
configurar(['PRIVADO_ATIVO' => false]);
[$s, $r] = chamar('gastar.php', 'POST', ['tipo' => 'privado', 'modelo' => 'livia-voguel', 'creditos' => 1]);
verificar('privado sem PRIVADO_ATIVO: 409, nada cobrado', [$s, $r['erro'] ?? null, lv_saldo($ana)], [409, 'O show privado ainda não está disponível.', $antes - 10]);
configurar();
[$s, $r] = chamar('gastar.php', 'POST', ['tipo' => 'privado', 'modelo' => 'livia-voguel', 'creditos' => 1]);
verificar('privado cobra o preço do servidor, não o do navegador', [$s, $r['creditos'] ?? null, $r['saldo'] ?? null], [200, 20, $antes - 30]);
[$s] = chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => -50]);
verificar('gorjeta negativa = 422', $s, 422);
[$s] = chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => '10']);
verificar('gorjeta em texto = 422', $s, 422);
[$s] = chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'outra', 'creditos' => 10]);
verificar('modelo desconhecida = 422', $s, 422);
[$s] = chamar('gastar.php', 'POST', ['tipo' => 'compra', 'modelo' => 'livia-voguel', 'creditos' => 10]);
verificar('tipo compra não existe no gastar', $s, 422);
[$s, $r] = chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => LV_GORJETA_MAXIMA]);
verificar('sem saldo = 402', [$s, $r['saldo'] ?? null], [402, $antes - 30]);
verificar('402 não mexe no saldo', lv_saldo($ana), $antes - 30);
$modelosJs = (string) file_get_contents($raiz . '/assets/js/modelos.js');
preg_match_all('/slug:\s*"([^"]+)"[\s\S]*?precoPrivado:\s*(\d+)/', $modelosJs, $mm, PREG_SET_ORDER);
verificar('modelos.php igual ao modelos.js', array_combine(array_column($mm, 1), array_map('intval', array_column($mm, 2))),
    array_map(static fn(array $m): int => $m['preco_privado'], LV_MODELOS));

[$s, $r] = chamar('conta.php', 'POST', ['apelido' => 'ana_v']);
verificar('conta: muda apelido', [$s, $r['usuario']['apelido'] ?? null], [200, 'ana_v']);
[$s] = chamar('conta.php', 'POST', ['apelido' => 'a b']);
verificar('conta: apelido inválido = 422', $s, 422);
[$s] = chamar('conta.php', 'POST', ['senha_atual' => 'errada123', 'senha_nova' => 'novasenha1']);
verificar('conta: senha atual errada = 401', $s, 401);
[$s] = chamar('conta.php', 'POST', ['senha_atual' => 'segredo123', 'senha_nova' => 'curta']);
verificar('conta: nova senha curta = 422', $s, 422);
[$s] = chamar('conta.php', 'POST', ['senha_atual' => 'segredo123', 'senha_nova' => 'novasenha1']);
verificar('conta: troca a senha', $s, 200);
$hashNovo = (string) lv_db()->query("SELECT senha_hash FROM lv_usuarios WHERE id = $ana")->fetchColumn();
verificar('conta: senha nova vale', password_verify('novasenha1', $hashNovo), true);
chamar('conta.php', 'POST', ['senha_atual' => 'novasenha1', 'senha_nova' => 'segredo123']);

// ============================================================================= isolamento entre usuários
$_SESSION = [];
chamar('cadastro.php', 'POST', ['email' => 'bia@exemplo.com.br', 'senha' => 'segredo123', 'maior18' => true]);
[$s] = chamar('status.php', 'GET', null, ['id' => $pixId]);
verificar('outro usuário não vê o pedido', $s, 404);
[$s, $r] = chamar('saldo.php');
verificar('saldo do outro usuário = 0', $r['saldo'] ?? null, 0);
[$s, $r] = chamar('eu.php');
verificar('eu.php do outro usuário: extrato vazio', [$r['usuario']['apelido'] ?? null, $r['extrato'] ?? null], ['bia', []]);
[$s, $r] = chamar('cadastro.php', 'POST', ['email' => 'apelido@exemplo.com.br', 'senha' => 'segredo123', 'maior18' => true, 'apelido' => 'x']);
verificar('cadastro com apelido inválido = 422', [$s, $r['campo'] ?? null], [422, 'apelido']);
[$s, $r] = chamar('cadastro.php', 'POST', ['email' => 'um.nome.bem.comprido.demais@exemplo.com.br', 'senha' => 'segredo123', 'maior18' => true]);
verificar('apelido padrão de e-mail comprido', [$s, $r['usuario']['apelido'] ?? null], [201, 'um_nome_bem_comprido']);
$_SESSION = [];
[$s, $r] = chamar('eu.php');
verificar('eu.php sem login = logado false', [$s, $r['logado'] ?? null], [200, false]);
[$s] = chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => 1]);
verificar('gastar sem login = 401', $s, 401);
$_SESSION = sessao_cliente((int) lv_db()->query("SELECT id FROM lv_usuarios WHERE email = 'bia@exemplo.com.br'")->fetchColumn());
[$s] = chamar('status.php', 'GET', null, ['id' => "' OR 1=1 --"]);
verificar('id malformado = 404', $s, 404);

// ============================================================================= preço de teste (R$ 1,00)
$_SESSION = [];
chamar('cadastro.php', 'POST', ['email' => 'dono@exemplo.com.br', 'senha' => 'segredo123', 'maior18' => true]);
$dono = $_SESSION['usuario_id'];
chamar('cobranca.php', 'POST', ['pacote' => 3, 'metodo' => 'pix'] + $comprador);
verificar('fora de TESTE_USUARIOS: preço cheio', end($unico['chamadas'])[2]['amount'], LV_PACOTES[3]['centavos']);
configurar(['TESTE_USUARIOS' => [$dono]]);
chamar('cobranca.php', 'POST', ['pacote' => 2, 'metodo' => 'pix'] + $comprador);
verificar('conta de TESTE_USUARIOS paga R$ 1,00', end($unico['chamadas'])[2]['amount'], 100);
$_SESSION = sessao_cliente($ana);
chamar('cobranca.php', 'POST', ['pacote' => 1, 'metodo' => 'pix'] + $comprador);
verificar('outra conta continua no preço cheio', end($unico['chamadas'])[2]['amount'], LV_PACOTES[1]['centavos']);
configurar();

// ============================================================================= sessão presa à senha
$_SESSION = [];
chamar('entrar.php', 'POST', ['email' => 'dono@exemplo.com.br', 'senha' => 'segredo123']);
$outroAparelho = $_SESSION; // a mesma conta aberta em outro lugar (ou um cookie roubado)
[$s, $r] = chamar('conta.php', 'POST', ['senha_atual' => 'segredo123', 'senha_nova' => 'novasenha456']);
verificar('troca de senha: esta sessão continua', [$s, $r['logado'] ?? null], [200, true]);
$_SESSION = $outroAparelho;
[$s, $r] = chamar('eu.php');
verificar('troca de senha: a sessão aberta com a senha antiga cai', [$s, $r['logado'] ?? null], [200, false]);
$_SESSION = ['usuario_id' => $dono];
[$s, $r] = chamar('eu.php');
verificar('sessão sem a marca da senha não vale', $r['logado'] ?? null, false);

// ============================================================================= freios
$_SESSION = [];
for ($i = 0; $i < 10; $i++) {
    chamar('entrar.php', 'POST', ['email' => 'ana@exemplo.com.br', 'senha' => 'errada' . $i]);
}
[$s] = chamar('entrar.php', 'POST', ['email' => 'ana@exemplo.com.br', 'senha' => 'segredo123']);
verificar('10 erros de login travam o e-mail', $s, 429);
[$s] = chamar('entrar.php', 'POST', ['email' => 'ana@exemplo.com.br', 'senha' => 'segredo123'], [], ['REMOTE_ADDR' => '198.51.100.30']);
verificar('erros vindos de um IP não trancam a conta em outro lugar', $s, 200);
$_SESSION = [];
lv_db()->exec("DELETE FROM lv_eventos WHERE tipo = 'login_erro'");
for ($i = 0; $i < 100; $i++) {
    chamar('entrar.php', 'POST', ['email' => 'ana@exemplo.com.br', 'senha' => 'errada' . $i], [], ['REMOTE_ADDR' => '198.51.' . intdiv($i, 200) . '.' . ($i % 200 + 1)]);
}
[$s] = chamar('entrar.php', 'POST', ['email' => 'ana@exemplo.com.br', 'senha' => 'segredo123'], [], ['REMOTE_ADDR' => '192.0.2.77']);
verificar('ataque espalhado por 100 IPs: o e-mail trava', $s, 429);
[$s] = chamar('entrar.php', 'POST', ['email' => 'ninguem@exemplo.com.br', 'senha' => 'segredo123'], [], ['REMOTE_ADDR' => '192.0.2.78']);
verificar('e-mail sem conta: 401, igual a senha errada', $s, 401);
lv_db()->exec("DELETE FROM lv_eventos WHERE tipo = 'login_erro'");

$_SESSION = sessao_cliente($ana);
lv_db()->exec("DELETE FROM lv_eventos WHERE tipo = 'cobranca'");
for ($i = 0; $i < 15; $i++) {
    lv_registrar('cobranca', (string) $ana);
}
[$s] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'cartao', 'cartao' => $cartaoOk] + $comprador);
verificar('15 cobranças por hora travam', $s, 429);

// Teste de cartões roubados: depois de LV_CARTAO_RECUSAS_DIA recusas, só Pix.
lv_db()->exec("DELETE FROM lv_eventos WHERE tipo IN ('cobranca', 'cartao_ip', 'cartao_recusado')");
for ($i = 0; $i < LV_CARTAO_RECUSAS_DIA; $i++) {
    $unico['proximo'] = [200, ['payment_status' => 'refused']];
    chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'cartao', 'cartao' => $cartaoOk] + $comprador);
}
$chamadasAntes = count($unico['chamadas']);
[$s, $r] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'cartao', 'cartao' => $cartaoOk] + $comprador);
verificar('5 cartões recusados em 24 h: 429, sem chegar à Unicopag', [$s, count($unico['chamadas'])], [429, $chamadasAntes]);
[$s] = chamar('cobranca.php', 'POST', ['pacote' => 1, 'metodo' => 'pix'] + $comprador);
verificar('com o cartão travado, Pix continua liberado', $s < 300, true);
lv_db()->exec("DELETE FROM lv_eventos WHERE tipo IN ('cobranca', 'cartao_ip', 'cartao_recusado')");
for ($i = 0; $i < LV_CARTAO_POR_HORA_IP; $i++) {
    lv_registrar('cartao_ip', '203.0.113.7');
}
[$s] = chamar('cobranca.php', 'POST', ['pacote' => 0, 'metodo' => 'cartao', 'cartao' => $cartaoOk] + $comprador);
verificar('10 tentativas de cartão no mesmo IP em 1 h (contas diferentes também): 429', $s, 429);
lv_db()->exec("DELETE FROM lv_eventos WHERE tipo IN ('cobranca', 'cartao_ip', 'cartao_recusado')");

// ============================================================================= tabelas criadas pela api/
$comandos = lv_esquema_comandos();
$creates = 0;
foreach (LV_ESQUEMAS as $arquivo) {
    $creates += preg_match_all('/CREATE TABLE IF NOT EXISTS/', (string) file_get_contents($raiz . '/api/' . $arquivo));
}
verificar('esquema: um comando por CREATE TABLE', count($comandos), $creates);
verificar('esquema: só CREATE TABLE IF NOT EXISTS', array_values(array_filter($comandos, static fn(string $c): bool => !str_starts_with($c, 'CREATE TABLE IF NOT EXISTS'))), []);
verificar('esquema: sem comentário sobrando', array_values(array_filter($comandos, static fn(string $c): bool => str_contains($c, '--'))), []);

// ============================================================================= segredo
$publicos = array_merge(glob($raiz . '/assets/js/*.js') ?: [], glob($raiz . '/*.html') ?: []);
$vazou = array_filter($publicos, static fn(string $f): bool => (bool) preg_match('/api_token|UNICO_API_KEY/', (string) file_get_contents($f)));
verificar('nenhum arquivo público fala da chave', array_values(array_map('basename', $vazou)), []);
verificar('config.php fora do Git', str_contains((string) @file_get_contents($raiz . '/.gitignore'), 'api/config.php'), true);

echo $falhas === 0 ? "OK: $total verificações\n" : "$falhas de $total verificações falharam\n";
exit($falhas === 0 ? 0 : 1);
