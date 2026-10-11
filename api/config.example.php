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

    // Área da modelo: quanto a modelo recebe por crédito ganho (gorjeta ou minuto de privado), em centavos.
    // 20 = R$ 0,20. Vazio = 20.
    'MODELO_REPASSE_CENTAVOS' => '',
    // Saque mínimo da modelo, em centavos. 5000 = R$ 50,00. Vazio = 5000.
    'MODELO_SAQUE_MINIMO_CENTAVOS' => '',
    // Dias que cada gorjeta ou minuto de privado espera antes de poder ser sacado (prazo para um pagamento com
    // cartão ser contestado; estorno nesse prazo é descontado antes de o dinheiro sair). Vazio = 7. 0 = sem espera.
    'MODELO_ESPERA_SAQUE_DIAS' => '',

    // Show privado pelo servidor. Deixe false: ainda não existe o fluxo em que a modelo recebe o pedido e aceita,
    // então o site recusa cobrar minutos de privado (api/gastar.php) e a sala esconde o botão.
    'PRIVADO_ATIVO' => false,

    // Só para a compra de teste: as contas desta lista (o id em lv_usuarios, veja no phpMyAdmin) pagam
    // PRECO_TESTE_CENTAVOS em qualquer pacote (ex.: 100 = R$ 1,00). Todo outro visitante paga o preço real.
    // Volte para '' e [] depois do teste.
    'PRECO_TESTE_CENTAVOS' => '',
    'TESTE_USUARIOS' => [],
];
