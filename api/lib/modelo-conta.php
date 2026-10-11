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

// ----------------------------------------------------------------------------- ganhos

/** Repasse padrão da modelo por crédito recebido, em centavos. No servidor, MODELO_REPASSE_CENTAVOS no config.php muda. */
const LV_MODELO_REPASSE_PADRAO = 20;

/** Os ganhos são contados no fuso de Brasília ("hoje" e "este mês" viram à meia-noite daqui, não do UTC). */
const LV_MODELO_FUSO = 'America/Sao_Paulo';

function lv_modelo_repasse_centavos(): int
{
    return max(0, (int) lv_cfg('MODELO_REPASSE_CENTAVOS', LV_MODELO_REPASSE_PADRAO));
}

/** Início de um dia/mês no fuso de Brasília, como texto UTC no formato do banco. */
function lv_modelo_inicio(string $quando, ?int $agora = null): string
{
    $fuso = new DateTimeZone(LV_MODELO_FUSO);
    $data = (new DateTimeImmutable('@' . ($agora ?? time())))->setTimezone($fuso)->modify($quando);
    return $data->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Créditos que a modelo recebeu (gorjetas e minutos de privado) entre $de (incluso) e $ate (excluso), em UTC. */
function lv_modelo_soma(string $slug, ?string $de = null, ?string $ate = null, ?string $motivo = null): array
{
    $sql = "SELECT COALESCE(SUM(-creditos), 0) AS creditos, COUNT(*) AS quantidade FROM lv_extrato
            WHERE modelo = ? AND motivo IN ('gorjeta', 'privado')";
    $params = [$slug];
    foreach ([['criado_em >= ?', $de], ['criado_em < ?', $ate], ['motivo = ?', $motivo]] as [$condicao, $valor]) {
        if ($valor !== null) {
            $sql .= ' AND ' . $condicao;
            $params[] = $valor;
        }
    }
    $stmt = lv_db()->prepare($sql);
    $stmt->execute($params);
    $linha = $stmt->fetch();
    $creditos = (int) $linha['creditos'];
    return ['creditos' => $creditos, 'centavos' => $creditos * lv_modelo_repasse_centavos(), 'quantidade' => (int) $linha['quantidade']];
}

/** Lançamentos recebidos pela modelo, do mais novo ao mais antigo. Mostra só o apelido público de quem mandou. */
function lv_modelo_lancamentos(string $slug, ?string $de = null, ?string $ate = null, int $limite = 200): array
{
    $sql = "SELECT e.creditos, e.motivo, e.criado_em, u.apelido FROM lv_extrato e JOIN lv_usuarios u ON u.id = e.usuario_id
            WHERE e.modelo = ? AND e.motivo IN ('gorjeta', 'privado')";
    $params = [$slug];
    if ($de !== null) {
        $sql .= ' AND e.criado_em >= ?';
        $params[] = $de;
    }
    if ($ate !== null) {
        $sql .= ' AND e.criado_em < ?';
        $params[] = $ate;
    }
    $stmt = lv_db()->prepare($sql . ' ORDER BY e.id DESC LIMIT ' . max(1, min(500, $limite)));
    $stmt->execute($params);
    $repasse = lv_modelo_repasse_centavos();
    return array_map(static fn(array $l): array => [
        'data' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($l['criado_em'] . ' UTC')),
        'tipo' => (string) $l['motivo'],
        'cliente' => (string) $l['apelido'],
        'creditos' => -(int) $l['creditos'],
        'centavos' => -(int) $l['creditos'] * $repasse,
    ], $stmt->fetchAll());
}


// ----------------------------------------------------------------------------- ao vivo

/** Sem aviso do painel por mais que isto (em segundos), a modelo aparece offline. O painel avisa a cada minuto. */
const LV_AO_VIVO_VALIDADE = 300;

/** Status de todas as modelos de LV_MODELOS: [slug => ['online' => bool, 'desde' => ISO|null]]. */
function lv_ao_vivo_todas(): array
{
    $limite = gmdate('Y-m-d H:i:s', time() - LV_AO_VIVO_VALIDADE);
    $linhas = lv_db()->query('SELECT slug, online, desde, visto_em FROM lv_modelos_ao_vivo')->fetchAll();
    $porSlug = array_column($linhas, null, 'slug');
    $saida = [];
    foreach (array_keys(LV_MODELOS) as $slug) {
        $l = $porSlug[$slug] ?? null;
        $online = $l !== null && (int) $l['online'] === 1 && $l['visto_em'] >= $limite;
        $saida[$slug] = [
            'online' => $online,
            'desde' => $online && $l['desde'] ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($l['desde'] . ' UTC')) : null,
        ];
    }
    return $saida;
}

/**
 * Liga, desliga ou renova (aviso do painel) o status da modelo. Ligar quando já estava ao vivo mantém o `desde`.
 * UPDATE e, se não havia linha, INSERT: funciona igual no MySQL e no SQLite dos testes.
 */
function lv_ao_vivo_definir(string $slug, bool $online): array
{
    $pdo = lv_db();
    $agora = lv_agora();
    $atual = lv_ao_vivo_todas()[$slug] ?? ['online' => false];
    $desde = $online ? ($atual['online'] ? null : $agora) : null; // null no UPDATE abaixo = mantém
    $sql = $online
        ? 'UPDATE lv_modelos_ao_vivo SET online = 1, desde = COALESCE(?, desde), visto_em = ? WHERE slug = ?'
        : 'UPDATE lv_modelos_ao_vivo SET online = 0, desde = NULL, visto_em = ? WHERE slug = ?';
    $params = $online ? [$desde, $agora, $slug] : [$agora, $slug];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) {
        try {
            $pdo->prepare('INSERT INTO lv_modelos_ao_vivo (slug, online, desde, visto_em) VALUES (?, ?, ?, ?)')
                ->execute([$slug, $online ? 1 : 0, $online ? $agora : null, $agora]);
        } catch (PDOException $e) {
            if (!lv_e_duplicado($e)) {
                throw $e;
            }
            $pdo->prepare($sql)->execute($params); // outra aba criou a linha ao mesmo tempo
        }
    }
    return lv_ao_vivo_todas()[$slug];
}
