# Livia Voguel — site de cam ao vivo

Site de transmissão ao vivo, pensado para **começar com 1 modelo** e crescer depois.
É HTML, CSS e JavaScript puro, sem build. Funciona em qualquer hospedagem estática (Hostinger, Vercel, Netlify).

## Páginas

| Arquivo | O que é |
|---|---|
| `index.html` | Início: modelo em destaque, agenda e "como funciona". Com 2+ modelos aparece a grade com filtro "Ao vivo agora". |
| `sala.html?m=slug` | Sala: player, meta de gorjetas, gorjetas (rápidas ou valor livre com recado), show privado com cronômetro e cobrança por minuto, chat, seguir, abas sobre/fotos/agenda. |
| `creditos.html` | Pacotes de créditos e dúvidas frequentes. |
| `checkout.html?p=N` | Pagamento do pacote N: Pix (QR Code, copia e cola, contagem regressiva) ou cartão, e tela de aprovado/recusado. |
| `entrar.html`, `cadastro.html`, `recuperar-senha.html` | Login, cadastro (checa 18+ pela data de nascimento) e recuperação de senha. |
| `conta.html` | Minha conta: saldo, extrato de créditos, modelos seguidas e meus dados. |
| `painel-modelo.html` | Área da modelo: ficar ao vivo, como transmitir pelo OBS, ganhos, perfil e configurações da sala. |
| `termos.html`, `404.html` | Termos/privacidade (revisar com advogado) e página de erro. |

Todas as páginas mostram o aviso de **18+** na primeira visita.

## Modo demonstração

Com `modoDemo: false`, login, saldo, extrato, gorjetas, privado e pagamento usam a pasta `api/`
(veja "Pagamentos (Unicopag)" abaixo), que precisa de PHP + MySQL. **Por enquanto o site está com
`modoDemo: true`**: mude para `false` em `assets/js/config.js` depois de criar o banco, o `api/config.php`
e fazer a compra de teste.

Para ver o visual sem servidor (por exemplo abrindo os arquivos direto ou num `npx serve`), mude para
`modoDemo: true` em `assets/js/config.js`: tudo fica simulado no navegador (há uma faixa amarela avisando) e dá
para testar o fluxo inteiro: criar conta → comprar créditos → "Simular pagamento aprovado" → gorjeta → privado →
extrato. Com o site no ar e vendendo de verdade, o `modoDemo` tem que ser `false`.

> A `painel-modelo.html` ainda não tem senha. Antes de colocar o site no ar, ela precisa de login próprio da modelo.

## Como trocar os dados da modelo

Edite `assets/js/modelos.js`: nome, idade, cidade, bio, tags, agenda, preço do privado e fotos.
Coloque as fotos em `assets/img/` e troque os caminhos `placeholder-*.svg`.

## Como adicionar uma nova modelo

1. Em `assets/js/modelos.js`, copie o bloco `{ ... }` da Livia.
2. Cole depois dele, separado por vírgula, e troque `slug`, `nome` e os demais campos.
3. Pronto: a home passa a mostrar a grade de modelos e o menu troca "Sala ao vivo" por "Modelos".

Marque `destaque: true` na modelo que deve aparecer no topo da home.

## Configurações gerais

Em `assets/js/config.js` ficam o nome do site, o e-mail de suporte, o modo demonstração, os pacotes de créditos,
os valores rápidos de gorjeta e o mínimo de minutos para o show privado.

## O que ainda é demonstração (próximas etapas)

1. **Transmissão ao vivo:** contratar um serviço de streaming que aceite conteúdo adulto e
   colar o link do player no campo `streamUrl`. A modelo transmite pelo OBS (RTMP).
2. **Excluir conta e trocar e-mail:** hoje são pedidos ao suporte (o botão abre um e-mail).
3. **Chat em tempo real:** hoje as mensagens ficam só no navegador de quem escreve.
4. **Status online automático:** hoje o campo `online` é manual.
5. **Painel da modelo:** para ver ganhos, editar perfil e abrir ou fechar a sala.

Os itens acima, menos o streaming, precisam de backend (por exemplo Supabase ou PHP + MySQL na Hostinger).

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
ele pelas funções de `assets/js/app.js` (`LV.login`, `LV.cadastrar`, `LV.saldo`, `LV.extrato`, `LV.lancar`…) e pelo
`checkout.html`. O app guarda uma cópia do estado da conta para as páginas lerem na hora e a atualiza em
`api/eu.php` a cada página aberta.

| Endpoint | O que faz |
|---|---|
| `POST api/cadastro.php` | `{ apelido, email, senha, maior18: true }` → cria a conta e já entra (senha com 8+ caracteres) |
| `POST api/entrar.php` / `POST api/sair.php` | Login e logout (sessão PHP) |
| `GET api/eu.php` | `{ logado, usuario: { id, email, apelido }, saldo, extrato }` |
| `GET api/saldo.php` | `{ saldo, email }` do usuário logado |
| `POST api/conta.php` | `{ apelido }` e/ou `{ senha_atual, senha_nova }` |
| `POST api/gastar.php` | `{ tipo: "gorjeta"\|"privado", modelo, creditos }` → debita no servidor (402 sem saldo) |
| `POST api/cobranca.php` | `{ pacote, metodo: "pix"\|"cartao", nome, cpf, telefone?, cartao?: { numero, titular, validade "MM/AA", cvv } }` → cria a cobrança |
| `GET api/status.php?id=…` | `{ status: "pendente"\|"pago"\|"falhou"\|"expirado"\|"estornado", creditos, saldo }` |
| `POST api/webhook.php` | Aviso da Unicopag. Confere a cobrança na API antes de creditar e nunca credita duas vezes |

Regras importantes:
- **O preço sai do servidor** (`api/lib/pacotes.php`), nunca do navegador. Quando mudar um pacote em `assets/js/config.js`,
  mude também em `api/lib/pacotes.php`. O mesmo vale para o preço do privado: `assets/js/modelos.js` →
  `api/lib/modelos.php` (nova modelo também entra lá). O teste avisa se os arquivos não baterem.
- **Créditos só entram pela compra confirmada.** Gorjeta e privado são debitados em `api/gastar.php`; o privado
  cobra o preço do servidor, nunca o que o navegador mandar.
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
