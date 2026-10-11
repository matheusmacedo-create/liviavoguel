<?php
/**
 * Painel da equipe: login próprio (sessão `equipe_id`, separada de cliente e de modelo), saques e contas de modelo.
 * Incluir depois de base.php, modelo-conta.php, saques.php e stream.php.
 */
declare(strict_types=1);

/** Sessão da equipe dura no máximo isto, em segundos (8 horas). */
const LV_EQUIPE_SESSAO_MAXIMA = 28800;
/** Erros de login por e-mail na janela LV_JANELA_LOGIN (3× isso por IP). */
const LV_EQUIPE_ERROS_LOGIN = 5;

function lv_equipe_conta(int $id): ?array
{
    $stmt = lv_db()->prepare('SELECT id, email, senha_hash, ativa FROM lv_equipe_contas WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function lv_equipe_logada(): ?array
{
    lv_sessao();
    $id = $_SESSION['equipe_id'] ?? null;
    $desde = $_SESSION['equipe_desde'] ?? 0;
    if (!is_int($id) || $id <= 0) {
        return null;
    }
    $conta = (is_int($desde) && time() - $desde <= LV_EQUIPE_SESSAO_MAXIMA) ? lv_equipe_conta($id) : null;
    if (!$conta || !(int) $conta['ativa']) {
        lv_equipe_deslogar();
        return null;
    }
    return $conta;
}

function lv_exigir_equipe(): array
{
    return lv_equipe_logada() ?? lv_falhar(401, 'Entre no painel da equipe para continuar.', ['login_equipe' => true]);
}

function lv_equipe_logar(int $id): void
{
    lv_sessao();
    if (!defined('LV_TESTE')) {
        session_regenerate_id(true);
    }
    $_SESSION['equipe_id'] = $id;
    $_SESSION['equipe_desde'] = time();
}

function lv_equipe_deslogar(): void
{
    lv_sessao();
    unset($_SESSION['equipe_id'], $_SESSION['equipe_desde']);
    if (!defined('LV_TESTE') && session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/** Saques para a equipe: pendentes primeiro (mais antigos em cima), depois os últimos resolvidos. */
function lv_equipe_saques(): array
{
    $iso = static fn(?string $t): ?string => $t === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($t . ' UTC'));
    $linha = static fn(array $s): array => [
        'id' => (int) $s['id'], 'modelo' => (string) $s['slug'], 'nome' => LV_MODELOS[$s['slug']]['nome'] ?? (string) $s['slug'],
        'creditos' => (int) $s['creditos'], 'centavos' => (int) $s['valor_centavos'],
        'pix' => ['tipo' => (string) $s['tipo_chave'], 'chave' => (string) $s['chave']],
        'status' => (string) $s['status'], 'observacao' => $s['observacao'],
        'pedido_em' => $iso($s['pedido_em']), 'pago_em' => $iso($s['pago_em']),
    ];
    $pendentes = lv_db()->query("SELECT * FROM lv_saques WHERE status = 'pendente' ORDER BY id ASC")->fetchAll();
    $resolvidos = lv_db()->query("SELECT * FROM lv_saques WHERE status <> 'pendente' ORDER BY id DESC LIMIT 50")->fetchAll();
    return ['pendentes' => array_map($linha, $pendentes), 'resolvidos' => array_map($linha, $resolvidos)];
}

/** Resolve um saque pendente. Devolve null se deu certo ou a mensagem de erro. Só sai de "pendente" (uma vez só). */
function lv_equipe_resolver_saque(int $id, string $status, ?string $observacao): ?string
{
    $sql = $status === 'pago'
        ? "UPDATE lv_saques SET status = 'pago', pago_em = ?, observacao = ? WHERE id = ? AND status = 'pendente'"
        : "UPDATE lv_saques SET status = 'recusado', observacao = ? WHERE id = ? AND status = 'pendente'";
    $params = $status === 'pago' ? [lv_agora(), $observacao, $id] : [$observacao, $id];
    $stmt = lv_db()->prepare($sql);
    $stmt->execute($params);
    if ($stmt->rowCount() === 1) {
        return null;
    }
    $existe = lv_db()->prepare('SELECT status FROM lv_saques WHERE id = ?');
    $existe->execute([$id]);
    $atual = $existe->fetchColumn();
    return $atual === false ? 'Saque não encontrado.' : 'Esse saque já foi marcado como ' . $atual . '.';
}

/** Contas de modelo para a equipe, com o status ao vivo e os ganhos disponíveis. */
function lv_equipe_modelos(): array
{
    $aoVivo = lv_ao_vivo_todas();
    $contas = lv_db()->query('SELECT id, slug, email, ativa, criado_em, ultimo_login_em FROM lv_modelos_contas ORDER BY slug')->fetchAll();
    return array_map(static fn(array $c): array => [
        'id' => (int) $c['id'], 'slug' => (string) $c['slug'], 'nome' => LV_MODELOS[$c['slug']]['nome'] ?? (string) $c['slug'],
        'email' => (string) $c['email'], 'ativa' => (bool) $c['ativa'],
        'online' => (bool) ($aoVivo[$c['slug']]['online'] ?? false),
        'ultimo_login' => $c['ultimo_login_em'] ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($c['ultimo_login_em'] . ' UTC')) : null,
        'disponivel' => lv_saque_disponivel((string) $c['slug']),
        'stream' => lv_stream_config((string) $c['slug']),
    ], $contas);
}

/** Bloqueia (ativa = 0: a sessão dela cai e a sala é desligada) ou reativa uma conta de modelo. */
function lv_equipe_ativar_modelo(int $id, bool $ativa): ?string
{
    $conta = lv_modelo_conta($id);
    if ($conta === null) {
        return 'Conta de modelo não encontrada.';
    }
    lv_db()->prepare('UPDATE lv_modelos_contas SET ativa = ? WHERE id = ?')->execute([$ativa ? 1 : 0, $id]);
    if (!$ativa) {
        lv_ao_vivo_definir((string) $conta['slug'], false);
    }
    return null;
}
