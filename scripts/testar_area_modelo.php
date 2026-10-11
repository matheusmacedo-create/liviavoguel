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

// ============================================================================= espelho dos slugs
// Sem comentários: o exemplo "nova-modelo" comentado no fim do arquivo não é uma modelo.
$js = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', (string) file_get_contents($raiz . '/assets/js/modelos.js'));
preg_match_all('/slug:\s*"([^"]+)"/', $js, $m);
verificar('toda modelo de modelos.js existe em LV_MODELOS (para criar a conta)', array_values(array_diff($m[1], array_keys(LV_MODELOS))), []);

echo $falhas === 0 ? "OK: $total verificações passaram.\n" : "FALHAS: $falhas de $total.\n";
exit($falhas === 0 ? 0 : 1);
