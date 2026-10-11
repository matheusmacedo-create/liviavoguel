<?php
/**
 * GET → { ok, modelos: { <slug>: { preco_privado, meta: { titulo, alvo, atual } | null, boas_vindas, bloqueadas, salva } } }.
 * Público: a sala usa para o preço do privado, a meta de gorjetas, a mensagem de boas-vindas e o filtro do chat.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

lv_exigir_metodo('GET');
lv_json(['ok' => true, 'modelos' => lv_salas()]);
