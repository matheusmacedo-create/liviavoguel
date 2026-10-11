<?php
/** Configuração, respostas JSON, banco (PDO), sessão, leitura do corpo e freios. Incluído por todos os endpoints. */
declare(strict_types=1);

require_once __DIR__ . '/pacotes.php';
require_once __DIR__ . '/modelos.php';
require_once __DIR__ . '/unicopag.php';
require_once __DIR__ . '/creditos.php';

/** Freios: cobranças por usuário por hora; erros de login por e-mail (e 3× isso por IP) na janela, em segundos. */
const LV_COBRANCAS_POR_HORA = 15;
/**
 * Freio contra teste de cartões roubados: cartões recusados por conta (e 2× isso por IP) em 24 h, e tentativas de
 * cartão por IP por hora. Passou disso, só Pix até o prazo vencer.
 */
const LV_CARTAO_RECUSAS_DIA = 5;
const LV_CARTAO_POR_HORA_IP = 10;
/** O e-mail sozinho só trava o login com este fator × o limite de erros (ver lv_login_tentar). */
const LV_LOGIN_FATOR_EMAIL = 10;
const LV_ERROS_LOGIN = 10;
const LV_JANELA_LOGIN = 900;
/** Tamanho mínimo da senha (cadastro.html e conta.html usam o mesmo). */
const LV_SENHA_MINIMA = 8;

/** Resposta pronta. Nos testes (LV_TESTE), lv_json() lança isto em vez de encerrar o PHP. */
class LvResposta extends RuntimeException
{
    public function __construct(public int $status, public array $corpo)
    {
        parent::__construct('resposta ' . $status);
    }
}

// ----------------------------------------------------------------------------- configuração
function lv_config(): array
{
    static $config = null;
    if ($config === null || defined('LV_TESTE')) {
        $arquivo = getenv('LV_CONFIG_ARQUIVO') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($arquivo)) {
            lv_falhar(500, 'Pagamentos não configurados.');
        }
        $lido = require $arquivo;
        $config = is_array($lido) ? $lido : [];
    }
    return $config;
}

function lv_cfg(string $chave, mixed $padrao = null): mixed
{
    $config = lv_config();
    if (!array_key_exists($chave, $config) || $config[$chave] === '' || $config[$chave] === null) {
        return $padrao;
    }
    return $config[$chave];
}

function lv_site_url(): string
{
    return rtrim((string) lv_cfg('SITE_URL', ''), '/');
}

function lv_agora(): string
{
    return gmdate('Y-m-d H:i:s');
}

