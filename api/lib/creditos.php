<?php
/** Pedidos, a única porta de mudança de status e o extrato (ledger) de créditos. */
declare(strict_types=1);

/** Pendente há mais que isto (a Unicopag dá 1 dia ao Pix) vira expirado. */
const LV_VENCIMENTO_SEGUNDOS = 25 * 3600;

function lv_pedido_por(string $coluna, string $valor): ?array
{
    if (!in_array($coluna, ['id', 'codigo', 'unicopag_hash'], true)) {
        throw new InvalidArgumentException('coluna');
    }
    $stmt = lv_db()->prepare("SELECT * FROM lv_pedidos WHERE $coluna = ?");
    $stmt->execute([$valor]);
    return $stmt->fetch() ?: null;
}

function lv_saldo(int $usuarioId): int
{
    $stmt = lv_db()->prepare('SELECT COALESCE(SUM(creditos), 0) FROM lv_extrato WHERE usuario_id = ?');
    $stmt->execute([$usuarioId]);
    return (int) $stmt->fetchColumn();
}

function lv_e_duplicado(PDOException $e): bool
{
    // MySQL: 23000/1062. SQLite (testes): 23000/19.
    return ($e->errorInfo[0] ?? '') === '23000' || (string) $e->getCode() === '23000';
}

/**
 * Aplica um status vindo da Unicopag respeitando as transições: pago é definitivo (só estorno o altera); falhou e
 * expirado só valem sobre pendente. O UPDATE condicional e o lançamento no extrato vão na mesma transação, e a
 * chave única (pedido_id, motivo) segura o resto: webhook repetido ou em paralelo com o status.php credita uma vez só.
 */
function lv_aplicar_status(int $id, string $novo): array
{
    $pdo = lv_db();
    $agora = lv_agora();
    $transicoes = [
        'pago' => ["UPDATE lv_pedidos SET status = 'pago', pago_em = ?, atualizado_em = ? WHERE id = ? AND status NOT IN ('pago', 'estornado')", [$agora, $agora, $id]],
        'falhou' => ["UPDATE lv_pedidos SET status = 'falhou', atualizado_em = ? WHERE id = ? AND status = 'pendente'", [$agora, $id]],
        'expirado' => ["UPDATE lv_pedidos SET status = 'expirado', atualizado_em = ? WHERE id = ? AND status = 'pendente'", [$agora, $id]],
        // Estorno de um pedido pago tira os créditos; de um pendente, só muda o status (nada foi creditado).
        'estornado' => ["UPDATE lv_pedidos SET status = 'estornado', atualizado_em = ? WHERE id = ? AND status = 'pago'", [$agora, $id]],
        'estornado_pendente' => ["UPDATE lv_pedidos SET status = 'estornado', atualizado_em = ? WHERE id = ? AND status = 'pendente'", [$agora, $id]],
    ];
    if (!isset($transicoes[$novo]) || $novo === 'estornado_pendente') {
        return lv_pedido_por('id', (string) $id) ?? [];
    }
    $antes = lv_pedido_por('id', (string) $id);
    if (!$antes) {
        return [];
    }
    [$sql, $params] = $transicoes[$novo];
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 1) {
            if ($novo === 'pago') {
                lv_lancar((int) $antes['usuario_id'], (int) $antes['creditos'], 'compra', $id, 'Pacote de ' . $antes['creditos'] . ' créditos');
            } elseif ($novo === 'estornado') {
                $saldoAntes = lv_saldo((int) $antes['usuario_id']);
                lv_lancar((int) $antes['usuario_id'], -(int) $antes['creditos'], 'estorno', $id, 'Estorno do pacote de ' . $antes['creditos'] . ' créditos');
                lv_estornar_das_modelos($antes, $saldoAntes);
            }
        } elseif ($novo === 'estornado') {
            [$sql, $params] = $transicoes['estornado_pendente'];
            $pdo->prepare($sql)->execute($params);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if (!lv_e_duplicado($e)) {
            throw $e;
        }
        // O lançamento já existia (o status tinha sido mexido à mão, por exemplo): só o status muda, sem lançar de novo.
        $pdo->prepare($sql)->execute($params);
    }
    return lv_pedido_por('id', (string) $id) ?? [];
}

/**
 * Estorno de uma compra cujos créditos já foram gastos: o saldo do comprador fica negativo, e esse negativo (o que
 * foi gasto sem dinheiro por trás) sai do que as modelos podem sacar, começando pelos gastos mais recentes desde a
 * compra. Só conta o negativo que ESTE estorno criou: um segundo estorno não desconta de novo o do primeiro.
 * Roda dentro da transação de lv_aplicar_status; a chave única (pedido_id, slug) segura repetição.
 */
