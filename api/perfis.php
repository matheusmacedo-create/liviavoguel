<?php
/** GET → { ok, modelos: { <slug>: { nome, cidade, bio, tags, idiomas, atualizado_em } } }. Público; só modelos que já salvaram o perfil no painel. */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

lv_exigir_metodo('GET');
lv_json(['ok' => true, 'modelos' => lv_perfis()]);
