<?php
/** Chat das salas: mensagens, avisos de gorjeta, palavras bloqueadas e freios. Incluir depois de base.php e modelo-conta.php. */
declare(strict_types=1);

/** Tamanho máximo de uma mensagem e do recado de uma gorjeta. */
const LV_CHAT_MAX = 200;
const LV_RECADO_MAX = 80;
/** Freios por pessoa: intervalo mínimo entre mensagens (s) e máximo por minuto. */
const LV_CHAT_INTERVALO = 2;
const LV_CHAT_POR_MINUTO = 20;
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

function lv_chat_postar(string $slug, ?int $usuarioId, string $apelido, string $tipo, string $texto, ?int $creditos = null): int
{
    lv_db()->prepare('INSERT INTO lv_chat (slug, usuario_id, apelido, tipo, texto, creditos, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$slug, $usuarioId, mb_substr($apelido, 0, 20), $tipo, mb_substr($texto, 0, 255), $creditos, lv_agora()]);
    return (int) lv_db()->lastInsertId();
}

/** Mensagens da sala com id maior que $depois (as últimas LV_CHAT_LOTE quando $depois = 0), da mais antiga para a mais nova. */
function lv_chat_listar(string $slug, int $depois = 0): array
{
    if ($depois > 0) {
        $stmt = lv_db()->prepare('SELECT id, tipo, apelido, texto, creditos, criado_em FROM lv_chat WHERE slug = ? AND id > ? ORDER BY id ASC LIMIT ' . LV_CHAT_LOTE);
        $stmt->execute([$slug, $depois]);
        $linhas = $stmt->fetchAll();
    } else {
        $stmt = lv_db()->prepare('SELECT id, tipo, apelido, texto, creditos, criado_em FROM lv_chat WHERE slug = ? ORDER BY id DESC LIMIT ' . LV_CHAT_LOTE);
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
    ], $linhas);
}
