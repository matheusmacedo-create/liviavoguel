<?php
/**
 * Testes do login da área da modelo (api/modelo-*.php): sem rede, sem MySQL e sem segredos.
 *
 * Uso:  php scripts/testar_area_modelo.php
 * Sai com código 1 se algum teste falhar. O banco é um SQLite em memória com api/schema.sql + api/schema-modelos.sql.
 */
declare(strict_types=1);

// Só pela linha de comando: se o arquivo for aberto pelo navegador, não faz nada.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const LV_TESTE = true;
$raiz = dirname(__DIR__);
$configTeste = tempnam(sys_get_temp_dir(), 'lv-config-');
file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br', 'MODELO_ESPERA_SAQUE_DIAS' => 0];");
putenv("LV_CONFIG_ARQUIVO=$configTeste");
register_shutdown_function(static fn() => @unlink($configTeste));
$_SERVER['REQUEST_METHOD'] = 'CLI';
require $raiz . '/api/lib/base.php';
require $raiz . '/api/lib/modelo-conta.php';
require $raiz . '/api/lib/chat.php';
require $raiz . '/api/lib/saques.php';
require $raiz . '/api/lib/fotos.php';
require $raiz . '/api/lib/equipe.php';

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

/** Sessão de cliente como o login (lv_logar) deixa: com a marca da senha atual. */
function sessao_cliente(int $id): array
{
    $hash = (string) lv_db()->query('SELECT senha_hash FROM lv_usuarios WHERE id = ' . $id)->fetchColumn();
    return ['usuario_id' => $id, 'usuario_marca' => lv_marca_senha($hash)];
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
[$s] = $entrar('livia@exemplo.com.br', $senhaBoa, ['REMOTE_ADDR' => '198.51.100.20']);
verificar('erros vindos de um IP não trancam a modelo fora em outro lugar', $s, 200);

recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
for ($i = 0; $i < LV_MODELO_ERROS_LOGIN - 1; $i++) {
    $entrar('livia@exemplo.com.br', 'errada-' . $i);
}
$entrar('livia@exemplo.com.br', $senhaBoa);
$_SESSION = [];
for ($i = 0; $i < LV_MODELO_ERROS_LOGIN - 1; $i++) {
    $entrar('livia@exemplo.com.br', 'errada-de-novo-' . $i);
}
[$s] = $entrar('livia@exemplo.com.br', $senhaBoa);
verificar('acertar a senha zera os erros daquele IP', $s, 200);

recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
for ($i = 0; $i < LV_LOGIN_FATOR_EMAIL * LV_MODELO_ERROS_LOGIN; $i++) {
    $entrar('livia@exemplo.com.br', 'errada-' . $i, ['REMOTE_ADDR' => '198.51.100.' . ($i + 1)]);
}
[$s] = $entrar('livia@exemplo.com.br', $senhaBoa, ['REMOTE_ADDR' => '192.0.2.200']);
verificar('ataque espalhado por muitos IPs: o e-mail trava', $s, 429);

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
$outroAparelho = $_SESSION; // a mesma conta aberta em outro lugar (computador emprestado, cookie roubado)
[$s] = chamar('modelo-senha.php', 'POST', ['senha_atual' => $senhaBoa, 'senha_nova' => 'nova-senha-789']);
verificar('troca de senha: 200', $s, 200);
verificar('troca de senha: continua logada', isset($_SESSION['modelo_id']), true);
[, $c] = chamar('modelo-eu.php');
verificar('troca de senha: esta sessão segue valendo', $c['logado'], true);
$estaSessao = $_SESSION;
$_SESSION = $outroAparelho;
[, $c] = chamar('modelo-eu.php');
verificar('troca de senha: a sessão aberta com a senha antiga cai', $c['logado'], false);
// Redefinição pela equipe (scripts/criar_conta_modelo.php grava um hash novo): derruba todas as sessões.
$_SESSION = $estaSessao;
lv_db()->prepare('UPDATE lv_modelos_contas SET senha_hash = ? WHERE slug = ?')->execute([password_hash('redefinida-123', PASSWORD_DEFAULT), 'livia-voguel']);
[, $c] = chamar('modelo-eu.php');
verificar('senha redefinida pela equipe: a sessão aberta cai', $c['logado'], false);
lv_db()->prepare('UPDATE lv_modelos_contas SET senha_hash = ? WHERE slug = ?')->execute([password_hash('nova-senha-789', PASSWORD_DEFAULT), 'livia-voguel']);

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

$_SESSION = sessao_cliente($fa);
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

file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br', 'MODELO_ESPERA_SAQUE_DIAS' => 0, 'MODELO_REPASSE_CENTAVOS' => 35];");
[, $g] = chamar('modelo-ganhos.php');
verificar('repasse configurável no config.php', [$g['repasse_centavos'], $g['resumo']['hoje']['centavos']], [35, 30 * 35]);
file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br', 'MODELO_ESPERA_SAQUE_DIAS' => 0];");

[$s] = chamar('modelo-ganhos.php', 'POST', []);
verificar('ganhos por POST: 405', $s, 405);

// ============================================================================= ao vivo
recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
$_GET = [];
$aoVivo = static fn() => chamar('ao-vivo.php')[1]['modelos']['livia-voguel'];

verificar('ao vivo: começa offline', $aoVivo(), ['online' => false, 'desde' => null]);
verificar('ao vivo: lista só modelos de LV_MODELOS', array_keys(chamar('ao-vivo.php')[1]['modelos']), array_keys(LV_MODELOS));

$_SESSION = ['usuario_id' => 7];
[$s, $c] = chamar('modelo-ao-vivo.php', 'POST', ['online' => true]);
verificar('cliente não liga a sala: 401', [$s, $c['login_modelo'] ?? null], [401, true]);
verificar('cliente não liga a sala: continua offline', $aoVivo()['online'], false);

$_SESSION = [];
$entrar('livia@exemplo.com.br', $senhaBoa);
[$s] = chamar('modelo-ao-vivo.php', 'POST', ['online' => 'sim']);
verificar('online que não é true/false: 422', $s, 422);
[$s] = chamar('modelo-ao-vivo.php', 'POST', ['online' => true], ['HTTP_ORIGIN' => 'https://site-estranho.com']);
verificar('ligar vindo de outro site: 403', $s, 403);

[$s, $c] = chamar('modelo-ao-vivo.php', 'POST', ['online' => true]);
verificar('modelo liga a sala: 200 e online', [$s, $c['online']], [200, true]);
verificar('ligar marca desde quando', is_string($c['desde']), true);
verificar('público vê ao vivo', $aoVivo()['online'], true);

lv_db()->exec("UPDATE lv_modelos_ao_vivo SET desde = '2026-01-01 10:00:00'");
[, $c] = chamar('modelo-ao-vivo.php', 'POST', ['online' => true]);
verificar('aviso do painel mantém o desde', $c['desde'], '2026-01-01T10:00:00Z');

$velho = gmdate('Y-m-d H:i:s', time() - LV_AO_VIVO_VALIDADE - 5);
lv_db()->exec("UPDATE lv_modelos_ao_vivo SET visto_em = '$velho'");
verificar('sem aviso do painel por 5 min: offline', $aoVivo(), ['online' => false, 'desde' => null]);
[, $c] = chamar('modelo-ao-vivo.php', 'POST', ['online' => true]);
verificar('religar depois de vencer: online de novo, desde novo', [$c['online'], $c['desde'] !== '2026-01-01T10:00:00Z'], [true, true]);

[$s, $c] = chamar('modelo-ao-vivo.php', 'POST', ['online' => false]);
verificar('modelo desliga: offline', [$s, $c['online'], $c['desde']], [200, false, null]);
verificar('público vê offline', $aoVivo()['online'], false);
[$s, $c] = chamar('modelo-ao-vivo.php', 'POST', ['online' => false]);
verificar('desligar duas vezes não quebra', [$s, $c['online']], [200, false]);
verificar('uma linha por modelo', (int) lv_db()->query('SELECT COUNT(*) FROM lv_modelos_ao_vivo')->fetchColumn(), 1);
verificar('ligar/desligar fica registrado', (int) lv_db()->query("SELECT COUNT(*) FROM lv_eventos WHERE tipo = 'ao_vivo'")->fetchColumn(), 3);

[$s] = chamar('ao-vivo.php', 'POST', []);
verificar('ao-vivo.php por POST: 405', $s, 405);

// ============================================================================= perfil
recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
$_GET = [];
$perfilOk = ['nome' => 'Livia Voguel', 'cidade' => 'Niterói, RJ', 'bio' => "Oi!\n\nSou a   Livia.", 'tags' => ['Dança', ' dança ', '', 'Cosplay'], 'idiomas' => ['Português']];

verificar('perfis: começa vazio', chamar('perfis.php')[1]['modelos'], []);
$_SESSION = ['usuario_id' => 7];
[$s, $c] = chamar('modelo-perfil.php', 'POST', $perfilOk);
verificar('cliente não edita perfil: 401', [$s, $c['login_modelo'] ?? null], [401, true]);

$_SESSION = [];
$entrar('livia@exemplo.com.br', $senhaBoa);
[$s, $c] = chamar('modelo-perfil.php', 'POST', $perfilOk);
verificar('salvar perfil: 200', $s, 200);
verificar('bio sem espaços e quebras repetidos', $c['perfil']['bio'], 'Oi! Sou a Livia.');
verificar('interesses sem vazios e sem repetidos (maiúsculas não contam)', $c['perfil']['tags'], ['Dança', 'Cosplay']);
$pub = chamar('perfis.php')[1]['modelos']['livia-voguel'];
verificar('público vê o perfil salvo', [$pub['nome'], $pub['cidade'], $pub['idiomas']], ['Livia Voguel', 'Niterói, RJ', ['Português']]);

$_POST = [];
[$s] = chamar('modelo-perfil.php', 'POST', $perfilOk);
verificar('salvar de novo sem mudar nada: 200', $s, 200);
verificar('uma linha por modelo', (int) lv_db()->query('SELECT COUNT(*) FROM lv_modelos_perfil')->fetchColumn(), 1);
[, $c] = chamar('modelo-perfil.php', 'POST', ['cidade' => 'Rio de Janeiro, RJ'] + $perfilOk);
verificar('editar troca o que mudou', $c['perfil']['cidade'], 'Rio de Janeiro, RJ');

$erros = [
    'nome curto' => [['nome' => 'L'], 'nome'],
    'nome longo' => [['nome' => str_repeat('a', 41)], 'nome'],
    'nome que não é texto' => [['nome' => ['x']], 'nome'],
    'bio longa' => [['bio' => str_repeat('a', 601)], 'bio'],
    'cidade longa' => [['cidade' => str_repeat('a', 61)], 'cidade'],
    'interesse longo' => [['tags' => [str_repeat('a', 21)]], 'tags'],
    '11 interesses' => [['tags' => array_map(static fn($i) => "t$i", range(1, 11))], 'tags'],
    'interesses fora de lista' => [['tags' => 'Dança, Cosplay'], 'tags'],
    'interesse que não é texto' => [['tags' => [['x']]], 'tags'],
    'sem idioma' => [['idiomas' => []], 'idiomas'],
    '6 idiomas' => [['idiomas' => ['a1', 'a2', 'a3', 'a4', 'a5', 'a6']], 'idiomas'],
];
foreach ($erros as $nome => [$troca, $campo]) {
    [$s, $c] = chamar('modelo-perfil.php', 'POST', $troca + $perfilOk);
    verificar("perfil inválido ($nome): 422 no campo $campo", [$s, $c['campo'] ?? null], [422, $campo]);
}
verificar('perfil inválido não muda o salvo', chamar('perfis.php')[1]['modelos']['livia-voguel']['cidade'], 'Rio de Janeiro, RJ');

$xss = ['nome' => '<img src=x onerror=alert(1)>', 'bio' => '<script>alert(1)</script>'] + $perfilOk;
[, $c] = chamar('modelo-perfil.php', 'POST', $xss);
verificar('HTML é guardado como texto (a tela escapa ao mostrar)', $c['perfil']['nome'], '<img src=x onerror=alert(1)>');
verificar('JSON da resposta não quebra com HTML', json_decode(json_encode($c), true)['perfil']['bio'], '<script>alert(1)</script>');

[$s] = chamar('modelo-perfil.php', 'POST', $perfilOk, ['HTTP_ORIGIN' => 'https://site-estranho.com']);
verificar('editar perfil vindo de outro site: 403', $s, 403);
[$s] = chamar('perfis.php', 'POST', []);
verificar('perfis.php por POST: 405', $s, 405);
lv_db()->exec("INSERT INTO lv_modelos_perfil (slug, nome, cidade, bio, tags, idiomas, atualizado_em) VALUES ('removida', 'X', '', '', '[]', '[]', '2026-01-01 00:00:00')");
verificar('perfil de modelo fora de LV_MODELOS não aparece', array_keys(chamar('perfis.php')[1]['modelos']), ['livia-voguel']);

// ============================================================================= configurar sala
recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
$_GET = [];
$salaPub = static fn() => chamar('salas.php')[1]['modelos']['livia-voguel'];
$padrao = LV_MODELOS['livia-voguel']['preco_privado'];
verificar('sala sem configuração: preço padrão e sem meta', [$salaPub()['preco_privado'], $salaPub()['meta'], $salaPub()['salva']], [$padrao, null, false]);
verificar('preço do privado sem configuração = padrão', lv_preco_privado('livia-voguel'), $padrao);

$salaOk = ['preco_privado' => 35, 'meta_titulo' => 'Dança especial', 'meta_alvo' => 300, 'boas_vindas' => 'Oi, amor! 💜', 'bloqueadas' => ['WhatsApp', 'whatsapp', 'telefone']];
$_SESSION = ['usuario_id' => 7];
[$s] = chamar('modelo-sala.php', 'POST', $salaOk);
verificar('cliente não configura a sala: 401', $s, 401);

$_SESSION = [];
$entrar('livia@exemplo.com.br', $senhaBoa);
[$s, $c] = chamar('modelo-sala.php', 'POST', $salaOk);
verificar('salvar sala: 200', $s, 200);
verificar('salvar sala: devolve a configuração', [$c['sala']['preco_privado'], $c['sala']['meta'], $c['sala']['boas_vindas']],
    [35, ['titulo' => 'Dança especial', 'alvo' => 300, 'atual' => 0], 'Oi, amor! 💜']);
verificar('palavras bloqueadas em minúsculas e sem repetidas', $c['sala']['bloqueadas'], ['whatsapp', 'telefone']);
verificar('público vê a sala salva', $salaPub()['preco_privado'], 35);

// A cobrança do privado passa a usar o preço novo (o navegador não escolhe).
$pdo = lv_db();
$pdo->exec("INSERT INTO lv_usuarios (email, apelido, senha_hash, criado_em) VALUES ('fa@exemplo.com.br', 'fa_1', 'x', '2026-01-01 00:00:00')");
$fa = (int) $pdo->lastInsertId();
lv_lancar($fa, 500, 'compra', null, 'Pacote de 500 créditos');
$sessaoModelo = $_SESSION;
$_SESSION = sessao_cliente($fa);
file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br', 'MODELO_ESPERA_SAQUE_DIAS' => 0, 'PRIVADO_ATIVO' => true];");
lv_ao_vivo_definir('livia-voguel', true);
[$s, $c] = chamar('gastar.php', 'POST', ['tipo' => 'privado', 'modelo' => 'livia-voguel', 'creditos' => 1]);
verificar('privado cobra o preço que a modelo definiu', [$s, $c['creditos'], $c['saldo']], [200, 35, 465]);
lv_ao_vivo_definir('livia-voguel', false);
file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br', 'MODELO_ESPERA_SAQUE_DIAS' => 0];");

// Meta conta só as gorjetas depois que ela foi definida.
[, $c] = chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => 40]);
verificar('gorjeta entra na meta', $salaPub()['meta']['atual'], 40);
verificar('privado não entra na meta', $salaPub()['meta']['atual'], 40);
$_SESSION = $sessaoModelo;
[, $c] = chamar('modelo-sala.php', 'POST', ['preco_privado' => 30, 'boas_vindas' => 'Oi!'] + $salaOk);
verificar('mudar só o preço mantém a meta e o progresso', [$c['sala']['preco_privado'], $c['sala']['meta']['atual']], [30, 40]);
[, $c] = chamar('modelo-sala.php', 'POST', ['meta_alvo' => 500] + $salaOk);
verificar('trocar o valor da meta recomeça do zero', $c['sala']['meta'], ['titulo' => 'Dança especial', 'alvo' => 500, 'atual' => 0]);
[, $c] = chamar('modelo-sala.php', 'POST', ['meta_titulo' => '', 'meta_alvo' => null] + $salaOk);
verificar('título e valor vazios = sem meta', $c['sala']['meta'], null);
verificar('uma linha por modelo', (int) $pdo->query('SELECT COUNT(*) FROM lv_modelos_sala')->fetchColumn(), 1);

