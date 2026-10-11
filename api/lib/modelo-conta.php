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
    // Conta bloqueada, apagada ou com a senha trocada desde este login (lv_marca_senha): a sessão não vale mais.
    if (!$conta || !(int) $conta['ativa']
        || !hash_equals(lv_marca_senha((string) $conta['senha_hash']), (string) ($_SESSION['modelo_marca'] ?? ''))) {
        lv_modelo_deslogar();
        return null;
    }
    return $conta;
}

function lv_exigir_modelo(): array
{
    return lv_modelo_logada() ?? lv_falhar(401, 'Entre na área da modelo para continuar.', ['login_modelo' => true]);
}

/** $senhaHash é o hash que ficou gravado (depois de um rehash ou de uma troca de senha, o novo). */
function lv_modelo_logar(int $id, string $senhaHash): void
{
    lv_sessao();
    if (!defined('LV_TESTE')) {
        session_regenerate_id(true);
    }
    $_SESSION['modelo_id'] = $id;
    $_SESSION['modelo_desde'] = time();
    $_SESSION['modelo_marca'] = lv_marca_senha($senhaHash);
}

/** Sai só da área da modelo; se a mesma pessoa estiver logada como cliente, continua logada. */
function lv_modelo_deslogar(): void
{
    lv_sessao();
    unset($_SESSION['modelo_id'], $_SESSION['modelo_desde'], $_SESSION['modelo_marca']);
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

// ----------------------------------------------------------------------------- perfil

/** Limites do perfil (o painel usa os mesmos). */
const LV_PERFIL_LIMITES = ['nome' => 40, 'cidade' => 60, 'bio' => 600, 'tag' => 20, 'tags' => 10, 'idiomas' => 5];

/** Lista de textos curtos (interesses, idiomas): sem vazios, sem repetidos, cada um até $max caracteres. */
function lv_perfil_lista(mixed $valor, int $maxItens, int $max): ?array
{
    if (!is_array($valor) || !array_is_list($valor)) {
        return null;
    }
    $lista = [];
    foreach ($valor as $item) {
        if (!is_string($item)) {
            return null;
        }
        $texto = lv_texto($item, 1000);
        if ($texto === '') {
            continue;
        }
        if (mb_strlen($texto) > $max) {
            return null;
        }
        $lista[mb_strtolower($texto)] ??= $texto;
    }
    $lista = array_values($lista);
    return count($lista) <= $maxItens ? $lista : null;
}

/**
 * Confere o perfil vindo do painel. Devolve [perfil, null] se estiver certo, ou [null, [campo, mensagem]].
 * Textos passam por lv_texto (espaços repetidos e quebras de linha viram um espaço só).
 */
function lv_perfil_validar(array $b): array
{
    $l = LV_PERFIL_LIMITES;
    $bruto = static fn(string $c): string => is_string($b[$c] ?? null) ? $b[$c] : '';
    $nome = lv_texto($bruto('nome'), 1000);
    $cidade = lv_texto($bruto('cidade'), 1000);
    $bio = lv_texto($bruto('bio'), 5000);
    if (mb_strlen($nome) < 2 || mb_strlen($nome) > $l['nome']) {
        return [null, ['nome', "O nome artístico precisa ter de 2 a {$l['nome']} caracteres."]];
    }
    if (mb_strlen($cidade) > $l['cidade']) {
        return [null, ['cidade', "A cidade pode ter até {$l['cidade']} caracteres."]];
    }
    if (mb_strlen($bio) > $l['bio']) {
        return [null, ['bio', "O \"Sobre mim\" pode ter até {$l['bio']} caracteres."]];
    }
    $tags = lv_perfil_lista($b['tags'] ?? [], $l['tags'], $l['tag']);
    if ($tags === null) {
        return [null, ['tags', "Até {$l['tags']} interesses, cada um com até {$l['tag']} caracteres."]];
    }
    $idiomas = lv_perfil_lista($b['idiomas'] ?? [], $l['idiomas'], $l['tag']);
    if ($idiomas === null || $idiomas === []) {
        return [null, ['idiomas', "Informe de 1 a {$l['idiomas']} idiomas."]];
    }
    return [['nome' => $nome, 'cidade' => $cidade, 'bio' => $bio, 'tags' => $tags, 'idiomas' => $idiomas], null];
}

/** Perfis salvos: [slug => perfil]. Só modelos de LV_MODELOS; quem nunca salvou não aparece. */
function lv_perfis(): array
{
    $saida = [];
    foreach (lv_db()->query('SELECT slug, nome, cidade, bio, tags, idiomas, atualizado_em FROM lv_modelos_perfil')->fetchAll() as $p) {
        if (!isset(LV_MODELOS[$p['slug']])) {
            continue;
        }
        $saida[$p['slug']] = [
            'nome' => (string) $p['nome'],
            'cidade' => (string) $p['cidade'],
            'bio' => (string) $p['bio'],
            'tags' => json_decode((string) $p['tags'], true) ?: [],
            'idiomas' => json_decode((string) $p['idiomas'], true) ?: [],
            'atualizado_em' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($p['atualizado_em'] . ' UTC')),
        ];
    }
    return $saida;
}

