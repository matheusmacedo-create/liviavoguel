<?php
/** Fotos da modelo: envio, conferência, regravação em JPG, capa e galeria. Incluir depois de base.php. */
declare(strict_types=1);

const LV_FOTO_MAX_BYTES = 5 * 1024 * 1024;
const LV_FOTO_MAX_POR_MODELO = 12;
const LV_FOTO_LADO_MAX = 1600;   // lado maior depois de regravada
const LV_FOTO_LADO_MIN = 300;    // menor que isso fica feio na capa
const LV_FOTO_TIPOS = ['image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/webp' => 'webp'];

/** Pasta das fotos no disco (config UPLOADS_DIR só para testes) e o caminho público, relativo à raiz do site. */
function lv_fotos_pasta(string $slug): string
{
    $base = (string) lv_cfg('UPLOADS_DIR', dirname(__DIR__, 2) . '/uploads');
    return rtrim($base, '/') . '/modelos/' . $slug;
}

function lv_foto_url(string $slug, string $arquivo): string
{
    return 'uploads/modelos/' . rawurlencode($slug) . '/' . rawurlencode($arquivo);
}

/** O arquivo veio mesmo de um envio do navegador? (nos testes, qualquer arquivo serve) */
function lv_foto_enviada(string $tmp): bool
{
    return defined('LV_TESTE') ? is_file($tmp) : is_uploaded_file($tmp);
}

/**
 * Confere a imagem enviada e regrava como JPG (tira EXIF, localização e qualquer coisa escondida no arquivo).
 * Devolve [arquivo, largura, altura, null] ou [null, null, null, mensagem].
 */
