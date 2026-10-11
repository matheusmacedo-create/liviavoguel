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