function lv_perfil_salvar(string $slug, array $perfil): array
{
    $pdo = lv_db();
    $json = static fn(array $v): string => json_encode($v, JSON_UNESCAPED_UNICODE);
    $valores = [$perfil['nome'], $perfil['cidade'], $perfil['bio'], $json($perfil['tags']), $json($perfil['idiomas']), lv_agora()];
    $update = 'UPDATE lv_modelos_perfil SET nome = ?, cidade = ?, bio = ?, tags = ?, idiomas = ?, atualizado_em = ? WHERE slug = ?';
    $stmt = $pdo->prepare($update);
    $stmt->execute([...$valores, $slug]);
    if ($stmt->rowCount() === 0) {
        try {
            $pdo->prepare('INSERT INTO lv_modelos_perfil (nome, cidade, bio, tags, idiomas, atualizado_em, slug) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([...$valores, $slug]);
        } catch (PDOException $e) {
            if (!lv_e_duplicado($e)) {
                throw $e;
            }
            $pdo->prepare($update)->execute([...$valores, $slug]); // já existia e nada mudou, ou outra aba salvou junto
        }
    }
    return lv_perfis()[$slug];
}

// ----------------------------------------------------------------------------- configuração da sala

/** Limites de "Configurar sala" (o painel usa os mesmos). */
const LV_SALA_LIMITES = ['preco_min' => 1, 'preco_max' => 500, 'meta_titulo' => 60, 'meta_alvo_max' => 100000,
    'boas_vindas' => 120, 'palavra' => 30, 'palavras' => 30];

function lv_sala_linha(string $slug): ?array
{
    $stmt = lv_db()->prepare('SELECT * FROM lv_modelos_sala WHERE slug = ?');
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

/**
 * Preço do show privado em créditos por minuto: o da sala salva no painel, ou o padrão de LV_MODELOS.
 * É o que api/gastar.php cobra; o navegador nunca escolhe o preço.
 */
function lv_preco_privado(string $slug): int
{
    $padrao = (int) (LV_MODELOS[$slug]['preco_privado'] ?? 0);
    try {
        $linha = lv_sala_linha($slug);
    } catch (PDOException $e) {
        // Banco sem api/schema-modelos.sql importado: cobra o padrão e avisa no log, sem derrubar a sala.
        error_log('[pagamentos] lv_modelos_sala indisponível, preço padrão: ' . $e->getMessage());
        return $padrao;
    }
    return $linha ? (int) $linha['preco_privado'] : $padrao;
}

/** Créditos de gorjeta que a modelo recebeu em lançamentos do extrato com id maior que $depoisDoId. */
function lv_gorjetas_desde(string $slug, ?int $depoisDoId): int
{
    if ($depoisDoId === null) {
        return 0;
    }
    $stmt = lv_db()->prepare("SELECT COALESCE(SUM(-creditos), 0) FROM lv_extrato WHERE modelo = ? AND motivo = 'gorjeta' AND id > ?");
    $stmt->execute([$slug, $depoisDoId]);
    return (int) $stmt->fetchColumn();
}

/** Último id do extrato: o ponto de partida de uma meta nova. */
function lv_ultimo_lancamento(): int
{
    return (int) lv_db()->query('SELECT COALESCE(MAX(id), 0) FROM lv_extrato')->fetchColumn();
}

/** Configuração pública de cada sala de LV_MODELOS (padrões para quem nunca salvou). */
function lv_salas(): array
{
    $saida = [];
    foreach (LV_MODELOS as $slug => $modelo) {
        $l = lv_sala_linha($slug);
        $meta = null;
        if ($l && $l['meta_titulo'] !== null && $l['meta_alvo'] !== null) {
            $meta = ['titulo' => (string) $l['meta_titulo'], 'alvo' => (int) $l['meta_alvo'], 'atual' => lv_gorjetas_desde($slug, $l['meta_desde_id'] === null ? null : (int) $l['meta_desde_id'])];
        }
        $saida[$slug] = [
            'preco_privado' => $l ? (int) $l['preco_privado'] : (int) $modelo['preco_privado'],
            'meta' => $meta,
            'boas_vindas' => $l ? (string) $l['boas_vindas'] : '',
            'bloqueadas' => $l ? (json_decode((string) $l['bloqueadas'], true) ?: []) : [],
            'salva' => $l !== null,
        ];
    }
    return $saida;
}

/** Confere "Configurar sala". Devolve [config, null] ou [null, [campo, mensagem]]. Meta: título e valor juntos, ou os dois vazios. */
function lv_sala_validar(array $b): array
{
    $l = LV_SALA_LIMITES;
    $preco = $b['preco_privado'] ?? null;
    if (!is_int($preco) || $preco < $l['preco_min'] || $preco > $l['preco_max']) {
        return [null, ['preco_privado', "O preço do privado vai de {$l['preco_min']} a {$l['preco_max']} créditos por minuto."]];
    }
    $titulo = lv_texto(is_string($b['meta_titulo'] ?? null) ? $b['meta_titulo'] : '', 1000);
    $alvo = $b['meta_alvo'] ?? null;
    if ($titulo === '' && ($alvo === null || $alvo === '')) {
        $titulo = null;
        $alvo = null;
    } else {
        if ($titulo === '' || mb_strlen($titulo) > $l['meta_titulo']) {
            return [null, ['meta_titulo', "Dê um título à meta, com até {$l['meta_titulo']} caracteres (ou deixe título e valor vazios)."]];
        }
        if (!is_int($alvo) || $alvo < 1 || $alvo > $l['meta_alvo_max']) {
            return [null, ['meta_alvo', "A meta vai de 1 a {$l['meta_alvo_max']} créditos."]];
        }
    }
    $boas = lv_texto(is_string($b['boas_vindas'] ?? null) ? $b['boas_vindas'] : '', 1000);
    if (mb_strlen($boas) > $l['boas_vindas']) {
        return [null, ['boas_vindas', "A mensagem de boas-vindas pode ter até {$l['boas_vindas']} caracteres."]];
    }
    $bloqueadas = lv_perfil_lista($b['bloqueadas'] ?? [], $l['palavras'], $l['palavra']);
    if ($bloqueadas === null) {
        return [null, ['bloqueadas', "Até {$l['palavras']} palavras bloqueadas, cada uma com até {$l['palavra']} caracteres."]];
    }
    $bloqueadas = array_values(array_unique(array_map('mb_strtolower', $bloqueadas)));
    return [['preco_privado' => $preco, 'meta_titulo' => $titulo, 'meta_alvo' => $alvo, 'boas_vindas' => $boas, 'bloqueadas' => $bloqueadas], null];
}

/** Salva a configuração. A meta recomeça do zero (a partir do último lançamento) quando o título ou o valor muda. */
function lv_sala_salvar(string $slug, array $c): array
{
    $pdo = lv_db();
    $agora = lv_agora();
    $antes = lv_sala_linha($slug);
    $mesmaMeta = $antes && $c['meta_titulo'] !== null
        && $antes['meta_titulo'] === $c['meta_titulo'] && (int) $antes['meta_alvo'] === $c['meta_alvo'];
    $desde = $c['meta_titulo'] === null ? null : ($mesmaMeta ? (int) $antes['meta_desde_id'] : lv_ultimo_lancamento());
    $valores = [$c['preco_privado'], $c['meta_titulo'], $c['meta_alvo'], $desde, $c['boas_vindas'],
        json_encode($c['bloqueadas'], JSON_UNESCAPED_UNICODE), $agora, $slug];
    if ($antes) {
        $pdo->prepare('UPDATE lv_modelos_sala SET preco_privado = ?, meta_titulo = ?, meta_alvo = ?, meta_desde_id = ?, boas_vindas = ?, bloqueadas = ?, atualizado_em = ? WHERE slug = ?')
            ->execute($valores);
    } else {
        try {
            $pdo->prepare('INSERT INTO lv_modelos_sala (preco_privado, meta_titulo, meta_alvo, meta_desde_id, boas_vindas, bloqueadas, atualizado_em, slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute($valores);
        } catch (PDOException $e) {
            if (!lv_e_duplicado($e)) {
                throw $e;
            }
            return lv_sala_salvar($slug, $c); // outra aba salvou primeiro: agora existe, atualiza
        }
    }
    return lv_salas()[$slug];
}
