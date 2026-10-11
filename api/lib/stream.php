<?php
/** Transmissão: link HLS que a sala toca e dados do OBS (servidor e chave) de cada modelo. Incluir depois de base.php. */
declare(strict_types=1);

function lv_stream_config(string $slug): ?array
{
    $stmt = lv_db()->prepare('SELECT hls_url, rtmp_servidor, rtmp_chave FROM lv_modelos_stream WHERE slug = ?');
    $stmt->execute([$slug]);
    $l = $stmt->fetch();
    return $l ? ['hls_url' => (string) $l['hls_url'], 'rtmp_servidor' => (string) $l['rtmp_servidor'], 'rtmp_chave' => (string) $l['rtmp_chave']] : null;
}

/**
 * Confere os dados da transmissão vindos da equipe. Devolve [config, null] ou [null, [campo, mensagem]].
 * Tudo vazio = apagar a configuração. HLS: https e um .m3u8. Servidor: rtmp:// ou rtmps://.
 */
function lv_stream_validar(array $b): array
{
    $hls = trim(is_string($b['hls_url'] ?? null) ? $b['hls_url'] : '');
    $servidor = trim(is_string($b['rtmp_servidor'] ?? null) ? $b['rtmp_servidor'] : '');
    $chave = trim(is_string($b['rtmp_chave'] ?? null) ? $b['rtmp_chave'] : '');
    if ($hls === '' && $servidor === '' && $chave === '') {
        return [['hls_url' => '', 'rtmp_servidor' => '', 'rtmp_chave' => ''], null];
    }
    $partes = parse_url($hls);
    if (strlen($hls) > 255 || !$partes || ($partes['scheme'] ?? '') !== 'https' || empty($partes['host'])
        || !str_ends_with(strtolower($partes['path'] ?? ''), '.m3u8')) {
        return [null, ['hls_url', 'O link do player precisa começar com https:// e terminar em .m3u8.']];
    }
    if (strlen($servidor) > 255 || !preg_match('#^rtmps?://[^\s/]+(/\S*)?$#i', $servidor)) {
        return [null, ['rtmp_servidor', 'O servidor do OBS precisa começar com rtmp:// ou rtmps://.']];
    }
    if ($chave === '' || strlen($chave) > 255 || preg_match('/\s/', $chave)) {
        return [null, ['rtmp_chave', 'Cole a chave de transmissão (sem espaços).']];
    }
    return [['hls_url' => $hls, 'rtmp_servidor' => $servidor, 'rtmp_chave' => $chave], null];
}

function lv_stream_salvar(string $slug, array $c): void
{
    $pdo = lv_db();
    if ($c['hls_url'] === '') {
        $pdo->prepare('DELETE FROM lv_modelos_stream WHERE slug = ?')->execute([$slug]);
        return;
    }
    $pdo->prepare('DELETE FROM lv_modelos_stream WHERE slug = ?')->execute([$slug]);
    $pdo->prepare('INSERT INTO lv_modelos_stream (slug, hls_url, rtmp_servidor, rtmp_chave, atualizado_em) VALUES (?, ?, ?, ?, ?)')
        ->execute([$slug, $c['hls_url'], $c['rtmp_servidor'], $c['rtmp_chave'], lv_agora()]);
}

/** Freio para quem tenta adivinhar chaves em api/stream-evento.php: avisos com chave errada por IP em 15 minutos. */
const LV_STREAM_ERROS = 20;

/** Modelo (slug) dona da chave de transmissão, só se a conta dela estiver ativa. Aceita "live/<chave>" (caminho). */
function lv_stream_dona_da_chave(string $chave): ?string
{
    $chave = basename(str_replace('\\', '/', trim($chave)));
    $chave = explode('?', $chave, 2)[0]; // o nginx-rtmp manda junto o que vier depois de "?" na chave
    if ($chave === '' || strlen($chave) > 255) {
        return null;
    }
    $stmt = lv_db()->prepare('SELECT s.slug FROM lv_modelos_stream s JOIN lv_modelos_contas c ON c.slug = s.slug
        WHERE s.rtmp_chave = ? AND c.ativa = 1');
    $stmt->execute([$chave]);
    $slug = $stmt->fetchColumn();
    return $slug === false ? null : (string) $slug;
}

/** O OBS da modelo começou (ou continua) a transmitir: abre ou renova o aviso. Devolve true se acabou de começar. */
function lv_stream_publicando(string $slug): bool
{
    $pdo = lv_db();
    $agora = lv_agora();
    $limite = gmdate('Y-m-d H:i:s', time() - LV_PUBLICANDO_VALIDADE);
    $stmt = $pdo->prepare('UPDATE lv_modelos_publicando SET visto_em = ? WHERE slug = ? AND visto_em >= ?');
    $stmt->execute([$agora, $slug, $limite]);
    if ($stmt->rowCount() > 0) {
        return false;
    }
    // Sem linha, ou uma velha (o aviso de fim se perdeu): começa de novo.
    $pdo->prepare('DELETE FROM lv_modelos_publicando WHERE slug = ?')->execute([$slug]);
    try {
        $pdo->prepare('INSERT INTO lv_modelos_publicando (slug, desde, visto_em) VALUES (?, ?, ?)')->execute([$slug, $agora, $agora]);
    } catch (PDOException $e) {
        if (!lv_e_duplicado($e)) {
            throw $e;
        }
        return false; // outro aviso chegou junto
    }
    return true;
}

/** O OBS parou: tira o aviso. Devolve true se havia transmissão. */
function lv_stream_parou(string $slug): bool
{
    $stmt = lv_db()->prepare('DELETE FROM lv_modelos_publicando WHERE slug = ?');
    $stmt->execute([$slug]);
    return $stmt->rowCount() > 0;
}
