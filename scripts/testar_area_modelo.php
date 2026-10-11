#!/usr/bin/env php
<?php
/**
 * Testes do login da área da modelo (api/modelo-*.php): sem rede, sem MySQL e sem segredos.
 *
 * Uso:  php scripts/testar_area_modelo.php
 * Sai com código 1 se algum teste falhar. O banco é um SQLite em memória com api/schema.sql + api/schema-modelos.sql.
 */
declare(strict_types=1);

const LV_TESTE = true;
$raiz = dirname(__DIR__);
$configTeste = tempnam(sys_get_temp_dir(), 'lv-config-');
file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br'];");
putenv("LV_CONFIG_ARQUIVO=$configTeste");
register_shutdown_function(static fn() => @unlink($configTeste));
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/api/lib/base.php';
require $raiz . '/api/lib/modelo-conta.php';

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

/** SQLite em memória com as tabelas dos dois esquemas, convertidas do dialeto do MySQL só no necessário. */
function banco_novo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    foreach (['schema.sql', 'schema-modelos.sql'] as $arquivo) {
        $sql = (string) file_get_contents(dirname(__DIR__) . '/api/' . $arquivo);
        $sql = preg_replace('/--[^\n]*/', '', $sql);
        $sql = preg_replace('/\)\s*ENGINE=[^;]+;/', ');', $sql);
        $sql = str_replace('INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/,\s*KEY \w+ \([^)]*\)/', '', $sql);
        $sql = preg_replace('/,\s*CONSTRAINT \w+ FOREIGN KEY[^,)]*\)[^,)]*\([^)]*\)/', '', $sql);
        $sql = preg_replace('/UNIQUE KEY \w+ /', 'UNIQUE ', $sql);
        $pdo->exec($sql);
    }
    return $pdo;
}

/** Roda um endpoint como o Apache rodaria e devolve [status, corpo]. A sessão ($_SESSION) passa de uma chamada para a outra. */
function chamar(string $arquivo, string $metodo = 'GET', ?array $corpo = null, array $servidor = []): array
{
    // $servidor vem primeiro: o que o teste passar (IP, origem, tipo do corpo) vale sobre o padrão.
    $_SERVER = $servidor + ['REQUEST_METHOD' => $metodo, 'REMOTE_ADDR' => '203.0.113.9', 'CONTENT_TYPE' => $corpo !== null ? 'application/json' : ''];
    $GLOBALS['LV_CORPO_TESTE'] = $corpo !== null ? json_encode($corpo) : '';
    try {
        include dirname(__DIR__) . '/api/' . $arquivo;
    } catch (LvResposta $r) {
        return [$r->status, $r->corpo];
    }
    throw new LogicException("$arquivo terminou sem resposta");
}

function criar_conta(string $slug, string $email, string $senha, int $ativa = 1): int
{
    lv_db()->prepare('INSERT INTO lv_modelos_contas (slug, email, senha_hash, ativa, criado_em) VALUES (?, ?, ?, ?, ?)')
        ->execute([$slug, $email, password_hash($senha, PASSWORD_DEFAULT), $ativa, lv_agora()]);
    return (int) lv_db()->lastInsertId();
}

function recomecar(): void
{
    lv_db(banco_novo());
    $_SESSION = [];
}

$entrar = static fn(string $email, string $senha, array $servidor = []) => chamar('modelo-entrar.php', 'POST', ['email' => $email, 'senha' => $senha], $servidor);
$senhaBoa = 'senha-forte-123';

// ============================================================================= entrar e sair
recomecar();
$id = criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);

[$s, $c] = chamar('modelo-eu.php');
verificar('sem sessão: eu 200', $s, 200);
verificar('sem sessão: não logada', $c['logado'], false);

[$s, $c] = $entrar('livia@exemplo.com.br', 'errada-123');
verificar('senha errada: 401', $s, 401);
verificar('senha errada: sem sessão', $_SESSION['modelo_id'] ?? null, null);

[$s, $c] = $entrar('ninguem@exemplo.com.br', $senhaBoa);
verificar('e-mail sem conta: 401 com a mesma mensagem', [$s, $c['erro']], [401, 'E-mail ou senha incorretos.']);

