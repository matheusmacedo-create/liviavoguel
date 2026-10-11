# Livia Voguel — site de cam ao vivo

Site de transmissão ao vivo, pensado para **começar com 1 modelo** e crescer depois.
É HTML, CSS e JavaScript puro, sem build. Funciona em qualquer hospedagem estática (Hostinger, Vercel, Netlify).

## Páginas

| Arquivo | O que é |
|---|---|
| `index.html` | Início: modelo em destaque, agenda e "como funciona". Com 2 ou mais modelos aparece também a grade com o filtro "Ao vivo agora". |
| `sala.html?m=slug` | Sala da modelo: player, chat, gorjetas, show privado e abas (sobre, fotos, agenda). |
| `creditos.html` | Pacotes de créditos e dúvidas frequentes. |
| `termos.html` | Modelo de termos de uso e privacidade (revisar com advogado). |

Todas as páginas mostram o aviso de **18+** na primeira visita.

## Como trocar os dados da modelo

Edite `assets/js/modelos.js`: nome, idade, cidade, bio, tags, agenda, preço do privado e fotos.
Coloque as fotos em `assets/img/` e troque os caminhos `placeholder-*.svg`.

## Como adicionar uma nova modelo

1. Em `assets/js/modelos.js`, copie o bloco `{ ... }` da Livia.
2. Cole depois dele, separado por vírgula, e troque `slug`, `nome` e os demais campos.
3. Pronto: a home passa a mostrar a grade de modelos e o menu troca "Sala ao vivo" por "Modelos".

Marque `destaque: true` na modelo que deve aparecer no topo da home.

## Configurações gerais

Em `assets/js/config.js` ficam o nome do site, o e-mail de suporte, os pacotes de créditos
(com o campo `checkoutUrl` para o link de pagamento) e os valores rápidos de gorjeta.

## O que ainda é demonstração (próximas etapas)

1. **Transmissão ao vivo:** contratar um serviço de streaming que aceite conteúdo adulto e
   colar o link do player no campo `streamUrl`. A modelo transmite pelo OBS (RTMP).
2. **Login e cadastro:** hoje o botão "Entrar" só mostra um aviso.
3. **Pagamento e saldo:** usar um processador que aceite conteúdo adulto. Hoje o saldo é sempre 0
   e o botão "Comprar" só funciona quando houver `checkoutUrl`.
4. **Chat em tempo real:** hoje as mensagens ficam só no navegador de quem escreve.
5. **Status online automático:** hoje o campo `online` é manual.
6. **Painel da modelo:** para ver ganhos, editar perfil e abrir ou fechar a sala.

As etapas 2 a 6 precisam de backend (por exemplo Supabase ou PHP + MySQL na Hostinger).

## Cuidados legais

- Guardar documento com foto e termo de consentimento assinado de cada modelo, com prova de que tem 18 anos ou mais.
- Revisar termos de uso e privacidade (LGPD) com um advogado.
- Usar um gateway de pagamento que permita esse tipo de conteúdo, para a conta não ser bloqueada.

## Rodar localmente

```bash
python3 -m http.server 8000
# abra http://localhost:8000
```

## Pagamentos (Unicopag)

O visitante cria uma conta, compra um pacote de créditos por **Pix** ou **cartão**, e os créditos entram no saldo
quando a Unicopag confirma o pagamento. O backend fica em `api/` (PHP 8.1+ e MySQL, sem Composer). O navegador fala com
ele por `assets/js/pagamento.js`.

| Endpoint | O que faz |
|---|---|
| `POST api/cadastro.php` | `{ email, senha, maior18: true }` → cria a conta e já entra |
| `POST api/entrar.php` / `POST api/sair.php` | Login e logout (sessão PHP) |
| `GET api/saldo.php` | `{ saldo, email }` do usuário logado |
| `POST api/cobranca.php` | `{ pacote, metodo: "pix"\|"cartao", nome, cpf, telefone?, cartao?: { numero, titular, validade "MM/AA", cvv } }` → cria a cobrança |
| `GET api/status.php?id=…` | `{ status: "pendente"\|"pago"\|"falhou"\|"expirado"\|"estornado", creditos, saldo }` |
| `POST api/webhook.php` | Aviso da Unicopag. Confere a cobrança na API antes de creditar e nunca credita duas vezes |

