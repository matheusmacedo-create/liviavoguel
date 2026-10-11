<?php
/** Configuração, respostas JSON, banco (PDO), sessão, leitura do corpo e freios. Incluído por todos os endpoints. */
declare(strict_types=1);

require_once __DIR__ . '/pacotes.php';
require_once __DIR__ . '/modelos.php';
require_once __DIR__ . '/unicopag.php';
require_once __DIR__ . '/creditos.php';

/** Freios: cobranças por usuário por hora; erros de login por e-mail (e 3× isso por IP) na janela, em segundos. */
const LV_COBRANCAS_POR_HORA = 15;
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
    return $pdo;
}

function lv_registrar(string $tipo, string $chave, ?string $detalhe = null): void
{
    lv_db()->prepare('INSERT INTO lv_eventos (tipo, chave, detalhe, criado_em) VALUES (?, ?, ?, ?)')
        ->execute([$tipo, mb_substr($chave, 0, 190), $detalhe !== null ? mb_substr($detalhe, 0, 255) : null, lv_agora()]);
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
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function lv_usuario_id(): ?int
{
    lv_sessao();
    $id = $_SESSION['usuario_id'] ?? null;
    return is_int($id) && $id > 0 ? $id : null;
}

function lv_exigir_login(): int
{
    return lv_usuario_id() ?? lv_falhar(401, 'Entre na sua conta para continuar.', ['login' => true]);
}

function lv_logar(int $usuarioId): void
{
    lv_sessao();
    if (!defined('LV_TESTE')) {
        session_regenerate_id(true);
    }
    $_SESSION['usuario_id'] = $usuarioId;
}

function lv_usuario(int $id): ?array
{
    $stmt = lv_db()->prepare('SELECT id, email, apelido FROM lv_usuarios WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
