<?php
/** GET → { logado, usuario: { id, email, apelido } | null, saldo, extrato: [{ valor, descricao, data }] }. Sem sessão: logado false (200). */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';

lv_exigir_metodo('GET');
lv_json(lv_estado(lv_usuario_id()));