$errosSala = [
    'preço zero' => [['preco_privado' => 0], 'preco_privado'],
    'preço acima do teto' => [['preco_privado' => 501], 'preco_privado'],
    'preço em texto' => [['preco_privado' => '20'], 'preco_privado'],
    'preço quebrado' => [['preco_privado' => 20.5], 'preco_privado'],
    'meta sem título' => [['meta_titulo' => ''], 'meta_titulo'],
    'meta sem valor' => [['meta_alvo' => null], 'meta_alvo'],
    'meta zero' => [['meta_alvo' => 0], 'meta_alvo'],
    'título da meta longo' => [['meta_titulo' => str_repeat('a', 61)], 'meta_titulo'],
    'boas-vindas longa' => [['boas_vindas' => str_repeat('a', 121)], 'boas_vindas'],
    '31 palavras bloqueadas' => [['bloqueadas' => array_map(static fn($i) => "p$i", range(1, 31))], 'bloqueadas'],
    'palavras fora de lista' => [['bloqueadas' => 'whatsapp'], 'bloqueadas'],
];
foreach ($errosSala as $nome => [$troca, $campo]) {
    [$s, $c] = chamar('modelo-sala.php', 'POST', $troca + $salaOk);
    verificar("sala inválida ($nome): 422 no campo $campo", [$s, $c['campo'] ?? null], [422, $campo]);
}
verificar('sala inválida não muda o preço salvo', lv_preco_privado('livia-voguel'), 35);
[$s] = chamar('modelo-sala.php', 'POST', $salaOk, ['HTTP_ORIGIN' => 'https://site-estranho.com']);
verificar('configurar sala vindo de outro site: 403', $s, 403);
[$s] = chamar('salas.php', 'POST', []);
verificar('salas.php por POST: 405', $s, 405);