function lv_foto_processar(array $envio, string $pasta): array
{
    $erro = static fn(string $m): array => [null, null, null, $m];
    if (($envio['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($envio['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
        return $erro('A foto pode ter até 5 MB.');
    }
    $tmp = (string) ($envio['tmp_name'] ?? '');
    if (($envio['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !lv_foto_enviada($tmp)) {
        return $erro('Escolha uma foto para enviar.');
    }
    if (filesize($tmp) > LV_FOTO_MAX_BYTES) {
        return $erro('A foto pode ter até 5 MB.');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $tipo = LV_FOTO_TIPOS[$mime] ?? null;
    $info = $tipo ? @getimagesize($tmp) : false;
    if ($tipo === null || !$info) {
        return $erro('Envie uma foto em JPG, PNG ou WebP.');
    }
    [$w, $h] = $info;
    if ($w < LV_FOTO_LADO_MIN || $h < LV_FOTO_LADO_MIN) {
        return $erro('A foto precisa ter pelo menos ' . LV_FOTO_LADO_MIN . ' × ' . LV_FOTO_LADO_MIN . ' pixels.');
    }
    if ($w * $h > 40_000_000) {
        return $erro('A foto é grande demais (mais de 40 megapixels).');
    }
    $img = match ($tipo) {
        'jpeg' => @imagecreatefromjpeg($tmp),
        'png' => @imagecreatefrompng($tmp),
        'webp' => @imagecreatefromwebp($tmp),
    };
    if (!$img) {
        return $erro('Não deu para ler essa foto. Tente outra.');
    }
    // Celular grava a foto deitada e diz no EXIF como girar: aplica antes de regravar (o EXIF não vai junto).
    if ($tipo === 'jpeg' && function_exists('exif_read_data')) {
        $giro = [3 => 180, 6 => -90, 8 => 90][(int) (@exif_read_data($tmp)['Orientation'] ?? 1)] ?? 0;
        if ($giro !== 0) {
            $img = imagerotate($img, $giro, 0);
        }
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $escala = min(1, LV_FOTO_LADO_MAX / max($w, $h));
    $nw = max(1, (int) round($w * $escala));
    $nh = max(1, (int) round($h * $escala));
    $saida = imagecreatetruecolor($nw, $nh);
    imagefill($saida, 0, 0, imagecolorallocate($saida, 13, 7, 16)); // PNG transparente fica sobre o fundo do site
    imagecopyresampled($saida, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    if (!is_dir($pasta) && !mkdir($pasta, 0755, true) && !is_dir($pasta)) {
        throw new RuntimeException('não deu para criar a pasta das fotos');
    }
    $arquivo = bin2hex(random_bytes(12)) . '.jpg';
    if (!imagejpeg($saida, $pasta . '/' . $arquivo, 85)) {
        throw new RuntimeException('não deu para gravar a foto');
    }
    return [$arquivo, $nw, $nh, null];
}

function lv_fotos_da_modelo(string $slug): array
{
    $stmt = lv_db()->prepare('SELECT id, arquivo, capa, ordem, largura, altura FROM lv_modelos_fotos WHERE slug = ? ORDER BY capa DESC, ordem ASC, id ASC');
    $stmt->execute([$slug]);
    return array_map(static fn(array $f): array => [
        'id' => (int) $f['id'], 'url' => lv_foto_url($slug, (string) $f['arquivo']), 'capa' => (bool) $f['capa'],
        'largura' => (int) $f['largura'], 'altura' => (int) $f['altura'],
    ], $stmt->fetchAll());
}

/** Fotos para o público: [slug => ['foto' => url da capa, 'galeria' => [urls]]], só de quem enviou fotos. */
function lv_fotos_publicas(): array
{
    $saida = [];
    foreach (array_keys(LV_MODELOS) as $slug) {
        $fotos = lv_fotos_da_modelo($slug);
        if (!$fotos) {
            continue;
        }
        $saida[$slug] = ['foto' => $fotos[0]['url'], 'galeria' => array_map(static fn($f) => $f['url'], array_slice($fotos, 1))];
    }
    return $saida;
}

function lv_foto_adicionar(string $slug, string $arquivo, int $largura, int $altura): int
{
    $pdo = lv_db();
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(MAX(ordem), 0) AS ultima, COALESCE(SUM(capa), 0) AS capas FROM lv_modelos_fotos WHERE slug = ?');
    $stmt->execute([$slug]);
    $l = $stmt->fetch();
    $pdo->prepare('INSERT INTO lv_modelos_fotos (slug, arquivo, capa, ordem, largura, altura, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$slug, $arquivo, (int) $l['capas'] === 0 ? 1 : 0, (int) $l['ultima'] + 1, $largura, $altura, lv_agora()]);
    return (int) $pdo->lastInsertId();
}

function lv_fotos_contar(string $slug): int
{
    $stmt = lv_db()->prepare('SELECT COUNT(*) FROM lv_modelos_fotos WHERE slug = ?');
    $stmt->execute([$slug]);
    return (int) $stmt->fetchColumn();
}

/** A foto $id é da modelo $slug? Devolve a linha ou null. */
function lv_foto_da_modelo(string $slug, int $id): ?array
{
    $stmt = lv_db()->prepare('SELECT id, arquivo, capa FROM lv_modelos_fotos WHERE id = ? AND slug = ?');
    $stmt->execute([$id, $slug]);
    return $stmt->fetch() ?: null;
}

function lv_foto_capa(string $slug, int $id): void
{
    $pdo = lv_db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE lv_modelos_fotos SET capa = 0 WHERE slug = ?')->execute([$slug]);
    $pdo->prepare('UPDATE lv_modelos_fotos SET capa = 1 WHERE slug = ? AND id = ?')->execute([$slug, $id]);
    $pdo->commit();
}

/** Apaga a foto do banco e do disco. Se era a capa, a próxima da galeria vira capa. */
function lv_foto_apagar(string $slug, array $foto): void
{
    $pdo = lv_db();
    $pdo->prepare('DELETE FROM lv_modelos_fotos WHERE id = ? AND slug = ?')->execute([(int) $foto['id'], $slug]);
    $caminho = lv_fotos_pasta($slug) . '/' . basename((string) $foto['arquivo']);
    if (is_file($caminho)) {
        @unlink($caminho);
    }
    if ((int) $foto['capa'] === 1) {
        $proxima = lv_fotos_da_modelo($slug)[0]['id'] ?? null;
        if ($proxima !== null) {
            lv_foto_capa($slug, $proxima);
        }
    }
}