[$s, $c] = $entrar('  LIVIA@exemplo.com.br ', $senhaBoa);
verificar('login certo (e-mail com maiúsculas e espaços): 200', $s, 200);
verificar('login certo: devolve a modelo', $c['modelo'], ['slug' => 'livia-voguel', 'nome' => 'Livia Voguel', 'email' => 'livia@exemplo.com.br']);
verificar('login certo: sessão de modelo', $_SESSION['modelo_id'] ?? null, $id);
verificar('login de modelo não loga como cliente', $_SESSION['usuario_id'] ?? null, null);
verificar('login certo: grava último login', lv_db()->query("SELECT ultimo_login_em IS NOT NULL FROM lv_modelos_contas WHERE id = $id")->fetchColumn(), 1);
verificar('resposta não traz o hash', array_key_exists('senha_hash', $c['modelo']), false);

[$s, $c] = chamar('modelo-eu.php');
verificar('logada: eu diz logada', [$c['logado'], $c['modelo']['slug']], [true, 'livia-voguel']);

$_SESSION['usuario_id'] = 42; // a mesma pessoa também logada como cliente
[$s] = chamar('modelo-sair.php', 'POST');
verificar('sair: 200', $s, 200);
verificar('sair: tira a sessão de modelo', $_SESSION['modelo_id'] ?? null, null);
verificar('sair: mantém o login de cliente', $_SESSION['usuario_id'] ?? null, 42);
[, $c] = chamar('modelo-eu.php');
verificar('depois de sair: não logada', $c['logado'], false);

// ============================================================================= separação cliente × modelo
recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
$_SESSION['usuario_id'] = 7; // só cliente
[, $c] = chamar('modelo-eu.php');
verificar('cliente logado não é modelo', $c['logado'], false);
[$s, $c] = chamar('modelo-senha.php', 'POST', ['senha_atual' => $senhaBoa, 'senha_nova' => 'outra-senha-456']);
verificar('cliente não troca senha de modelo: 401', [$s, $c['login_modelo'] ?? null], [401, true]);

// ============================================================================= sessão vencida e conta bloqueada
recomecar();
$id = criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
$entrar('livia@exemplo.com.br', $senhaBoa);
$_SESSION['modelo_desde'] = time() - LV_MODELO_SESSAO_MAXIMA - 1;
[, $c] = chamar('modelo-eu.php');
verificar('sessão de mais de 12 h vence', $c['logado'], false);
verificar('sessão vencida é limpa', $_SESSION['modelo_id'] ?? null, null);

$entrar('livia@exemplo.com.br', $senhaBoa);
lv_db()->exec("UPDATE lv_modelos_contas SET ativa = 0 WHERE id = $id");
[, $c] = chamar('modelo-eu.php');
verificar('conta bloqueada perde a sessão aberta', $c['logado'], false);
[$s] = $entrar('livia@exemplo.com.br', $senhaBoa);
verificar('conta bloqueada não entra, mesmo com a senha certa', $s, 401);

lv_db()->exec("DELETE FROM lv_modelos_contas WHERE id = $id");
$_SESSION['modelo_id'] = $id;
$_SESSION['modelo_desde'] = time();
[, $c] = chamar('modelo-eu.php');
verificar('conta apagada perde a sessão', $c['logado'], false);

// ============================================================================= freios e origem
recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
for ($i = 0; $i < LV_MODELO_ERROS_LOGIN; $i++) {
    $entrar('livia@exemplo.com.br', 'errada-' . $i);
}
[$s] = $entrar('livia@exemplo.com.br', $senhaBoa);
verificar('5 erros no e-mail: espera, mesmo com a senha certa', $s, 429);
verificar('freio não abre sessão', $_SESSION['modelo_id'] ?? null, null);

recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
for ($i = 0; $i < 3 * LV_MODELO_ERROS_LOGIN; $i++) {
    $entrar("tentativa$i@exemplo.com.br", 'qualquer-coisa');
}
[$s] = $entrar('livia@exemplo.com.br', $senhaBoa);
verificar('15 erros no mesmo IP com e-mails diferentes: espera', $s, 429);
[$s] = $entrar('livia@exemplo.com.br', $senhaBoa, ['REMOTE_ADDR' => '198.51.100.1']);
verificar('outro IP ainda entra', $s, 200);

recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
[$s] = $entrar('livia@exemplo.com.br', $senhaBoa, ['HTTP_ORIGIN' => 'https://site-estranho.com']);
verificar('login vindo de outro site: 403', $s, 403);
[$s] = $entrar('livia@exemplo.com.br', $senhaBoa, ['HTTP_ORIGIN' => 'https://www.exemplo.com.br']);
verificar('login vindo do www do próprio site: 200', $s, 200);
[$s] = chamar('modelo-sair.php', 'POST', null, ['HTTP_ORIGIN' => 'https://site-estranho.com']);
verificar('sair vindo de outro site: 403', $s, 403);
verificar('sair recusado mantém a sessão', isset($_SESSION['modelo_id']), true);
[$s] = chamar('modelo-entrar.php', 'GET');
verificar('entrar por GET: 405', $s, 405);
[$s] = chamar('modelo-entrar.php', 'POST', null, ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
verificar('entrar por formulário comum: 415', $s, 415);

// ============================================================================= trocar senha
recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
[$s] = chamar('modelo-senha.php', 'POST', ['senha_atual' => $senhaBoa, 'senha_nova' => 'nova-senha-789']);
verificar('trocar senha sem login: 401', $s, 401);

$entrar('livia@exemplo.com.br', $senhaBoa);
[$s, $c] = chamar('modelo-senha.php', 'POST', ['senha_atual' => 'errada-000', 'senha_nova' => 'nova-senha-789']);
verificar('senha atual errada: 400 no campo certo', [$s, $c['campo']], [400, 'senha_atual']);
[$s, $c] = chamar('modelo-senha.php', 'POST', ['senha_atual' => $senhaBoa, 'senha_nova' => 'curta']);
verificar('senha nova curta: 400', [$s, $c['campo']], [400, 'senha_nova']);
[$s, $c] = chamar('modelo-senha.php', 'POST', ['senha_atual' => $senhaBoa, 'senha_nova' => $senhaBoa]);
verificar('senha nova igual à atual: 400', [$s, $c['campo']], [400, 'senha_nova']);
[$s] = chamar('modelo-senha.php', 'POST', ['senha_atual' => $senhaBoa, 'senha_nova' => 'nova-senha-789']);
verificar('troca de senha: 200', $s, 200);
verificar('troca de senha: continua logada', isset($_SESSION['modelo_id']), true);

$_SESSION = [];
[$s] = $entrar('livia@exemplo.com.br', $senhaBoa);
verificar('senha antiga não entra mais', $s, 401);
[$s] = $entrar('livia@exemplo.com.br', 'nova-senha-789');
verificar('senha nova entra', $s, 200);

for ($i = 0; $i < 5; $i++) {
    chamar('modelo-senha.php', 'POST', ['senha_atual' => 'chute-' . $i, 'senha_nova' => 'outra-senha-000']);
}
[$s] = chamar('modelo-senha.php', 'POST', ['senha_atual' => 'nova-senha-789', 'senha_nova' => 'outra-senha-000']);
verificar('5 chutes da senha atual: espera', $s, 429);

// ============================================================================= ganhos
// Meia-noite de Brasília: 02:00 UTC do dia 11 ainda é dia 10 lá (23:00), e o dia 10 começou às 03:00 UTC.
$t = (int) strtotime('2026-10-11 02:00:00 UTC');
verificar('hoje no fuso de Brasília', lv_modelo_inicio('today', $t), '2026-10-10 03:00:00');
verificar('início do mês no fuso de Brasília', lv_modelo_inicio('first day of this month midnight', $t), '2026-10-01 03:00:00');
verificar('início do mês passado', lv_modelo_inicio('first day of last month midnight', $t), '2026-09-01 03:00:00');

recomecar();
$idModelo = criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
$pdo = lv_db();
$pdo->exec("INSERT INTO lv_usuarios (email, apelido, senha_hash, criado_em) VALUES ('fa@exemplo.com.br', 'fa_carioca', 'x', '2026-01-01 00:00:00')");
$fa = (int) $pdo->lastInsertId();
$lancar = static function (int $creditos, string $motivo, ?string $modelo, string $quando) use ($pdo, $fa): void {
    $pdo->prepare('INSERT INTO lv_extrato (usuario_id, creditos, motivo, modelo, descricao, criado_em) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$fa, $creditos, $motivo, $modelo, $motivo, $quando]);
};
$hoje = lv_modelo_inicio('today');
$seteDias = lv_modelo_inicio('today -6 days');
$mes = lv_modelo_inicio('first day of this month midnight');
$mesPassado = lv_modelo_inicio('first day of last month midnight');
$menos1s = static fn(string $t): string => gmdate('Y-m-d H:i:s', (int) strtotime($t . ' UTC') - 1);
$recebidos = [ // [créditos recebidos, motivo, quando]
    [10, 'gorjeta', $hoje],                 // hoje (bem na meia-noite)
    [20, 'privado', $hoje],                 // hoje, 1 minuto de privado
    [5, 'gorjeta', $menos1s($hoje)],        // ontem
    [7, 'gorjeta', $menos1s($seteDias)],    // fora dos últimos 7 dias
    [30, 'gorjeta', $menos1s($mes)],        // mês passado
    [100, 'gorjeta', $menos1s($mesPassado)], // antes do mês passado
];
foreach ($recebidos as [$c, $motivo, $quando]) {
    $lancar(-$c, $motivo, 'livia-voguel', $quando);
}
$lancar(130, 'compra', null, $hoje);               // compra do cliente: não é ganho
$lancar(-50, 'gorjeta', 'outra-modelo', $hoje);    // gorjeta para outra modelo: não é dela
$somar = static fn(callable $filtro): int => array_sum(array_map(static fn($r) => $r[0], array_filter($recebidos, $filtro)));
$repasse = LV_MODELO_REPASSE_PADRAO;

$_SESSION = [];
$_SESSION['usuario_id'] = $fa;
[$s, $c] = chamar('modelo-ganhos.php');
verificar('ganhos sem login de modelo (cliente logado): 401', [$s, $c['login_modelo'] ?? null], [401, true]);

$entrar('livia@exemplo.com.br', $senhaBoa);
$_GET = [];
[$s, $g] = chamar('modelo-ganhos.php');
verificar('ganhos: 200', $s, 200);
verificar('ganhos: repasse padrão', $g['repasse_centavos'], $repasse);
verificar('ganhos hoje', $g['resumo']['hoje'], ['creditos' => 30, 'centavos' => 30 * $repasse, 'quantidade' => 2]);
$mesEsperado = $somar(static fn($r) => $r[2] >= $mes);
verificar('ganhos no mês', $g['resumo']['mes']['creditos'], $mesEsperado);
verificar('ganhos desde o início', $g['resumo']['total']['creditos'], 172);
verificar('privado no mês (minutos = quantidade)', [$g['resumo']['privado_mes']['creditos'], $g['resumo']['privado_mes']['quantidade']], [20, 1]);
verificar('período padrão = 7 dias', $g['periodo']['nome'], '7d');
// Conta pelos dados, não por número fixo: do dia 1 ao 6 do mês, a gorjeta "do mês passado" cai nos últimos 7 dias.
verificar('7 dias: soma', $g['periodo']['soma']['creditos'], $somar(static fn($r) => $r[2] >= $seteDias));
verificar('7 dias: lançamentos', count($g['periodo']['lancamentos']), count(array_filter($recebidos, static fn($r) => $r[2] >= $seteDias)));
verificar('lançamento mostra o apelido', $g['periodo']['lancamentos'][0]['cliente'], 'fa_carioca');
verificar('lançamento nunca mostra o e-mail', str_contains(json_encode($g), 'fa@exemplo'), false);
verificar('lançamento: créditos positivos e em centavos', [$g['ultimas'][0]['creditos'] > 0, $g['ultimas'][0]['centavos'] === $g['ultimas'][0]['creditos'] * $repasse], [true, true]);
verificar('últimas: no máximo 5', count($g['ultimas']), 5);

$_GET = ['periodo' => 'mes_passado'];
[, $g] = chamar('modelo-ganhos.php');
verificar('mês passado: soma', $g['periodo']['soma']['creditos'], $somar(static fn($r) => $r[2] >= $mesPassado && $r[2] < $mes));
verificar('mês passado: tem fim', $g['periodo']['ate'] !== null, true);

$_GET = ['periodo' => 'qualquer-coisa'];
[, $g] = chamar('modelo-ganhos.php');
verificar('período inválido vira 7 dias', $g['periodo']['nome'], '7d');
$_GET = ['periodo' => ['7d']];
[$s] = chamar('modelo-ganhos.php');
verificar('período em lista não quebra', $s, 200);
$_GET = [];

file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br', 'MODELO_REPASSE_CENTAVOS' => 35];");
[, $g] = chamar('modelo-ganhos.php');
verificar('repasse configurável no config.php', [$g['repasse_centavos'], $g['resumo']['hoje']['centavos']], [35, 30 * 35]);
file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br'];");

[$s] = chamar('modelo-ganhos.php', 'POST', []);
verificar('ganhos por POST: 405', $s, 405);

// ============================================================================= espelho dos slugs
// Sem comentários: o exemplo "nova-modelo" comentado no fim do arquivo não é uma modelo.
$js = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', (string) file_get_contents($raiz . '/assets/js/modelos.js'));
preg_match_all('/slug:\s*"([^"]+)"/', $js, $m);
verificar('toda modelo de modelos.js existe em LV_MODELOS (para criar a conta)', array_values(array_diff($m[1], array_keys(LV_MODELOS))), []);

echo $falhas === 0 ? "OK: $total verificações passaram.\n" : "FALHAS: $falhas de $total.\n";
exit($falhas === 0 ? 0 : 1);
