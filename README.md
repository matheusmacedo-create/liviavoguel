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