function lv_estornar_das_modelos(array $pedido, int $saldoAntes): void
{
    $creditos = (int) $pedido['creditos'];
    $semDinheiro = max(0, $creditos - $saldoAntes) - max(0, -$saldoAntes);
    if ($semDinheiro <= 0) {
        return;
    }
    // "Depois da compra" pela ordem do extrato (id), não pelo horário: dois lançamentos no mesmo segundo não se confundem.
    $compra = lv_db()->prepare("SELECT id FROM lv_extrato WHERE pedido_id = ? AND motivo = 'compra'");
    $compra->execute([(int) $pedido['id']]);
    $stmt = lv_db()->prepare("SELECT modelo, -creditos AS creditos FROM lv_extrato
        WHERE usuario_id = ? AND motivo IN ('gorjeta', 'privado') AND modelo IS NOT NULL AND id > ? ORDER BY id DESC");
    $stmt->execute([(int) $pedido['usuario_id'], (int) $compra->fetchColumn()]);
    $porModelo = [];
    foreach ($stmt->fetchAll() as $gasto) {
        if ($semDinheiro <= 0) {
            break;
        }
        $parte = min($semDinheiro, (int) $gasto['creditos']);
        $porModelo[$gasto['modelo']] = ($porModelo[$gasto['modelo']] ?? 0) + $parte;
        $semDinheiro -= $parte;
    }
    $inserir = lv_db()->prepare('INSERT INTO lv_modelos_estornos (slug, pedido_id, creditos, criado_em) VALUES (?, ?, ?, ?)');
    foreach ($porModelo as $slug => $parte) {
        $inserir->execute([$slug, (int) $pedido['id'], $parte, lv_agora()]);
    }
}

function lv_lancar(int $usuarioId, int $creditos, string $motivo, ?int $pedidoId, string $descricao, ?string $modelo = null): void
{
    lv_db()->prepare('INSERT INTO lv_extrato (usuario_id, creditos, motivo, pedido_id, modelo, descricao, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$usuarioId, $creditos, $motivo, $pedidoId, $modelo, mb_substr($descricao, 0, 190), lv_agora()]);
}

/**
 * Gasta créditos (gorjeta, show privado). Devolve o saldo novo, ou null se não houver saldo. A linha do usuário
 * fica travada durante a conta no MySQL, então dois gastos simultâneos não passam do saldo.
 */
function lv_debitar(int $usuarioId, int $creditos, string $motivo, string $descricao, ?string $modelo = null): ?int
{
    if ($creditos <= 0 || !in_array($motivo, ['gorjeta', 'privado'], true)) {
        throw new InvalidArgumentException('débito inválido');
    }
    $pdo = lv_db();
    $pdo->beginTransaction();
    try {
        $trava = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $pdo->prepare('SELECT id FROM lv_usuarios WHERE id = ?' . $trava)->execute([$usuarioId]);
        $saldo = lv_saldo($usuarioId);
        if ($saldo < $creditos) {
            $pdo->rollBack();
            return null;
        }
        lv_lancar($usuarioId, -$creditos, $motivo, null, $descricao, $modelo);
        $pdo->commit();
        return $saldo - $creditos;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Últimos lançamentos do extrato, no formato do app.js: { valor, descricao, data }. */
function lv_extrato(int $usuarioId, int $limite = 200): array
{
    $stmt = lv_db()->prepare('SELECT creditos, descricao, criado_em FROM lv_extrato WHERE usuario_id = ? ORDER BY id DESC LIMIT ' . max(1, $limite));
    $stmt->execute([$usuarioId]);
    return array_map(static fn(array $l): array => [
        'valor' => (int) $l['creditos'],
        'descricao' => (string) $l['descricao'],
        'data' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($l['criado_em'] . ' UTC')),
    ], $stmt->fetchAll());
}

/** Estado da conta para o app.js: quem está logado, saldo e extrato. */
function lv_estado(?int $usuarioId): array
{
    $u = $usuarioId !== null ? lv_usuario($usuarioId) : null;
    if (!$u) {
        return ['ok' => true, 'logado' => false, 'usuario' => null, 'saldo' => 0, 'extrato' => []];
    }
    return [
        'ok' => true, 'logado' => true,
        'usuario' => ['id' => (int) $u['id'], 'email' => $u['email'], 'apelido' => $u['apelido']],
        'saldo' => lv_saldo((int) $u['id']), 'extrato' => lv_extrato((int) $u['id']),
    ];
}

/** Pendente: reconsulta a Unicopag (no máximo a cada $intervalo s) e vence depois de 25 h. */
function lv_atualizar_pendente(array $pedido, int $intervalo = 5): array
{
    if ($pedido['status'] !== 'pendente') {
        return $pedido;
    }
    $ultima = $pedido['consultado_em'] ? (int) strtotime($pedido['consultado_em'] . ' UTC') : 0;
    if (time() - $ultima >= $intervalo) {
        $pedido = lv_sincronizar($pedido);
    }
    if ($pedido['status'] === 'pendente' && time() - (int) strtotime($pedido['criado_em'] . ' UTC') > LV_VENCIMENTO_SEGUNDOS) {
        $pedido = lv_aplicar_status((int) $pedido['id'], 'expirado');
    }
    return $pedido;
}

/** O que o navegador vê de um pedido. */
function lv_pedido_publico(array $p): array
{
    $saida = [
        'ok' => true,
        'id' => $p['codigo'],
        'status' => $p['status'],
        'creditos' => (int) $p['creditos'],
        'valor' => round((int) $p['valor_centavos'] / 100, 2),
        'metodo' => $p['metodo'],
    ];
    if ($p['metodo'] === 'pix') {
        $imagem = (string) ($p['pix_imagem'] ?? '');
        $url = preg_match('#^https://#i', $imagem) ? $imagem : null;
        $base64 = $url === null && $imagem !== '' ? preg_replace('#^data:image/[a-z]+;base64,#i', '', $imagem) : null;
        $saida['pix'] = [
            'qrcode_base64' => $base64,
            'qrcode_url' => $url,
            'copia_cola' => $p['pix_copia_cola'],
            'expira_em' => $p['expira_em'] ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($p['expira_em'] . ' UTC')) : null,
        ];
    } else {
        $saida['cartao'] = ['bandeira' => $p['bandeira'], 'ultimos4' => $p['ultimos4']];
    }
    return $saida;
}