// ============================================================================= chat
recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
$pdo = lv_db();
$pdo->exec("INSERT INTO lv_usuarios (email, apelido, senha_hash, criado_em) VALUES ('fa@exemplo.com.br', 'fa_1', 'x', '2026-01-01 00:00:00')");
$fa = (int) $pdo->lastInsertId();
lv_lancar($fa, 500, 'compra', null, 'Pacote de 500 créditos');
$lerChat = static function (array $get = []): array { $_GET = ['modelo' => 'livia-voguel'] + $get; $r = chamar('chat.php'); $_GET = []; return $r; };
$enviar = static fn(string $texto) => chamar('chat-enviar.php', 'POST', ['modelo' => 'livia-voguel', 'texto' => $texto]);
// Libera o freio de intervalo entre mensagens (empurra os registros de chat 3 s para trás).
$esperar = static fn() => $pdo->exec("UPDATE lv_eventos SET criado_em = '" . gmdate('Y-m-d H:i:s', time() - 3) . "' WHERE tipo = 'chat' AND criado_em > '" . gmdate('Y-m-d H:i:s', time() - 3) . "'");

$_GET = ['modelo' => 'nao-existe'];
[$s] = chamar('chat.php');
verificar('chat de modelo que não existe: 422', $s, 422);
[$s, $c] = $lerChat();
verificar('chat começa vazio', [$s, $c['mensagens'], $c['ultimo_id']], [200, [], 0]);

$_SESSION = [];
[$s, $c] = $enviar('oi');
verificar('mandar mensagem sem login: 401', [$s, $c['login'] ?? null], [401, true]);
$_SESSION = sessao_cliente($fa);
[$s] = $enviar('oi');
verificar('mandar mensagem com a modelo offline: 409', $s, 409);

lv_ao_vivo_definir('livia-voguel', true);
[$s, $c] = $enviar("  oi   Livia!  ");
verificar('mensagem enviada: 200', $s, 200);
verificar('mensagem guardada sem espaços sobrando e com o apelido', [$c['mensagem']['texto'], $c['mensagem']['apelido']], ['oi Livia!', 'fa_1']);
[$s] = $enviar('de novo');
verificar('duas mensagens em menos de 2 s: 429', $s, 429);
$esperar();
[$s] = $enviar('<b>linda</b>');
verificar('depois de 2 s: manda de novo', $s, 200);
[, $c] = $lerChat();
verificar('todos leem as mensagens, na ordem', array_map(static fn($m) => $m['texto'], $c['mensagens']), ['oi Livia!', '<b>linda</b>']);
$primeiro = $c['mensagens'][0]['id'];
[, $c] = $lerChat(['depois' => (string) $primeiro]);
verificar('com "depois", só as novas', array_map(static fn($m) => $m['texto'], $c['mensagens']), ['<b>linda</b>']);
[, $c] = $lerChat(['depois' => 'abc']);
verificar('"depois" inválido vira do começo', count($c['mensagens']), 2);

$esperar();
[$s, $c] = $enviar(str_repeat('a', 201));
verificar('mensagem com mais de 200 caracteres: 422', $s, 422);
[$s] = $enviar('   ');
verificar('mensagem vazia: 422', $s, 422);

$_SESSION = [];
$entrar('livia@exemplo.com.br', $senhaBoa);
chamar('modelo-sala.php', 'POST', ['preco_privado' => 20, 'meta_titulo' => '', 'meta_alvo' => null, 'boas_vindas' => '', 'bloqueadas' => ['telefone', 'WhatsApp']]);
$_SESSION = sessao_cliente($fa);
$esperar();
[$s, $c] = $enviar('me passa o TELEFÔNE?');
verificar('palavra bloqueada com maiúscula e acento: 422', [$s, $c['campo'] ?? null], [422, 'texto']);
[$s] = $enviar('meu whats app');
verificar('palavra separada não é a bloqueada', $s, 200);

for ($i = 0; $i < LV_CHAT_POR_MINUTO; $i++) {
    $esperar();
    lv_registrar('chat', 'u:' . $fa);
}
$esperar();
[$s] = $enviar('mais uma');
verificar('mais de 20 mensagens por minuto: 429', $s, 429);
$pdo->exec("DELETE FROM lv_eventos WHERE tipo = 'chat'");

// Freio sem corrida: a mesma conta em outra aba acabou de mandar (o registro dela já está lá).
lv_registrar('chat', 'u:' . $fa);
[$s] = $enviar('ao mesmo tempo');
verificar('duas mensagens em paralelo da mesma conta: uma é recusada', $s, 429);
$pdo->exec("DELETE FROM lv_eventos WHERE tipo = 'chat'");
// Freio por IP: várias contas no mesmo lugar não somam um flood.
for ($i = 0; $i < LV_CHAT_POR_MINUTO_IP; $i++) {
    lv_registrar('chat', 'ip:203.0.113.9');
}
$esperar();
[$s] = $enviar('mais uma do mesmo IP');
verificar('mais de 60 mensagens por minuto do mesmo IP: 429', $s, 429);
$pdo->exec("DELETE FROM lv_eventos WHERE tipo = 'chat'");
// Apelido com palavra bloqueada pela modelo (ex.: telefone no apelido) aparece como "anônimo".
$pdo->exec("UPDATE lv_usuarios SET apelido = 'meu_telefone_21' WHERE id = $fa");
[$s, $c] = $enviar('oi gente');
verificar('apelido com palavra bloqueada aparece como anônimo', [$s, $c['mensagem']['apelido'] ?? null, array_slice($lerChat()[1]['mensagens'], -1)[0]['apelido']], [200, 'anônimo', 'anônimo']);
$pdo->exec("UPDATE lv_usuarios SET apelido = 'fa_1' WHERE id = $fa");
$pdo->exec("DELETE FROM lv_eventos WHERE tipo = 'chat'");

// Gorjeta aparece no chat com o recado; privado não aparece.
$antes = lv_saldo($fa);
[$s, $c] = chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => 15, 'recado' => 'Linda demais']);
verificar('gorjeta com recado: 200', $s, 200);
[, $c] = $lerChat();
verificar('gorjeta aparece no chat com valor e recado', array_slice(array_map(static fn($m) => [$m['tipo'], $m['apelido'], $m['texto'], $m['creditos']], $c['mensagens']), -1),
    [['gorjeta', 'fa_1', 'Linda demais', 15]]);
[$s, $c] = chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => 15, 'recado' => 'me liga no whatsapp']);
verificar('recado com palavra bloqueada: 422 no campo recado', [$s, $c['campo'] ?? null], [422, 'recado']);
verificar('recado recusado não debita', lv_saldo($fa), $antes - 15);
[$s] = chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => 15, 'recado' => str_repeat('a', 81)]);
verificar('recado com mais de 80 caracteres: 422', $s, 422);
$qtdChat = count($lerChat()[1]['mensagens']);
chamar('gastar.php', 'POST', ['tipo' => 'privado', 'modelo' => 'livia-voguel']);
verificar('privado não aparece no chat', count($lerChat()[1]['mensagens']), $qtdChat);
chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => 5]);
verificar('gorjeta sem recado também aparece', array_slice(array_map(static fn($m) => [$m['tipo'], $m['texto'], $m['creditos']], $lerChat()[1]['mensagens']), -1), [['gorjeta', '', 5]]);

[$s] = chamar('chat-enviar.php', 'POST', ['modelo' => 'livia-voguel', 'texto' => 'oi'], ['HTTP_ORIGIN' => 'https://site-estranho.com']);
verificar('mensagem vinda de outro site: 403', $s, 403);
[$s] = chamar('chat.php', 'POST', []);
verificar('chat.php por POST: 405', $s, 405);

