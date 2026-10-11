<?php
/**
 * Login da área da modelo. Fica separado do login de quem assiste: a sessão guarda `modelo_id`
 * (nunca `usuario_id`), então uma conta de cliente não abre o painel, e vice-versa.
 * Incluir depois de base.php.
 */
declare(strict_types=1);

/** Erros de login por e-mail na janela LV_JANELA_LOGIN (3× isso por IP). Mais rígido que o de cliente: são poucas contas e valem mais. */
const LV_MODELO_ERROS_LOGIN = 5;

/** Sessão da modelo dura no máximo isto, em segundos, mesmo com o navegador aberto (12 horas). */
const LV_MODELO_SESSAO_MAXIMA = 43200;

/** Hash de uma senha qualquer: compara mesmo quando o e-mail não existe, para o tempo de resposta não revelar quem tem conta. */
function lv_modelo_hash_falso(): string
{
    static $hash = null;
    return $hash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
}

function lv_modelo_conta(int $id): ?array
{
    $stmt = lv_db()->prepare('SELECT id, slug, email, senha_hash, ativa FROM lv_modelos_contas WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Dados públicos da modelo logada, para o painel montar a tela. */
function lv_modelo_publico(array $conta): array
{
    $slug = (string) $conta['slug'];
    return [
        'slug' => $slug,
        'nome' => LV_MODELOS[$slug]['nome'] ?? $slug,
        'email' => (string) $conta['email'],
    ];
}

/** Conta da modelo logada, ou null (sessão expirada, conta bloqueada ou apagada também dão null). */
function lv_modelo_logada(): ?array
{
    lv_sessao();
    $id = $_SESSION['modelo_id'] ?? null;
    $desde = $_SESSION['modelo_desde'] ?? 0;
    if (!is_int($id) || $id <= 0) {
        return null;
    }
    if (!is_int($desde) || time() - $desde > LV_MODELO_SESSAO_MAXIMA) {
        lv_modelo_deslogar();
        return null;
    }
    $conta = lv_modelo_conta($id);
    if (!$conta || !(int) $conta['ativa']) {
        lv_modelo_deslogar();
        return null;
    }
    return $conta;
}

function lv_exigir_modelo(): array
{
    return lv_modelo_logada() ?? lv_falhar(401, 'Entre na área da modelo para continuar.', ['login_modelo' => true]);
}

function lv_modelo_logar(int $id): void
{
    lv_sessao();
    if (!defined('LV_TESTE')) {
        session_regenerate_id(true);
    }
    $_SESSION['modelo_id'] = $id;
    $_SESSION['modelo_desde'] = time();
}

/** Sai só da área da modelo; se a mesma pessoa estiver logada como cliente, continua logada. */
function lv_modelo_deslogar(): void
{
    lv_sessao();
    unset($_SESSION['modelo_id'], $_SESSION['modelo_desde']);
    if (!defined('LV_TESTE') && session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}
