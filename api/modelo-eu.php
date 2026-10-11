<?php
/** GET → { logado, modelo: { slug, nome, email } | null }. Sem sessão de modelo: logado false (200). */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';

lv_exigir_metodo('GET');
$conta = lv_modelo_logada();
lv_json(['logado' => $conta !== null, 'modelo' => $conta ? lv_modelo_publico($conta) : null]);