// ============================================================================= moderação do chat
recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
$pdo = lv_db();
foreach (['fa_1', 'bia_rj'] as $ap) {
    $pdo->prepare("INSERT INTO lv_usuarios (email, apelido, senha_hash, criado_em) VALUES (?, ?, 'x', '2026-01-01 00:00:00')")->execute(["$ap@exemplo.com.br", $ap]);
}
[$fa, $bia] = array_map('intval', $pdo->query('SELECT id FROM lv_usuarios ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
lv_lancar($fa, 500, 'compra', null, 'Pacote');
lv_ao_vivo_definir('livia-voguel', true);
$m1 = lv_chat_postar('livia-voguel', $fa, 'fa_1', 'msg', 'oi');
$m2 = lv_chat_postar('livia-voguel', $bia, 'bia_rj', 'msg', 'besteira');
$m3 = lv_chat_postar('livia-voguel', $bia, 'bia_rj', 'msg', 'outra besteira');
$moderar = static fn(array $corpo) => chamar('modelo-chat.php', 'POST', $corpo);
$textosPublicos = static function (): array { $_GET = ['modelo' => 'livia-voguel']; $r = chamar('chat.php')[1]; $_GET = []; return [array_map(static fn($m) => $m['texto'], $r['mensagens']), $r['apagadas']]; };

$_SESSION = sessao_cliente($fa);
[$s] = $moderar(['acao' => 'apagar', 'id' => $m2]);
verificar('cliente não modera: 401', $s, 401);
$_GET = [];
[$s] = chamar('modelo-chat.php');
verificar('cliente não lê o chat do painel: 401', $s, 401);

$_SESSION = [];
$entrar('livia@exemplo.com.br', $senhaBoa);
[$s, $c] = chamar('modelo-chat.php');
verificar('painel lê o chat com o autor', [$s, array_map(static fn($m) => $m['usuario'], $c['mensagens'])], [200, [$fa, $bia, $bia]]);
$_GET = ['modelo' => 'livia-voguel'];
verificar('chat público não traz o id interno de quem escreveu', array_key_exists('usuario', chamar('chat.php')[1]['mensagens'][0]), false);
$_GET = [];

[$s] = $moderar(['acao' => 'apagar', 'id' => $m2]);
verificar('modelo apaga mensagem: 200', $s, 200);
[$textos, $apagadas] = $textosPublicos();
verificar('mensagem apagada some para todos', $textos, ['oi', 'outra besteira']);
verificar('salas abertas recebem o id apagado', $apagadas, [$m2]);
[$s] = $moderar(['acao' => 'apagar', 'id' => $m2]);
verificar('apagar de novo não quebra', $s, 200);
[$s] = $moderar(['acao' => 'apagar', 'id' => 99999]);
verificar('apagar mensagem que não existe: 404', $s, 404);
$outra = lv_chat_postar('outra-sala', $fa, 'fa_1', 'msg', 'em outra sala');
[$s] = $moderar(['acao' => 'apagar', 'id' => $outra]);
verificar('modelo não apaga mensagem de outra sala: 404', $s, 404);

[$s, $c] = $moderar(['acao' => 'bloquear', 'id' => $m3]);
verificar('bloquear quem escreveu: 200 e aparece na lista', [$s, array_map(static fn($b) => $b['apelido'], $c['bloqueados'])], [200, ['bia_rj']]);
verificar('bloquear apaga as mensagens da pessoa', $textosPublicos()[0], ['oi']);
verificar('lista de bloqueados não mostra e-mail', str_contains(json_encode($c), '@'), false);

$sessaoModelo = $_SESSION;
$_SESSION = sessao_cliente($bia);
[$s, $c] = chamar('chat-enviar.php', 'POST', ['modelo' => 'livia-voguel', 'texto' => 'oi de novo']);
verificar('bloqueada não escreve: 403', $s, 403);
lv_lancar($bia, 100, 'compra', null, 'Pacote');
[$s] = chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => 10, 'recado' => 'me desbloqueia']);
verificar('bloqueada ainda manda gorjeta', $s, 200);
$_GET = ['modelo' => 'livia-voguel'];
$ultima = array_slice(chamar('chat.php')[1]['mensagens'], -1)[0];
$_GET = [];
verificar('gorjeta de bloqueada aparece sem recado', [$ultima['tipo'], $ultima['apelido'], $ultima['texto'], $ultima['creditos']], ['gorjeta', 'bia_rj', '', 10]);

$_SESSION = $sessaoModelo;
[$s, $c] = $moderar(['acao' => 'desbloquear', 'usuario' => $bia]);
verificar('desbloquear: 200 e sai da lista', [$s, $c['bloqueados']], [200, []]);
$_SESSION = sessao_cliente($bia);
[$s] = chamar('chat-enviar.php', 'POST', ['modelo' => 'livia-voguel', 'texto' => 'obrigada']);
verificar('desbloqueada volta a escrever', $s, 200);

// Recado de gorjeta abusivo: a modelo apaga; bloquear tira também os recados de gorjeta da pessoa.
$_SESSION = sessao_cliente($fa);
chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => 1, 'recado' => 'me chama 21 98888 7777']);
$recado = (int) $pdo->query("SELECT MAX(id) FROM lv_chat WHERE tipo = 'gorjeta'")->fetchColumn();
$_SESSION = $sessaoModelo;
[$s] = $moderar(['acao' => 'apagar', 'id' => $recado]);
verificar('modelo apaga recado de gorjeta: some da sala', [$s, in_array('me chama 21 98888 7777', $textosPublicos()[0], true)], [200, false]);
$_SESSION = sessao_cliente($fa);
chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => 1, 'recado' => 'outro recado chato']);
chamar('gastar.php', 'POST', ['tipo' => 'gorjeta', 'modelo' => 'livia-voguel', 'creditos' => 2]);
$_SESSION = $sessaoModelo;
$msgFa = lv_chat_postar('livia-voguel', $fa, 'fa_1', 'msg', 'mensagem da fa');
$moderar(['acao' => 'bloquear', 'id' => $msgFa]);
$textos = $textosPublicos()[0];
verificar('bloquear tira os recados de gorjeta da pessoa; a gorjeta sem recado fica',
    [in_array('outro recado chato', $textos, true), in_array('mensagem da fa', $textos, true), in_array('', $textos, true)], [false, false, true]);
$moderar(['acao' => 'desbloquear', 'usuario' => $fa]);

$_SESSION = $sessaoModelo;
[$s] = $moderar(['acao' => 'sumir', 'id' => $m1]);
verificar('ação inválida: 422', $s, 422);
[$s] = $moderar(['acao' => 'apagar', 'id' => (string) $m1]);
verificar('id em texto: 404', $s, 404);
[$s] = chamar('modelo-chat.php', 'POST', ['acao' => 'apagar', 'id' => $m1], ['HTTP_ORIGIN' => 'https://site-estranho.com']);
verificar('moderar vindo de outro site: 403', $s, 403);

// ============================================================================= saques
recomecar();
$idConta = criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
$pdo = lv_db();
$pdo->exec("INSERT INTO lv_usuarios (email, apelido, senha_hash, criado_em) VALUES ('fa@exemplo.com.br', 'fa_1', 'x', '2026-01-01 00:00:00')");
$fa = (int) $pdo->lastInsertId();
$receber = static fn(int $creditos) => lv_lancar($fa, -$creditos, 'gorjeta', null, 'Gorjeta', 'livia-voguel');
$saque = static fn(array $corpo) => chamar('modelo-saque.php', 'POST', $corpo);
$_GET = [];

$_SESSION = sessao_cliente($fa);
[$s] = chamar('modelo-saque.php');
verificar('cliente não vê saques: 401', $s, 401);

$_SESSION = [];
$entrar('livia@exemplo.com.br', $senhaBoa);
[$s, $c] = chamar('modelo-saque.php');
$soValor = static fn(array $d): array => ['creditos' => $d['creditos'], 'centavos' => $d['centavos']];
verificar('sem ganhos: nada disponível, sem chave', [$s, $soValor($c['disponivel']), $c['pix'], $c['minimo_centavos']], [200, ['creditos' => 0, 'centavos' => 0], null, 5000]);

$chaves = [
    'cpf com pontuação' => ['cpf', '529.982.247-25', '52998224725'],
    'e-mail em maiúsculas' => ['email', ' Livia@Exemplo.com.BR ', 'livia@exemplo.com.br'],
    'celular' => ['celular', '(21) 99999-8888', '+5521999998888'],
    'aleatória' => ['aleatoria', '123E4567-E89B-12D3-A456-426614174000', '123e4567-e89b-12d3-a456-426614174000'],
];
foreach ($chaves as $nome => [$tipo, $chave, $esperado]) {
    [$s, $c] = $saque(['acao' => 'pix', 'tipo' => $tipo, 'chave' => $chave, 'senha_atual' => $senhaBoa]);
    verificar("chave Pix ($nome)", [$s, ['tipo' => $c['pix']['tipo'] ?? null, 'chave' => $c['pix']['chave'] ?? null]], [200, ['tipo' => $tipo, 'chave' => $esperado]]);
}
$ruins = ['cpf inválido' => ['cpf', '529.982.247-26'], 'e-mail inválido' => ['email', 'livia@'], 'fixo no lugar de celular' => ['celular', '(21) 3333-4444'],
    'aleatória curta' => ['aleatoria', '123e4567'], 'chave em lista' => ['cpf', ['52998224725']]];
