<?php
/** Chave Pix e pedidos de saque da modelo. Incluir depois de base.php e modelo-conta.php. */
declare(strict_types=1);

/** Saque mínimo, em centavos (R$ 50,00). No servidor, MODELO_SAQUE_MINIMO_CENTAVOS no config.php muda. */
const LV_SAQUE_MINIMO_PADRAO = 5000;
const LV_PIX_TIPOS = ['cpf', 'email', 'celular', 'aleatoria'];

function lv_saque_minimo_centavos(): int
{
    return max(1, (int) lv_cfg('MODELO_SAQUE_MINIMO_CENTAVOS', LV_SAQUE_MINIMO_PADRAO));
}

/** Confere e normaliza a chave Pix. Devolve a chave pronta ou null se não servir para o tipo. */
function lv_pix_chave(string $tipo, mixed $valor): ?string
{
    if (!is_string($valor)) {
        return null;
    }
    $v = trim($valor);
    return match ($tipo) {
        'cpf' => lv_cpf_valido($v) ? lv_digitos($v) : null,
        'email' => lv_email($v) ?: null,
        'celular' => (static function (string $v): ?string {
            $d = lv_telefone($v);
            return $d !== '' && strlen($d) === 11 ? '+55' . $d : null; // Pix de celular: +55 DDD 9xxxx-xxxx
        })($v),
        'aleatoria' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($v)) ? strtolower($v) : null,
        default => null,
    };
}

function lv_pix_da_modelo(string $slug): ?array
{
    $stmt = lv_db()->prepare('SELECT tipo, chave FROM lv_modelos_pix WHERE slug = ?');
    $stmt->execute([$slug]);
    $l = $stmt->fetch();
    return $l ? ['tipo' => (string) $l['tipo'], 'chave' => (string) $l['chave']] : null;
}

function lv_pix_salvar(string $slug, string $tipo, string $chave): void
{
    $pdo = lv_db();
    $stmt = $pdo->prepare('UPDATE lv_modelos_pix SET tipo = ?, chave = ?, atualizado_em = ? WHERE slug = ?');
    $stmt->execute([$tipo, $chave, lv_agora(), $slug]);
    if ($stmt->rowCount() === 0) {
        try {
            $pdo->prepare('INSERT INTO lv_modelos_pix (slug, tipo, chave, atualizado_em) VALUES (?, ?, ?, ?)')->execute([$slug, $tipo, $chave, lv_agora()]);
        } catch (PDOException $e) {
            if (!lv_e_duplicado($e)) {
                throw $e;
            }
        }
    }
}

/**
 * Créditos disponíveis para saque: tudo o que a modelo recebeu (gorjetas e privado) menos o que já foi pedido
 * (pendente ou pago; recusado volta a ficar disponível). Em reais, pelo repasse atual.
 */
function lv_saque_disponivel(string $slug): array
{
    $recebidos = lv_modelo_soma($slug)['creditos'];
    $stmt = lv_db()->prepare("SELECT COALESCE(SUM(creditos), 0) FROM lv_saques WHERE slug = ? AND status IN ('pendente', 'pago')");
    $stmt->execute([$slug]);
    $creditos = max(0, $recebidos - (int) $stmt->fetchColumn());
    return ['creditos' => $creditos, 'centavos' => $creditos * lv_modelo_repasse_centavos()];
}

function lv_saques_da_modelo(string $slug): array
{
    $stmt = lv_db()->prepare('SELECT id, creditos, valor_centavos, tipo_chave, chave, status, observacao, pedido_em, pago_em
        FROM lv_saques WHERE slug = ? ORDER BY id DESC LIMIT 100');
    $stmt->execute([$slug]);
    $iso = static fn(?string $t): ?string => $t === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($t . ' UTC'));
    return array_map(static fn(array $s): array => [
        'id' => (int) $s['id'],
        'creditos' => (int) $s['creditos'],
        'centavos' => (int) $s['valor_centavos'],
        'pix' => ['tipo' => (string) $s['tipo_chave'], 'chave' => (string) $s['chave']],
        'status' => (string) $s['status'],
        'observacao' => $s['observacao'],
        'pedido_em' => $iso($s['pedido_em']),
        'pago_em' => $iso($s['pago_em']),
    ], $stmt->fetchAll());
}

/**
 * Pede o saque de tudo o que está disponível. Devolve [saque, null] ou [null, mensagem]. A conta da modelo fica
 * travada durante a conta no MySQL, para dois cliques ao mesmo tempo não virarem dois pedidos.
 */
function lv_saque_pedir(int $contaId, string $slug): array
{
    $pdo = lv_db();
    $pdo->beginTransaction();
    try {
        $trava = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $pdo->prepare('SELECT id FROM lv_modelos_contas WHERE id = ?' . $trava)->execute([$contaId]);
        $pix = lv_pix_da_modelo($slug);
        $pendente = $pdo->prepare("SELECT COUNT(*) FROM lv_saques WHERE slug = ? AND status = 'pendente'");
        $pendente->execute([$slug]);
        $disponivel = lv_saque_disponivel($slug);
        $erro = match (true) {
            $pix === null => 'Cadastre sua chave Pix antes de pedir o saque.',
            (int) $pendente->fetchColumn() > 0 => 'Você já tem um saque esperando pagamento.',
            $disponivel['centavos'] < lv_saque_minimo_centavos() => 'O saque mínimo é de R$ ' . number_format(lv_saque_minimo_centavos() / 100, 2, ',', '.') . '.',
            default => null,
        };
        if ($erro !== null) {
            $pdo->rollBack();
            return [null, $erro];
        }
        $pdo->prepare("INSERT INTO lv_saques (slug, creditos, valor_centavos, tipo_chave, chave, status, pedido_em) VALUES (?, ?, ?, ?, ?, 'pendente', ?)")
            ->execute([$slug, $disponivel['creditos'], $disponivel['centavos'], $pix['tipo'], $pix['chave'], lv_agora()]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return [lv_saques_da_modelo($slug)[0] ?? ['id' => $id], null];
}

/** O que a seção Ganhos do painel mostra sobre saques. */
function lv_saque_estado(string $slug): array
{
    return ['ok' => true, 'pix' => lv_pix_da_modelo($slug), 'disponivel' => lv_saque_disponivel($slug),
        'minimo_centavos' => lv_saque_minimo_centavos(), 'repasse_centavos' => lv_modelo_repasse_centavos(),
        'saques' => lv_saques_da_modelo($slug)];
}