Regras importantes:
- **O preço sai do servidor** (`api/lib/pacotes.php`), nunca do navegador. Quando mudar um pacote em `assets/js/config.js`,
  mude também em `api/lib/pacotes.php`. O teste avisa se os dois arquivos não baterem.
- **O saldo é a soma do extrato** (tabela `lv_extrato`). Não existe número de saldo para editar.
- **A chave da API é segredo.** Ela só existe no `api/config.php` do servidor, que está no `.gitignore`. Nunca coloque a
  chave em JavaScript, em HTML ou num commit.

### Como subir na Hostinger

1. **Banco:** no hPanel, abra **Bancos de dados → Gerenciamento** e crie um banco MySQL com um usuário. Anote o nome do
   banco, o usuário e a senha. Abra o **phpMyAdmin** desse banco e use **Importar** para carregar o `api/schema.sql`.
2. **Arquivos:** envie o site inteiro para `public_html/`, pela integração Git ou pelo Gerenciador de Arquivos. A pasta
   `api/` precisa ir junto, com os arquivos `.htaccess` dela.
3. **PHP:** em **Avançado → Configuração do PHP**, escolha PHP 8.1 ou mais novo, com as extensões `curl` e `pdo_mysql`
   (já vêm ligadas por padrão).

### Como criar o `config.php`

No Gerenciador de Arquivos, copie `public_html/api/config.example.php` para `public_html/api/config.php` e preencha:

- `UNICO_API_KEY`: a chave da API (painel da Unicopag → API).
- `DB_NAME`, `DB_USER`, `DB_SENHA`: os dados do passo 1. O `DB_HOST` fica `localhost`.
- `SITE_URL`: o endereço do site com `https://` e sem barra no fim, por exemplo `https://seudominio.com.br`.

O `.htaccess` impede que o `config.php` seja aberto pelo navegador. Para conferir, abra
`https://seudominio.com.br/api/config.php`: tem que aparecer **403**.

### URL do webhook no painel da Unicopag

```
https://seudominio.com.br/api/webhook.php
```

Cada cobrança já envia essa URL no campo `postback_url`. Mesmo assim, cadastre-a também no painel da Unicopag (se ele
tiver o campo). O webhook não confia no conteúdo do aviso: ele usa só o código da transação para perguntar o status
direto à Unicopag. Se um aviso se perder, a tela de checkout consulta o status sozinha (`status.php`) e o crédito cai
do mesmo jeito.

### Compra de teste de R$ 1,00

1. Crie sua conta no site com o seu e-mail.
2. No `config.php`, coloque `'PRECO_TESTE_CENTAVOS' => 100` e `'TESTE_EMAILS' => ['seu@email.com']`. Só esse e-mail
   paga R$ 1,00. Qualquer outro visitante continua pagando o preço normal.
3. Compre qualquer pacote por Pix e pague o QR Code. Em alguns segundos a tela mostra "pago" e o saldo sobe.
4. Para conferir no phpMyAdmin: o pedido aparece em `lv_pedidos` com `status = pago`, e a tabela `lv_extrato` tem uma
   única linha `compra` para ele.
5. Depois do teste, volte `PRECO_TESTE_CENTAVOS` para `''` e `TESTE_EMAILS` para `[]`. Se quiser, estorne o R$ 1,00 no
   painel da Unicopag: o estorno também tira os créditos do saldo.

### Testes (sem rede)

```
php scripts/testar_pagamentos.php
```

Os testes usam uma Unicopag simulada e um banco SQLite em memória: não precisam de chave, de MySQL nem de internet.
Eles cobrem: preço vindo do servidor, webhook mentiroso, webhook repetido, valor divergente, Pix vencido, cartão
aprovado e recusado, estorno, gastos sem saldo, isolamento entre usuários e os freios de login e de cobrança.