foreach ($ruins as $nome => [$tipo, $chave]) {
    [$s, $c] = $saque(['acao' => 'pix', 'tipo' => $tipo, 'chave' => $chave]);
    verificar("chave Pix recusada ($nome): 422", [$s, $c['campo'] ?? null], [422, 'chave']);
}
[$s, $c] = $saque(['acao' => 'pix', 'tipo' => 'banco', 'chave' => 'x']);
verificar('tipo de chave inválido: 422', [$s, $c['campo'] ?? null], [422, 'tipo']);
verificar('chave recusada não troca a salva', lv_pix_da_modelo('livia-voguel')['tipo'], 'aleatoria');

// Trocar a chave pede a senha (com uma sessão roubada não dá para desviar o saque).
[$s, $c] = $saque(['acao' => 'pix', 'tipo' => 'cpf', 'chave' => '52998224725']);
verificar('trocar a chave Pix sem a senha: 400', [$s, $c['campo'] ?? null], [400, 'senha_atual']);
[$s, $c] = $saque(['acao' => 'pix', 'tipo' => 'cpf', 'chave' => '52998224725', 'senha_atual' => 'senha-errada-123']);
verificar('trocar a chave Pix com a senha errada: 400', [$s, $c['campo'] ?? null], [400, 'senha_atual']);
verificar('sem a senha certa, a chave não muda', lv_pix_da_modelo('livia-voguel')['tipo'], 'aleatoria');
// Chave cadastrada ou trocada agora: o saque espera 24 h.
$receber(300);
[$s, $c] = $saque(['acao' => 'pedir']);
verificar('chave nova: saque espera 24 h', [$s, $c['erro']], [409, 'Por segurança, o saque fica liberado 24 horas depois de cadastrar ou trocar a chave Pix.']);
[, $c] = chamar('modelo-saque.php');
verificar('o painel recebe quando o saque libera', is_string($c['pix']['saque_liberado_em'] ?? null), true);
$pdo->exec("UPDATE lv_modelos_pix SET atualizado_em = '2000-01-01 00:00:00'");
[, $c] = $saque(['acao' => 'pix', 'tipo' => 'aleatoria', 'chave' => '123e4567-e89b-12d3-a456-426614174000', 'senha_atual' => $senhaBoa]);
verificar('salvar a mesma chave de novo não reinicia a espera', $c['pix']['saque_liberado_em'], null);
$pdo->exec("DELETE FROM lv_extrato WHERE modelo = 'livia-voguel'");

$receber(100); // 100 créditos × R$ 0,20 = R$ 20,00 (abaixo do mínimo de R$ 50,00)
[$s, $c] = $saque(['acao' => 'pedir']);
verificar('abaixo do mínimo: 409', [$s, $c['erro']], [409, 'O saque mínimo é de R$ 50,00.']);
$receber(200); // 300 créditos = R$ 60,00
[$s, $c] = $saque(['acao' => 'pedir']);
verificar('pedir saque: 200, sai tudo o que está disponível', [$s, $c['saques'][0]['creditos'], $c['saques'][0]['centavos'], $c['saques'][0]['status']], [200, 300, 6000, 'pendente']);
verificar('pedido guarda a chave do momento', $c['saques'][0]['pix'], ['tipo' => 'aleatoria', 'chave' => '123e4567-e89b-12d3-a456-426614174000']);
verificar('depois de pedir: nada disponível', $soValor($c['disponivel']), ['creditos' => 0, 'centavos' => 0]);
$receber(400); // R$ 80,00 novos
[$s, $c] = $saque(['acao' => 'pedir']);
verificar('um saque pendente por vez: 409', [$s, $c['erro']], [409, 'Você já tem um saque esperando pagamento.']);

$pdo->exec("UPDATE lv_saques SET status = 'pago', pago_em = '2026-10-11 12:00:00' WHERE status = 'pendente'");
[, $c] = chamar('modelo-saque.php');
verificar('pago pela equipe: aparece pago e libera o próximo', [$c['saques'][0]['status'], $c['saques'][0]['pago_em'], $c['disponivel']['creditos']], ['pago', '2026-10-11T12:00:00Z', 400]);
[$s, $c] = $saque(['acao' => 'pedir']);
verificar('segundo saque: só os créditos novos', [$s, $c['saques'][0]['creditos']], [200, 400]);
$pdo->exec("UPDATE lv_saques SET status = 'recusado', observacao = 'Chave errada' WHERE status = 'pendente'");
[, $c] = chamar('modelo-saque.php');
verificar('recusado devolve os créditos ao disponível', [$c['saques'][0]['status'], $c['saques'][0]['observacao'], $c['disponivel']['creditos']], ['recusado', 'Chave errada', 400]);

file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br', 'MODELO_ESPERA_SAQUE_DIAS' => 0, 'MODELO_SAQUE_MINIMO_CENTAVOS' => 10000];");
[$s, $c] = $saque(['acao' => 'pedir']);
verificar('mínimo configurável no config.php', [$s, $c['erro']], [409, 'O saque mínimo é de R$ 100,00.']);
file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br', 'MODELO_ESPERA_SAQUE_DIAS' => 0];");

$pdo->exec("DELETE FROM lv_modelos_pix");
[$s, $c] = $saque(['acao' => 'pedir']);
verificar('sem chave Pix não pede: 409', [$s, $c['erro']], [409, 'Cadastre sua chave Pix antes de pedir o saque.']);
[$s] = $saque(['acao' => 'sacar-tudo']);
verificar('ação inválida: 422', $s, 422);
[$s] = chamar('modelo-saque.php', 'POST', ['acao' => 'pedir'], ['HTTP_ORIGIN' => 'https://site-estranho.com']);
verificar('saque vindo de outro site: 403', $s, 403);

// Espera antes do saque: cada gorjeta fica disponível MODELO_ESPERA_SAQUE_DIAS depois (padrão 7).
$pdo->exec('DELETE FROM lv_saques');
$pdo->exec("DELETE FROM lv_extrato WHERE modelo = 'livia-voguel'");
file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br'];");
$receber(300);
$d = lv_saque_disponivel('livia-voguel');
verificar('gorjeta de agora fica em espera (padrão 7 dias)', [$d['creditos'], $d['em_espera']['creditos'], $d['em_espera']['dias']], [0, 300, 7]);
$pdo->exec("UPDATE lv_extrato SET criado_em = '2000-01-01 00:00:00' WHERE modelo = 'livia-voguel'");
$d = lv_saque_disponivel('livia-voguel');
verificar('passado o prazo, fica disponível', [$d['creditos'], $d['em_espera']['creditos']], [300, 0]);
$pdo->exec("INSERT INTO lv_modelos_estornos (slug, pedido_id, creditos, criado_em) VALUES ('livia-voguel', 1, 120, '2026-10-11 00:00:00')");
$d = lv_saque_disponivel('livia-voguel');
verificar('estorno descontado da modelo sai do disponível', [$d['creditos'], $d['estornados']['creditos']], [180, 120]);
file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br', 'MODELO_ESPERA_SAQUE_DIAS' => 0];");
foreach (['ao-vivo.php', 'perfis.php', 'salas.php'] as $publico) {
    verificar("$publico não mostra chave Pix", str_contains(json_encode(chamar($publico)[1]), '123e4567'), false);
}

