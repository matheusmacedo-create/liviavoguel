<?php
/**
 * Pacotes de créditos, lidos SÓ no servidor. Espelho de SITE.pacotes (assets/js/config.js), na mesma ordem:
 * o navegador manda o índice e o preço sai daqui. Mudou um preço lá? Mude aqui também
 * (scripts/testar_pagamentos.php confere que os dois batem).
 */
declare(strict_types=1);

const LV_PACOTES = [
    ['creditos' => 50,  'bonus' => 0,   'centavos' => 1990],
    ['creditos' => 120, 'bonus' => 10,  'centavos' => 4490],
    ['creditos' => 300, 'bonus' => 40,  'centavos' => 9990],
    ['creditos' => 700, 'bonus' => 120, 'centavos' => 19990],
];
