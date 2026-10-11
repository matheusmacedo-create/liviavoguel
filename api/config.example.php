<?php
// Copie para config.php (só no servidor; config.php NUNCA entra no Git) e preencha.
return [
    // Unicopag: chave da API (painel Unicopag > API). Segredo: só existe aqui, no servidor.
    'UNICO_API_KEY'  => '',
    'UNICO_BASE_URL' => 'https://api.cloud.unicopag.com.br',

    // MySQL da Hostinger (hPanel > Bancos de dados > Gerenciar). No próprio servidor, o host é localhost.
    'DB_HOST'  => 'localhost',
    'DB_PORT'  => 3306,
    'DB_NAME'  => 'u000000000_livia',
    'DB_USER'  => 'u000000000_livia',
    'DB_SENHA' => '',

    // Endereço público do site, sem barra no fim (monta a postback_url e confere a origem dos pedidos).
    'SITE_URL' => 'https://seudominio.com.br',

    // Só para a compra de teste: os e-mails desta lista pagam PRECO_TESTE_CENTAVOS em qualquer pacote
    // (ex.: 100 = R$ 1,00). Todo outro visitante paga o preço real. Volte para '' e [] depois do teste.
    'PRECO_TESTE_CENTAVOS' => '',
    'TESTE_EMAILS' => [],
];