// ============================================================================= fotos
recomecar();
criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
$uploads = sys_get_temp_dir() . '/lv-uploads-' . bin2hex(random_bytes(4));
file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br', 'MODELO_ESPERA_SAQUE_DIAS' => 0, 'UPLOADS_DIR' => " . var_export($uploads, true) . "];");
$temporarios = [];
register_shutdown_function(static function () use ($uploads, &$temporarios): void {
    foreach (array_merge(glob($uploads . '/modelos/*/*') ?: [], $temporarios) as $f) { @unlink($f); }
    @rmdir($uploads . '/modelos/livia-voguel'); @rmdir($uploads . '/modelos'); @rmdir($uploads);
});
/** Gera uma imagem de teste e devolve o caminho. $extra é colado no fim do arquivo (para o teste do arquivo "poliglota"). */
$imagem = static function (int $w, int $h, string $tipo = 'jpeg', string $extra = '') use (&$temporarios): string {
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 50, 120));
    $arq = tempnam(sys_get_temp_dir(), 'lv-img-');
    $temporarios[] = $arq;
    match ($tipo) { 'jpeg' => imagejpeg($img, $arq), 'png' => imagepng($img, $arq), 'webp' => imagewebp($img, $arq) };
    if ($extra !== '') { file_put_contents($arq, $extra, FILE_APPEND); }
    return $arq;
};
$texto = static function (string $conteudo) use (&$temporarios): string {
    $arq = tempnam(sys_get_temp_dir(), 'lv-txt-');
    $temporarios[] = $arq;
    file_put_contents($arq, $conteudo);
    return $arq;
};
$enviarFoto = static function (string $arquivo, array $servidor = []): array {
    $_FILES = ['foto' => ['name' => 'foto.jpg', 'type' => 'image/jpeg', 'tmp_name' => $arquivo, 'error' => UPLOAD_ERR_OK, 'size' => filesize($arquivo)]];
    $r = chamar('modelo-fotos.php', 'POST', null, $servidor + ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_X_LV_ENVIO' => '1']);
    $_FILES = [];
    return $r;
};
$_GET = [];

$_SESSION = ['usuario_id' => 7];
[$s] = $enviarFoto($imagem(800, 600));
verificar('cliente não envia foto: 401', $s, 401);
$_SESSION = [];
$entrar('livia@exemplo.com.br', $senhaBoa);
[$s] = $enviarFoto($imagem(800, 600), ['HTTP_X_LV_ENVIO' => '']);
verificar('envio sem o cabeçalho do site: 403', $s, 403);
[$s] = $enviarFoto($imagem(800, 600), ['HTTP_ORIGIN' => 'https://site-estranho.com']);
verificar('envio vindo de outro site: 403', $s, 403);

[$s, $c] = $enviarFoto($imagem(2400, 1200));
verificar('envio de JPG: 200 e vira a capa', [$s, count($c['fotos']), $c['fotos'][0]['capa']], [200, 1, true]);
verificar('regravada com no máximo 1600 px', [$c['fotos'][0]['largura'], $c['fotos'][0]['altura']], [1600, 800]);
$disco = $uploads . '/modelos/livia-voguel/' . basename($c['fotos'][0]['url']);
verificar('arquivo gravado como JPG de verdade', [is_file($disco), (new finfo(FILEINFO_MIME_TYPE))->file($disco)], [true, 'image/jpeg']);
verificar('nome do arquivo é aleatório e .jpg', (bool) preg_match('#^uploads/modelos/livia-voguel/[0-9a-f]{24}\.jpg$#', $c['fotos'][0]['url']), true);

[$s, $c] = $enviarFoto($imagem(800, 800, 'png'));
verificar('PNG vira JPG e entra na galeria', [$s, count($c['fotos']), $c['fotos'][1]['capa'], substr($c['fotos'][1]['url'], -4)], [200, 2, false, '.jpg']);
[$s] = $enviarFoto($imagem(800, 800, 'webp'));
verificar('WebP aceito', $s, 200);

[$s, $c] = $enviarFoto($imagem(200, 200));
verificar('foto pequena demais: 422', [$s, $c['campo'] ?? null], [422, 'foto']);
[$s] = $enviarFoto($texto('isto não é uma foto'));
verificar('arquivo de texto com nome .jpg: 422', $s, 422);
[$s] = $enviarFoto($texto("<?php echo 'invadido'; ?>"));
verificar('código PHP disfarçado de foto: 422', $s, 422);
$antes = count(glob($uploads . '/modelos/livia-voguel/*'));
[$s, $c] = $enviarFoto($imagem(600, 600, 'png', "<?php system(\$_GET['c']); ?>"));
$nova = $uploads . '/modelos/livia-voguel/' . basename(end($c['fotos'])['url']);
verificar('PNG com PHP colado no fim: aceito, mas regravado sem o código', [$s, str_contains((string) file_get_contents($nova), '<?php')], [200, false]);
$_FILES = ['foto' => ['name' => 'x.jpg', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]];
[$s, $c] = chamar('modelo-fotos.php', 'POST', null, ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_X_LV_ENVIO' => '1']);
$_FILES = [];
verificar('arquivo acima do limite do PHP: 422 com mensagem clara', [$s, $c['erro']], [422, 'A foto pode ter até 5 MB.']);

$pub = chamar('perfis.php')[1]['modelos']['livia-voguel'];
[, $minhas] = chamar('modelo-fotos.php');
verificar('público vê a capa e a galeria', [$pub['foto'], count($pub['galeria'])], [$minhas['fotos'][0]['url'], count($minhas['fotos']) - 1]);
verificar('público não vê o nome do perfil se ela só enviou fotos', array_key_exists('nome', $pub), false);

$segunda = $minhas['fotos'][1]['id'];
[$s, $c] = chamar('modelo-fotos.php', 'POST', ['acao' => 'capa', 'id' => $segunda]);
verificar('trocar a capa', [$s, $c['fotos'][0]['id'], $c['fotos'][0]['capa']], [200, $segunda, true]);
$capaDisco = $uploads . '/modelos/livia-voguel/' . basename($c['fotos'][0]['url']);
[$s, $c] = chamar('modelo-fotos.php', 'POST', ['acao' => 'apagar', 'id' => $segunda]);
verificar('apagar a capa: a próxima vira capa e o arquivo some do disco', [$s, $c['fotos'][0]['capa'], is_file($capaDisco)], [200, true, false]);
[$s] = chamar('modelo-fotos.php', 'POST', ['acao' => 'apagar', 'id' => 99999]);
verificar('apagar foto que não existe: 404', $s, 404);
lv_db()->exec("INSERT INTO lv_modelos_fotos (slug, arquivo, capa, ordem, largura, altura, criado_em) VALUES ('outra-modelo', 'x.jpg', 1, 1, 400, 400, '2026-01-01 00:00:00')");
$deOutra = (int) lv_db()->lastInsertId();
[$s] = chamar('modelo-fotos.php', 'POST', ['acao' => 'apagar', 'id' => $deOutra]);
verificar('modelo não apaga foto de outra: 404', $s, 404);
[$s] = chamar('modelo-fotos.php', 'POST', ['acao' => 'girar', 'id' => $segunda]);
verificar('ação inválida: 422', $s, 422);

while (lv_fotos_contar('livia-voguel') < LV_FOTO_MAX_POR_MODELO) {
    lv_foto_adicionar('livia-voguel', 'enchimento.jpg', 400, 400);
}
[$s, $c] = $enviarFoto($imagem(800, 600));
verificar('limite de 12 fotos: 409', [$s, $c['erro']], [409, 'Você já tem 12 fotos. Apague uma para enviar outra.']);
file_put_contents($configTeste, "<?php return ['SITE_URL' => 'https://exemplo.com.br', 'MODELO_ESPERA_SAQUE_DIAS' => 0];");

// ============================================================================= painel da equipe
recomecar();
$idLivia = criar_conta('livia-voguel', 'livia@exemplo.com.br', $senhaBoa);
$pdo = lv_db();
$senhaEquipe = 'senha-da-equipe-123';
$pdo->prepare('INSERT INTO lv_equipe_contas (email, senha_hash, ativa, criado_em) VALUES (?, ?, 1, ?)')
    ->execute(['equipe@exemplo.com.br', password_hash($senhaEquipe, PASSWORD_DEFAULT), lv_agora()]);
$idEquipe = (int) $pdo->lastInsertId();
$entrarEquipe = static fn(string $email, string $senha) => chamar('equipe-entrar.php', 'POST', ['email' => $email, 'senha' => $senha]);
$_GET = [];

// separação dos logins
$_SESSION = [];
$entrar('livia@exemplo.com.br', $senhaBoa);
[$s, $c] = chamar('equipe.php');
verificar('modelo logada não abre o painel da equipe: 401', [$s, $c['login_equipe'] ?? null], [401, true]);
$_SESSION = ['usuario_id' => 7];
[$s] = chamar('equipe.php');
verificar('cliente logado não abre o painel da equipe: 401', $s, 401);
$_SESSION = [];
[$s] = $entrarEquipe('livia@exemplo.com.br', $senhaBoa);
verificar('conta de modelo não entra como equipe', $s, 401);
[$s] = $entrarEquipe('equipe@exemplo.com.br', 'errada-123456');
verificar('senha errada da equipe: 401', $s, 401);
[$s] = $entrarEquipe(' EQUIPE@exemplo.com.br ', $senhaEquipe);
verificar('login da equipe: 200', $s, 200);
verificar('login da equipe não vira modelo nem cliente', [$_SESSION['modelo_id'] ?? null, $_SESSION['usuario_id'] ?? null], [null, null]);
[$s, $c] = chamar('modelo-ganhos.php');
verificar('equipe não abre o painel da modelo', $s, 401);
[, $c] = chamar('equipe-eu.php');
verificar('equipe-eu diz logada', [$c['logado'], $c['equipe']['email']], [true, 'equipe@exemplo.com.br']);
$pdo->prepare('UPDATE lv_equipe_contas SET senha_hash = ? WHERE id = ?')->execute([password_hash($senhaEquipe, PASSWORD_DEFAULT), $idEquipe]);
[, $c] = chamar('equipe-eu.php');
verificar('senha da equipe trocada ou redefinida: a sessão aberta cai', $c['logado'], false);
[$s] = $entrarEquipe('equipe@exemplo.com.br', $senhaEquipe);
verificar('equipe entra de novo com a senha', $s, 200);
$_SESSION['equipe_desde'] = time() - LV_EQUIPE_SESSAO_MAXIMA - 1;
[$s] = chamar('equipe.php');
verificar('sessão da equipe vence em 8 h', $s, 401);

$_SESSION = [];
for ($i = 0; $i < LV_EQUIPE_ERROS_LOGIN; $i++) { $entrarEquipe('equipe@exemplo.com.br', "chute-$i-xxxxxx"); }
[$s] = $entrarEquipe('equipe@exemplo.com.br', $senhaEquipe);
verificar('5 erros no login da equipe: espera, mesmo com a senha certa', $s, 429);
$pdo->exec("DELETE FROM lv_eventos WHERE tipo = 'equipe_login_erro'");

// saques
$pdo->exec("INSERT INTO lv_usuarios (email, apelido, senha_hash, criado_em) VALUES ('fa@exemplo.com.br', 'fa_1', 'x', '2026-01-01 00:00:00')");
$fa = (int) $pdo->lastInsertId();
lv_lancar($fa, -500, 'gorjeta', null, 'Gorjeta', 'livia-voguel'); // R$ 100,00 para a modelo
lv_pix_salvar('livia-voguel', 'cpf', '52998224725');
$pdo->exec("UPDATE lv_modelos_pix SET atualizado_em = '2000-01-01 00:00:00'"); // chave antiga: sem a espera de 24 h
[$saque] = lv_saque_pedir($idLivia, 'livia-voguel');
$_SESSION = [];
$entrarEquipe('equipe@exemplo.com.br', $senhaEquipe);
[$s, $c] = chamar('equipe.php');
verificar('equipe vê o saque pendente com a chave Pix', [$s, count($c['saques']['pendentes']), $c['saques']['pendentes'][0]['pix']['chave'], $c['saques']['pendentes'][0]['centavos']],
    [200, 1, '52998224725', 10000]);
verificar('equipe vê a conta da modelo', [$c['modelos'][0]['slug'], $c['modelos'][0]['ativa'], $c['modelos'][0]['disponivel']['centavos']], ['livia-voguel', true, 0]);
[$s, $c] = chamar('equipe.php', 'POST', ['acao' => 'saque_recusado', 'id' => $saque['id'], 'observacao' => '']);
verificar('recusar sem motivo: 422', $s, 422);
[$s, $c] = chamar('equipe.php', 'POST', ['acao' => 'saque_pago', 'id' => $saque['id'], 'observacao' => 'Pix E2E123']);
verificar('marcar como pago: 200 e sai dos pendentes', [$s, count($c['saques']['pendentes']), $c['saques']['resolvidos'][0]['status'], $c['saques']['resolvidos'][0]['observacao']],
    [200, 0, 'pago', 'Pix E2E123']);
[$s, $c] = chamar('equipe.php', 'POST', ['acao' => 'saque_recusado', 'id' => $saque['id'], 'observacao' => 'erro']);
verificar('saque já pago não pode ser recusado: 409', [$s, $c['erro']], [409, 'Esse saque já foi marcado como pago.']);
[$s] = chamar('equipe.php', 'POST', ['acao' => 'saque_pago', 'id' => 99999]);
verificar('saque que não existe: 404', $s, 404);
lv_lancar($fa, -400, 'gorjeta', null, 'Gorjeta', 'livia-voguel');
[$saque2] = lv_saque_pedir($idLivia, 'livia-voguel');
[$s, $c] = chamar('equipe.php', 'POST', ['acao' => 'saque_recusado', 'id' => $saque2['id'], 'observacao' => 'Chave não confere']);
verificar('recusar com motivo: os créditos voltam ao disponível da modelo', [$s, $c['saques']['resolvidos'][0]['status'], $c['modelos'][0]['disponivel']['creditos']], [200, 'recusado', 400]);

// bloquear e reativar modelo
$sessaoEquipe = $_SESSION;
$_SESSION = [];
$entrar('livia@exemplo.com.br', $senhaBoa);
lv_ao_vivo_definir('livia-voguel', true);
$sessaoLivia = $_SESSION;
$_SESSION = $sessaoEquipe;
[$s, $c] = chamar('equipe.php', 'POST', ['acao' => 'modelo_bloquear', 'id' => $idLivia]);
verificar('bloquear modelo: conta inativa e sala desligada', [$s, $c['modelos'][0]['ativa'], $c['modelos'][0]['online']], [200, false, false]);
$_SESSION = $sessaoLivia;
[$s] = chamar('modelo-ganhos.php');
verificar('modelo bloqueada perde a sessão aberta', $s, 401);
[$s] = $entrar('livia@exemplo.com.br', $senhaBoa);
verificar('modelo bloqueada não entra', $s, 401);
$_SESSION = $sessaoEquipe;
[$s, $c] = chamar('equipe.php', 'POST', ['acao' => 'modelo_reativar', 'id' => $idLivia]);
verificar('reativar modelo', [$s, $c['modelos'][0]['ativa']], [200, true]);
$_SESSION = [];
[$s] = $entrar('livia@exemplo.com.br', $senhaBoa);
verificar('modelo reativada entra de novo', $s, 200);

$_SESSION = $sessaoEquipe;
[$s] = chamar('equipe.php', 'POST', ['acao' => 'modelo_bloquear', 'id' => 99999]);
verificar('modelo que não existe: 404', $s, 404);
[$s] = chamar('equipe.php', 'POST', ['acao' => 'apagar_tudo', 'id' => 1]);
verificar('ação inválida: 422', $s, 422);
[$s] = chamar('equipe.php', 'POST', ['acao' => 'saque_pago', 'id' => '1']);
verificar('id em texto: 422', $s, 422);
[$s] = chamar('equipe.php', 'POST', ['acao' => 'modelo_bloquear', 'id' => $idLivia], ['HTTP_ORIGIN' => 'https://site-estranho.com']);
verificar('ação vinda de outro site: 403', $s, 403);
verificar('ações da equipe ficam registradas', (int) $pdo->query("SELECT COUNT(*) FROM lv_eventos WHERE tipo = 'equipe_acao'")->fetchColumn(), 4);
$pdo->exec('UPDATE lv_equipe_contas SET ativa = 0');
[$s] = chamar('equipe.php');
verificar('conta da equipe desativada perde o acesso', $s, 401);
[$s] = chamar('equipe-sair.php', 'POST');
verificar('sair do painel da equipe', $s, 200);

// ============================================================================= conta do cliente: trocar e-mail e excluir
recomecar();
$pdo = lv_db();
$senhaCli = 'senha-do-cliente-1';
foreach ([['ana@exemplo.com.br', 'ana_rj'], ['bia@exemplo.com.br', 'bia_sp']] as [$em, $ap]) {
    $pdo->prepare('INSERT INTO lv_usuarios (email, apelido, senha_hash, criado_em) VALUES (?, ?, ?, ?)')->execute([$em, $ap, password_hash($senhaCli, PASSWORD_DEFAULT), lv_agora()]);
}
[$ana, $bia] = array_map('intval', $pdo->query('SELECT id FROM lv_usuarios ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

// Apelido que imita a modelo ou a equipe não passa (ninguém pede Pix "como a modelo").
$_SESSION = sessao_cliente($ana);
foreach (['Livia_Voguel', 'livia123', 'LIVIA', 'suporte_site', 'x_liviavoguel_x', 'equipe1'] as $ap) {
    [$s, $c] = chamar('conta.php', 'POST', ['apelido' => $ap]);
    verificar("apelido que imita a modelo ou a equipe ($ap): 422", [$s, $c['campo'] ?? null], [422, 'apelido']);
}
[$s, $c] = chamar('conta.php', 'POST', ['apelido' => 'olivia_rj']);
verificar('apelido parecido, mas de outra pessoa, passa', [$s, $c['usuario']['apelido'] ?? null], [200, 'olivia_rj']);
$pdo->exec("UPDATE lv_usuarios SET apelido = 'ana_rj' WHERE id = $ana");
$_SESSION = [];
[$s, $c] = chamar('cadastro.php', 'POST', ['email' => 'livia.voguel@exemplo.com.br', 'senha' => 'segredo1234', 'maior18' => true]);
verificar('cadastro com e-mail que imita a modelo ganha apelido neutro', [$s, (bool) preg_match('/^fa_\d+$/', $c['usuario']['apelido'] ?? '')], [201, true]);
$_SESSION = [];
[$s, $c] = chamar('cadastro.php', 'POST', ['email' => 'outra@exemplo.com.br', 'senha' => 'segredo1234', 'apelido' => 'Livia_Voguel', 'maior18' => true]);
verificar('cadastro com o apelido da modelo: 422', [$s, $c['campo'] ?? null], [422, 'apelido']);
$_SESSION = [];

$_SESSION = [];
[$s] = chamar('conta-email.php', 'POST', ['email_novo' => 'x@exemplo.com.br', 'senha' => $senhaCli]);
verificar('trocar e-mail sem login: 401', $s, 401);
$_SESSION = sessao_cliente($ana);
[$s, $c] = chamar('conta-email.php', 'POST', ['email_novo' => 'ana.nova@exemplo.com.br', 'senha' => 'errada']);
verificar('trocar e-mail com senha errada: 401 no campo senha', [$s, $c['campo'] ?? null], [401, 'senha']);
[$s, $c] = chamar('conta-email.php', 'POST', ['email_novo' => 'ana@', 'senha' => $senhaCli]);
verificar('e-mail inválido: 422', [$s, $c['campo'] ?? null], [422, 'email_novo']);
[$s] = chamar('conta-email.php', 'POST', ['email_novo' => 'ANA@exemplo.com.br', 'senha' => $senhaCli]);
verificar('o mesmo e-mail: 422', $s, 422);
[$s, $c] = chamar('conta-email.php', 'POST', ['email_novo' => 'bia@exemplo.com.br', 'senha' => $senhaCli]);
verificar('e-mail de outra conta: 409', [$s, $c['erro']], [409, 'Esse e-mail já é usado por outra conta.']);
[$s, $c] = chamar('conta-email.php', 'POST', ['email_novo' => ' Ana.Nova@Exemplo.com.br ', 'senha' => $senhaCli]);
verificar('trocar e-mail: 200 e devolve o novo', [$s, $c['usuario']['email']], [200, 'ana.nova@exemplo.com.br']);
$_SESSION = [];
[$s] = chamar('entrar.php', 'POST', ['email' => 'ana@exemplo.com.br', 'senha' => $senhaCli]);
verificar('e-mail antigo não entra mais', $s, 401);
[$s] = chamar('entrar.php', 'POST', ['email' => 'ana.nova@exemplo.com.br', 'senha' => $senhaCli]);
verificar('e-mail novo entra', $s, 200);
$pdo->exec("DELETE FROM lv_eventos WHERE tipo = 'login_erro'");
$_SESSION = sessao_cliente($ana);
for ($i = 0; $i < LV_ERROS_LOGIN; $i++) { chamar('conta-email.php', 'POST', ['email_novo' => "z$i@exemplo.com.br", 'senha' => 'chute']); }
[$s] = chamar('conta-email.php', 'POST', ['email_novo' => 'z@exemplo.com.br', 'senha' => $senhaCli]);
verificar('chutes de senha ao trocar e-mail: espera', $s, 429);
$pdo->exec("DELETE FROM lv_eventos WHERE tipo = 'login_erro'");

// Excluir
lv_lancar($ana, 300, 'compra', null, 'Pacote');
lv_lancar($ana, -20, 'gorjeta', null, 'Gorjeta', 'livia-voguel');
$msgAna = lv_chat_postar('livia-voguel', $ana, 'ana_rj', 'msg', 'meu zap é 21 99999-0000');
lv_chat_postar('livia-voguel', $ana, 'ana_rj', 'gorjeta', 'linda', 20);
lv_chat_postar('livia-voguel', $bia, 'bia_sp', 'msg', 'oi');
$pdo->prepare('INSERT INTO lv_chat_bloqueios (slug, usuario_id, criado_em) VALUES (?, ?, ?)')->execute(['livia-voguel', $ana, lv_agora()]);
lv_registrar('login_erro', 'e:ana.nova@exemplo.com.br');
$pdo->prepare("INSERT INTO lv_pedidos (codigo, usuario_id, pacote, creditos, valor_centavos, metodo, status, criado_em, atualizado_em) VALUES ('p1', ?, 0, 50, 1990, 'pix', 'pendente', ?, ?)")
    ->execute([$ana, lv_agora(), lv_agora()]);

$_SESSION = sessao_cliente($ana);
[$s, $c] = chamar('conta-excluir.php', 'POST', ['senha' => $senhaCli, 'confirmacao' => 'excluir']);
verificar('excluir sem digitar EXCLUIR: 422', [$s, $c['campo'] ?? null], [422, 'confirmacao']);
[$s, $c] = chamar('conta-excluir.php', 'POST', ['senha' => 'errada', 'confirmacao' => 'EXCLUIR']);
verificar('excluir com senha errada: 401', [$s, $c['campo'] ?? null], [401, 'senha']);
[$s, $c] = chamar('conta-excluir.php', 'POST', ['senha' => $senhaCli, 'confirmacao' => 'EXCLUIR']);
verificar('excluir com pagamento pendente: 409', $s, 409);
$pdo->exec("UPDATE lv_pedidos SET status = 'expirado'");
[$s, $c] = chamar('conta-excluir.php', 'POST', ['senha' => $senhaCli, 'confirmacao' => 'EXCLUIR']);
verificar('excluir: 200 e sai da conta', [$s, $_SESSION], [200, []]);

$u = $pdo->query("SELECT email, apelido FROM lv_usuarios WHERE id = $ana")->fetch();
verificar('e-mail e apelido viram anônimos', [$u['email'], $u['apelido']], ["excluida-$ana@conta-excluida.invalid", "excluida_$ana"]);
$_SESSION = [];
[$s] = chamar('entrar.php', 'POST', ['email' => 'ana.nova@exemplo.com.br', 'senha' => $senhaCli]);
verificar('conta excluída não entra mais', $s, 401);
$pdo->exec("DELETE FROM lv_eventos WHERE tipo = 'login_erro' AND chave LIKE '%ana.nova@exemplo.com.br%'"); // e: e ei: (e-mail+IP)
$todo = json_encode([$pdo->query('SELECT * FROM lv_usuarios')->fetchAll(), $pdo->query('SELECT * FROM lv_chat')->fetchAll(),
    $pdo->query('SELECT * FROM lv_eventos')->fetchAll(), $pdo->query('SELECT * FROM lv_extrato')->fetchAll()], JSON_UNESCAPED_UNICODE);
verificar('nenhum dado pessoal fica no banco (e-mail, apelido, texto do chat)',
    [str_contains($todo, 'ana.nova@'), str_contains($todo, 'ana@exemplo'), str_contains($todo, 'ana_rj'), str_contains($todo, '99999-0000'), str_contains($todo, 'linda')],
    [false, false, false, false, false]);
$_GET = ['modelo' => 'livia-voguel'];
[, $c] = chamar('chat.php');
$_GET = [];
verificar('mensagem dela some da sala; a gorjeta fica sem nome nem recado', [array_map(static fn($m) => [$m['tipo'], $m['apelido'], $m['texto']], $c['mensagens']), in_array($msgAna, $c['apagadas'], true)],
    [[['gorjeta', 'conta excluída', ''], ['msg', 'bia_sp', 'oi']], true]);
verificar('extrato e pedidos ficam (registro financeiro)', [(int) $pdo->query("SELECT COUNT(*) FROM lv_extrato WHERE usuario_id = $ana")->fetchColumn(), (int) $pdo->query("SELECT COUNT(*) FROM lv_pedidos WHERE usuario_id = $ana")->fetchColumn()], [2, 1]);
verificar('ganhos da modelo continuam certos', lv_modelo_soma('livia-voguel')['creditos'], 20);
verificar('bloqueio no chat dela some', (int) $pdo->query("SELECT COUNT(*) FROM lv_chat_bloqueios WHERE usuario_id = $ana")->fetchColumn(), 0);
$_SESSION = sessao_cliente($bia);
[, $c] = chamar('eu.php');
verificar('a outra conta não é afetada', $c['usuario']['email'], 'bia@exemplo.com.br');
[$s] = chamar('conta-excluir.php', 'POST', ['senha' => $senhaCli, 'confirmacao' => 'EXCLUIR'], ['HTTP_ORIGIN' => 'https://site-estranho.com']);
verificar('excluir vindo de outro site: 403', $s, 403);

// ============================================================================= espelho dos slugs
// Sem comentários: o exemplo "nova-modelo" comentado no fim do arquivo não é uma modelo.
$js = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', (string) file_get_contents($raiz . '/assets/js/modelos.js'));
preg_match_all('/slug:\s*"([^"]+)"/', $js, $m);
verificar('toda modelo de modelos.js existe em LV_MODELOS (para criar a conta)', array_values(array_diff($m[1], array_keys(LV_MODELOS))), []);

echo $falhas === 0 ? "OK: $total verificações passaram.\n" : "FALHAS: $falhas de $total.\n";
exit($falhas === 0 ? 0 : 1);
