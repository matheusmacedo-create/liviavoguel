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

Com `modoDemo: true` em `assets/js/config.js`, login, saldo, extrato e pagamento são simulados
no navegador (há uma faixa amarela avisando). Dá para testar o fluxo inteiro: criar conta →
comprar créditos → "Simular pagamento aprovado" → mandar gorjeta → show privado → ver o extrato.

O checkout já chama a API da integração Unicopag quando `modoDemo` for `false`:

- `POST api/cobranca.php` com `{ pacote, metodo: "pix"|"cartao", nome, cpf, email, cartao? }`
  → `{ id, status, pix?: { qrcode_base64, copia_cola, expira_em } }`
- `GET api/status.php?id=…` → `{ status: "pendente"|"pago"|"falhou"|"expirado", creditos }`

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

1. **Pagamento (Unicopag):** em andamento no branch `claude/unicopag-integracao`.
2. **Transmissão ao vivo:** contratar um serviço de streaming que aceite conteúdo adulto e
   colar o link do player no campo `streamUrl`. A modelo transmite pelo OBS (RTMP).
3. **Login, cadastro e saldo no servidor:** hoje ficam simulados no navegador.
4. **Chat em tempo real:** hoje as mensagens ficam só no navegador de quem escreve.
5. **Status online automático:** hoje o campo `online` é manual.
6. **Painel da modelo:** para ver ganhos, editar perfil e abrir ou fechar a sala.

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
