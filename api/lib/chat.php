<?php
/** Chat das salas: mensagens, avisos de gorjeta, palavras bloqueadas e freios. Incluir depois de base.php e modelo-conta.php. */
declare(strict_types=1);

/** Tamanho máximo de uma mensagem e do recado de uma gorjeta. */
const LV_CHAT_MAX = 200;
const LV_RECADO_MAX = 80;
/** Freios por pessoa: intervalo mínimo entre mensagens (s) e máximo por minuto. */
const LV_CHAT_INTERVALO = 2;
const LV_CHAT_POR_MINUTO = 20;
/** Máximo por minuto vindo de um mesmo IP, somando todas as contas. */
const LV_CHAT_POR_MINUTO_IP = 60;
/** Quantas mensagens a sala recebe de uma vez (ao abrir e a cada consulta). */
const LV_CHAT_LOTE = 50;

/** Minúsculas e sem acentos, para comparar com as palavras bloqueadas (o mesmo que o salas.js faz). */
function lv_chat_simples(string $texto): string
{
    $texto = mb_strtolower($texto);
    if (class_exists('Normalizer')) {
        return preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($texto, Normalizer::FORM_D)) ?? $texto;
    }
    return strtr($texto, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
}

/** A palavra bloqueada pela modelo que aparece no texto, ou null. */
function lv_chat_bloqueada(string $slug, string $texto): ?string
{
    $alvo = lv_chat_simples($texto);
    foreach (lv_salas()[$slug]['bloqueadas'] ?? [] as $palavra) {
        if ($palavra !== '' && str_contains($alvo, lv_chat_simples((string) $palavra))) {
            return (string) $palavra;
        }
    }
    return null;
}

/** Apelido com palavra que a modelo bloqueou (um telefone, "zap") não aparece: vira "anônimo". */
function lv_chat_apelido(string $slug, string $apelido): string
{
    return lv_chat_bloqueada($slug, $apelido) !== null ? 'anônimo' : $apelido;
}

function lv_chat_postar(string $slug, ?int $usuarioId, string $apelido, string $tipo, string $texto, ?int $creditos = null): int
{
    $apelido = lv_chat_apelido($slug, $apelido);
    lv_db()->prepare('INSERT INTO lv_chat (slug, usuario_id, apelido, tipo, texto, creditos, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$slug, $usuarioId, mb_substr($apelido, 0, 20), $tipo, mb_substr($texto, 0, 255), $creditos, lv_agora()]);
    return (int) lv_db()->lastInsertId();
}

/**
 * Mensagens da sala com id maior que $depois (as últimas LV_CHAT_LOTE quando $depois = 0), da mais antiga para a mais
 * nova, sem as apagadas pela modelo. Com $comAutor, inclui `usuario` (id interno de quem escreveu), só para o painel.
 */
function lv_chat_listar(string $slug, int $depois = 0, bool $comAutor = false): array
{
    $base = 'SELECT c.id, c.usuario_id, c.tipo, c.apelido, c.texto, c.creditos, c.criado_em FROM lv_chat c
        LEFT JOIN lv_chat_apagadas a ON a.chat_id = c.id WHERE c.slug = ? AND a.chat_id IS NULL';
    if ($depois > 0) {
        $stmt = lv_db()->prepare($base . ' AND c.id > ? ORDER BY c.id ASC LIMIT ' . LV_CHAT_LOTE);
        $stmt->execute([$slug, $depois]);
        $linhas = $stmt->fetchAll();
    } else {
        $stmt = lv_db()->prepare($base . ' ORDER BY c.id DESC LIMIT ' . LV_CHAT_LOTE);
        $stmt->execute([$slug]);
        $linhas = array_reverse($stmt->fetchAll());
    }
    return array_map(static fn(array $l): array => [
        'id' => (int) $l['id'],
        'tipo' => (string) $l['tipo'],
        'apelido' => (string) $l['apelido'],
        'texto' => (string) $l['texto'],
        'creditos' => $l['creditos'] === null ? null : (int) $l['creditos'],
        'data' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($l['criado_em'] . ' UTC')),
    ] + ($comAutor ? ['usuario' => $l['usuario_id'] === null ? null : (int) $l['usuario_id']] : []), $linhas);
}

