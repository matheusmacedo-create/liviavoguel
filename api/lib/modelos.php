<?php
/**
 * Modelos e preço do show privado (créditos por minuto), lidos SÓ no servidor. Espelho de MODELOS
 * (assets/js/modelos.js): o navegador diz qual modelo, e o preço sai daqui. Nova modelo ou preço novo lá?
 * Mude aqui também (scripts/testar_pagamentos.php confere que os dois batem).
 */
declare(strict_types=1);

const LV_MODELOS = [
    'livia-voguel' => ['nome' => 'Livia Voguel', 'preco_privado' => 20],
];

/** Teto de uma gorjeta, em créditos (proteção contra clique errado). */
const LV_GORJETA_MAXIMA = 10000;