// ----------------------------------------------------------------------------- respostas
function lv_json(array $corpo, int $status = 200): never
{
    if (defined('LV_TESTE')) {
        throw new LvResposta($status, $corpo);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lv_falhar(int $status, string $mensagem, array $extra = []): never
{
    lv_json(['ok' => false, 'erro' => $mensagem] + $extra, $status);
}

function lv_exigir_metodo(string $metodo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $metodo) {
        lv_falhar(405, 'Método não permitido.');
    }
}

// Erro inesperado: nada de detalhe técnico (nem dado de cartão) para o navegador; o detalhe vai para o log.
if (!defined('LV_TESTE')) {
    set_exception_handler(static function (Throwable $e): void {
        error_log('[pagamentos] ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code($e instanceof PDOException ? 503 : 500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'erro' => 'Erro no servidor. Tente de novo em instantes.'], JSON_UNESCAPED_UNICODE);
    });
}

// ----------------------------------------------------------------------------- banco
function lv_db(?PDO $usar = null): PDO
{
    static $pdo = null;
    if ($usar !== null) {
        return $pdo = $usar;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    // DB_DSN: só para testar localmente com outro banco (ex.: sqlite:/caminho). Na Hostinger, fica sem.
    $dsn = (string) lv_cfg('DB_DSN', '') ?: sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        lv_cfg('DB_HOST', 'localhost'), (int) lv_cfg('DB_PORT', 3306), lv_cfg('DB_NAME', ''));
    try {
        $pdo = new PDO($dsn, (string) lv_cfg('DB_USER', ''), (string) lv_cfg('DB_SENHA', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('[pagamentos] banco indisponível: ' . $e->getMessage());
        lv_falhar(503, 'Serviço indisponível. Tente de novo em instantes.');
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        lv_migrar($pdo);
    }
    return $pdo;
}

/** Arquivos de esquema que a api/ aplica sozinha no MySQL, nesta ordem. */
const LV_ESQUEMAS = ['schema.sql', 'schema-modelos.sql'];

/**
 * Os comandos SQL dos arquivos de esquema, sem comentários. Todos são CREATE TABLE IF NOT EXISTS: rodar de novo não
 * muda nada nas tabelas que já existem (coluna nova numa tabela existente precisa de ALTER feito à mão).
 */
function lv_esquema_comandos(): array
{
    $comandos = [];
    foreach (LV_ESQUEMAS as $arquivo) {
        $sql = (string) @file_get_contents(dirname(__DIR__) . '/' . $arquivo);
        $sql = preg_replace('/--[^\n]*/', '', $sql) ?? '';
        foreach (explode(';', $sql) as $comando) {
            if (trim($comando) !== '') {
                $comandos[] = trim($comando);
            }
        }
    }
    return $comandos;
}

/**
 * Cria as tabelas que faltam, sem precisar do phpMyAdmin. A versão (md5 dos arquivos de esquema) fica em lv_esquema:
 * enquanto os arquivos não mudam, custa uma consulta por requisição. Quando mudam (deploy novo), roda de novo.
 */
function lv_migrar(PDO $pdo): void
{
    $versao = md5(implode("\n", array_map(static fn(string $a): string => (string) @file_get_contents(dirname(__DIR__) . '/' . $a), LV_ESQUEMAS)));
    try {
        if ($pdo->query('SELECT versao FROM lv_esquema LIMIT 1')->fetchColumn() === $versao) {
            return;
        }
    } catch (PDOException) {
        // lv_esquema ainda não existe: banco novo.
    }
    try {
        foreach (lv_esquema_comandos() as $comando) {
            $pdo->exec($comando);
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS lv_esquema (versao CHAR(32) NOT NULL, aplicado_em DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('DELETE FROM lv_esquema');
        $pdo->prepare('INSERT INTO lv_esquema (versao, aplicado_em) VALUES (?, ?)')->execute([$versao, lv_agora()]);
    } catch (PDOException $e) {
        error_log('[pagamentos] não foi possível criar as tabelas: ' . $e->getMessage());
        lv_falhar(503, 'Serviço indisponível. Tente de novo em instantes.');
    }
}

function lv_registrar(string $tipo, string $chave, ?string $detalhe = null): void
{
    lv_db()->prepare('INSERT INTO lv_eventos (tipo, chave, detalhe, criado_em) VALUES (?, ?, ?, ?)')
        ->execute([$tipo, mb_substr($chave, 0, 190), $detalhe !== null ? mb_substr($detalhe, 0, 255) : null, lv_agora()]);
}

/** Hash de uma senha qualquer: compara mesmo quando o e-mail não existe, para o tempo de resposta não revelar quem tem conta. */
function lv_hash_falso(): string
{
    static $hash = null;
    return $hash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
}

/**
 * Freio de login (cliente, modelo e equipe). Os erros contam pelo par e-mail+IP e pelo IP: quem chuta senhas de um IP
 * só trava a si mesmo, e a dona da conta, em outro lugar, continua entrando. O e-mail sozinho só trava com
 * LV_LOGIN_FATOR_EMAIL × mais erros (ataque espalhado por muitos IPs). Bloqueado, responde 429 sem registrar nada
 * (insistir não piora a trava de ninguém). A tentativa é registrada ANTES de conferir a senha e contada de novo, então
 * uma rajada em paralelo também esbarra no limite. Devolve os ids registrados, para lv_login_acertou apagar.
 */
function lv_login_tentar(string $tipo, string $email, int $limite): array
{
    $ip = lv_ip();
    $porEmailIp = 'ei:' . $email . '|' . $ip;
    $porIp = 'ip:' . $ip;
    $porEmail = 'e:' . $email;
    $travado = static fn(int $folga): bool => lv_contar_eventos($tipo, $porEmailIp, LV_JANELA_LOGIN) >= $limite + $folga
        || lv_contar_eventos($tipo, $porIp, LV_JANELA_LOGIN) >= 3 * $limite + $folga;
    if ($travado(0) || lv_contar_eventos($tipo, $porEmail, LV_JANELA_LOGIN) >= LV_LOGIN_FATOR_EMAIL * $limite) {
        lv_falhar(429, 'Muitas tentativas. Aguarde 15 minutos e tente de novo.');
    }
    $ids = [];
    foreach ([$porEmailIp, $porIp, $porEmail] as $chave) {
        lv_registrar($tipo, $chave);
        $ids[] = (int) lv_db()->lastInsertId();
    }
    if ($travado(1)) {
        lv_falhar(429, 'Muitas tentativas. Aguarde 15 minutos e tente de novo.');
    }
    return $ids;
}

/** Senha certa: a tentativa não conta como erro, e os erros anteriores deste e-mail neste IP são esquecidos. */
function lv_login_acertou(string $tipo, string $email, array $ids): void
{
    $pdo = lv_db();
    if ($ids) {
        $pdo->exec('DELETE FROM lv_eventos WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
    }
    $pdo->prepare('DELETE FROM lv_eventos WHERE tipo = ? AND chave = ?')->execute([$tipo, 'ei:' . $email . '|' . lv_ip()]);
}

function lv_contar_eventos(string $tipo, string $chave, int $segundos): int
{
    $stmt = lv_db()->prepare('SELECT COUNT(*) FROM lv_eventos WHERE tipo = ? AND chave = ? AND criado_em > ?');
    $stmt->execute([$tipo, mb_substr($chave, 0, 190), gmdate('Y-m-d H:i:s', time() - $segundos)]);
    return (int) $stmt->fetchColumn();
}

// ----------------------------------------------------------------------------- entrada
function lv_corpo_bruto(): string
{
    if (defined('LV_TESTE')) {
        return (string) ($GLOBALS['LV_CORPO_TESTE'] ?? '');
    }
    return (string) file_get_contents('php://input');
}

/**
 * Corpo JSON de um pedido do próprio site. Exigir application/json e conferir a origem barra o envio de
 * formulário a partir de outro site (CSRF): o navegador não manda esse tipo entre sites sem permissão (CORS).
 */
function lv_ler_json(): array
{
    lv_exigir_metodo('POST');
    $tipo = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (!str_starts_with($tipo, 'application/json')) {
        lv_falhar(415, 'Envie JSON.');
    }
    if (!lv_origem_do_site()) {
        lv_falhar(403, 'Origem não permitida.');
    }
    $dados = json_decode(lv_corpo_bruto(), true);
    if (!is_array($dados)) {
        lv_falhar(400, 'JSON inválido.');
    }
    return $dados;
}

function lv_origem_do_site(): bool
{
    $origem = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
    if ($origem === '') {
        return true;
    }
    $site = lv_site_url();
    $permitidas = [$site];
    if (preg_match('#^(https?://)(www\.)?(.+)$#', $site, $m)) {
        $permitidas[] = $m[1] . ($m[2] === '' ? 'www.' : '') . $m[3];
    }
    return in_array($origem, $permitidas, true);
}

function lv_texto(mixed $valor, int $max): string
{
    if (!is_scalar($valor)) {
        return '';
    }
    $texto = trim(preg_replace('/\s+/u', ' ', (string) $valor) ?? '');
    return mb_substr($texto, 0, $max);
}

function lv_digitos(string $texto): string
{
    return preg_replace('/\D+/', '', $texto) ?? '';
}

function lv_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function lv_email(mixed $valor): string
{
    $email = strtolower(lv_texto($valor, 190));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

/** Apelido público: 3 a 20 letras, números ou _ (a mesma regra do cadastro.html). Inválido = ''. */
function lv_apelido(mixed $valor): string
{
    $apelido = lv_texto($valor, 40);
    return preg_match('/^\w{3,20}$/', $apelido) ? $apelido : '';
}

/**
 * Apelido que imita uma modelo ou a equipe ("Livia_Voguel", "livia123", "suporte"): recusado no cadastro e na troca,
 * para ninguém pedir Pix no chat "como a modelo". Compara só as letras, sem acento e sem maiúsculas.
 */
function lv_apelido_reservado(string $apelido): bool
{
    $letras = static fn(string $s): string => preg_replace('/[^a-z]/', '', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s))) ?? '';
    $a = $letras($apelido);
    foreach (['admin', 'suporte', 'equipe', 'oficial', 'moderador', 'moderacao', 'staff'] as $palavra) {
        if (str_contains($a, $palavra)) {
            return true;
        }
    }
    foreach (LV_MODELOS as $slug => $m) {
        $nome = (string) ($m['nome'] ?? '');
        $primeiro = $letras((string) strtok($nome, ' '));
        if (str_contains($a, $letras($slug)) || str_contains($a, $letras($nome)) || ($primeiro !== '' && $a === $primeiro)) {
            return true;
        }
    }
    return false;
}

/**
 * Data de nascimento "AAAA-MM-DD" de quem tem 18 anos ou mais (e menos de 120), ou null. A idade é contada no dia de
 * hoje em Brasília: quem faz 18 hoje já passa.
 */
function lv_nascimento_maior(mixed $valor): ?string
{
    if (!is_string($valor) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    $hoje = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    $nascimento = new DateTimeImmutable($valor, new DateTimeZone('America/Sao_Paulo'));
    $idade = $nascimento->diff($hoje)->y;
    return $nascimento <= $hoje && $idade >= 18 && $idade < 120 ? $valor : null;
}

function lv_cpf_valido(string $cpf): bool
{
    $cpf = lv_digitos($cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }
    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += (int) $cpf[$i] * ($t + 1 - $i);
        }
        if ((int) $cpf[$t] !== ((10 * $soma) % 11) % 10) {
            return false;
        }
    }
    return true;
}

/** Telefone com DDD: 10 ou 11 dígitos (o 55 do país é opcional). Inválido = ''. */
function lv_telefone(string $texto): string
{
    $d = lv_digitos($texto);
    if (strlen($d) > 11 && str_starts_with($d, '55')) {
        $d = substr($d, 2);
    }
    return (strlen($d) === 10 || strlen($d) === 11) && $d[0] !== '0' ? $d : '';
}

function lv_luhn_valido(string $numero): bool
{
    if (!preg_match('/^\d{12,19}$/', $numero)) {
        return false;
    }
    $soma = 0;
    $dobrar = false;
    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        $n = (int) $numero[$i];
        if ($dobrar && ($n *= 2) > 9) {
            $n -= 9;
        }
        $soma += $n;
        $dobrar = !$dobrar;
    }
    return $soma % 10 === 0;
}

// ----------------------------------------------------------------------------- sessão
function lv_sessao(): void
{
    if (defined('LV_TESTE')) {
        $_SESSION ??= [];
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('lv_sessao');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        // Cookie só por HTTPS. Atrás de um proxy que termina o HTTPS, o PHP não vê HTTPS: vale o X-Forwarded-Proto.
        // (Forjar esse cabeçalho só deixaria o cookie mais restrito, nunca menos.) O .htaccess já força HTTPS.
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Marca da senha guardada na sessão no login. Trocar (ou redefinir) a senha muda a marca, e toda sessão aberta com a
 * senha antiga deixa de valer: quem roubou um cookie perde o acesso assim que a dona da conta troca a senha.
 */
function lv_marca_senha(string $senhaHash): string
{
    return substr(hash('sha256', 'lv-sessao|' . $senhaHash), 0, 32);
}

function lv_usuario_id(): ?int
{
    lv_sessao();
    $id = $_SESSION['usuario_id'] ?? null;
    if (!is_int($id) || $id <= 0) {
        return null;
    }
    $stmt = lv_db()->prepare('SELECT senha_hash FROM lv_usuarios WHERE id = ?');
    $stmt->execute([$id]);
    $hash = $stmt->fetchColumn();
    if ($hash === false || !hash_equals(lv_marca_senha((string) $hash), (string) ($_SESSION['usuario_marca'] ?? ''))) {
        unset($_SESSION['usuario_id'], $_SESSION['usuario_marca']);
        return null;
    }
    return $id;
}

function lv_exigir_login(): int
{
    return lv_usuario_id() ?? lv_falhar(401, 'Entre na sua conta para continuar.', ['login' => true]);
}

/** $senhaHash é o hash que ficou gravado (depois de um rehash, o novo). */
function lv_logar(int $usuarioId, string $senhaHash): void
{
    lv_sessao();
    if (!defined('LV_TESTE')) {
        session_regenerate_id(true);
    }
    $_SESSION['usuario_id'] = $usuarioId;
    $_SESSION['usuario_marca'] = lv_marca_senha($senhaHash);
}

function lv_usuario(int $id): ?array
{
    $stmt = lv_db()->prepare('SELECT id, email, apelido FROM lv_usuarios WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