// ----------------------------------------------------------------------------- moderação (pela modelo)

/** Por quanto tempo (s) as salas abertas recebem os ids apagados, para tirá-los da tela. */
const LV_CHAT_APAGADAS_JANELA = 600;

function lv_chat_bloqueado(string $slug, int $usuarioId): bool
{
    $stmt = lv_db()->prepare('SELECT 1 FROM lv_chat_bloqueios WHERE slug = ? AND usuario_id = ?');
    $stmt->execute([$slug, $usuarioId]);
    return (bool) $stmt->fetchColumn();
}

/** Ids apagados nos últimos LV_CHAT_APAGADAS_JANELA segundos (as salas abertas tiram da tela). */
function lv_chat_apagadas_recentes(string $slug): array
{
    $stmt = lv_db()->prepare('SELECT chat_id FROM lv_chat_apagadas WHERE slug = ? AND apagada_em > ?');
    $stmt->execute([$slug, gmdate('Y-m-d H:i:s', time() - LV_CHAT_APAGADAS_JANELA)]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** A mensagem $id da sala $slug, ou null (de outra sala também dá null: a modelo só mexe na própria). */
function lv_chat_mensagem(string $slug, int $id): ?array
{
    $stmt = lv_db()->prepare('SELECT id, usuario_id, apelido, tipo FROM lv_chat WHERE id = ? AND slug = ?');
    $stmt->execute([$id, $slug]);
    return $stmt->fetch() ?: null;
}

/** Apaga uma mensagem da sala (repetir não faz mal). */
function lv_chat_apagar(string $slug, int $id): void
{
    try {
        lv_db()->prepare('INSERT INTO lv_chat_apagadas (chat_id, slug, apagada_em) VALUES (?, ?, ?)')->execute([$id, $slug, lv_agora()]);
    } catch (PDOException $e) {
        if (!lv_e_duplicado($e)) {
            throw $e;
        }
    }
}

/** Bloqueia quem escreveu e apaga as mensagens dessa pessoa na sala, inclusive gorjetas com recado (as sem recado ficam). */
function lv_chat_bloquear(string $slug, int $usuarioId): void
{
    try {
        lv_db()->prepare('INSERT INTO lv_chat_bloqueios (slug, usuario_id, criado_em) VALUES (?, ?, ?)')->execute([$slug, $usuarioId, lv_agora()]);
    } catch (PDOException $e) {
        if (!lv_e_duplicado($e)) {
            throw $e;
        }
    }
    $stmt = lv_db()->prepare("SELECT id FROM lv_chat WHERE slug = ? AND usuario_id = ? AND (tipo = 'msg' OR texto <> '')
        AND id NOT IN (SELECT chat_id FROM lv_chat_apagadas WHERE slug = ?)");
    $stmt->execute([$slug, $usuarioId, $slug]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        lv_chat_apagar($slug, (int) $id);
    }
}

function lv_chat_desbloquear(string $slug, int $usuarioId): void
{
    lv_db()->prepare('DELETE FROM lv_chat_bloqueios WHERE slug = ? AND usuario_id = ?')->execute([$slug, $usuarioId]);
}

/** Quem está bloqueado na sala: [{ usuario, apelido, desde }]. `usuario` é o id interno (a modelo nunca vê e-mail). */
function lv_chat_bloqueados(string $slug): array
{
    $stmt = lv_db()->prepare('SELECT b.usuario_id, u.apelido, b.criado_em FROM lv_chat_bloqueios b JOIN lv_usuarios u ON u.id = b.usuario_id
        WHERE b.slug = ? ORDER BY b.criado_em DESC');
    $stmt->execute([$slug]);
    return array_map(static fn(array $l): array => [
        'usuario' => (int) $l['usuario_id'],
        'apelido' => (string) $l['apelido'],
        'desde' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($l['criado_em'] . ' UTC')),
    ], $stmt->fetchAll());
}
