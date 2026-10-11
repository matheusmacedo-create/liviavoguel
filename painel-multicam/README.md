# Painel Multicam (fase 2)

Programa de computador que junta numa tela só:

- **Ranking dos sites:** quanto cada um está rendendo agora em **R$ por minuto** (últimos 5 minutos),
  quanto rendeu na sessão, pessoas assistindo e privados. O site que mais rende fica em primeiro com 🔥.
- **Chat de todos os sites junto**, com a cor de cada site. Gorjetas aparecem em verde com o valor em reais,
  e quem já deu gorjeta ganha um 💎 com o total da sessão.
- **Filtros:** "Só gorjetas", "Só quem já deu gorjeta" e esconder sites. Com 4 chats, isso é o que salva.
- **Alerta "site esquentou"** com um bip, quando chegam várias gorjetas em pouco tempo num site.
- **Abas com cada site**, já logado. Clique numa mensagem ou num card e a aba daquele site abre pra responder.
- **Exportar sessão (CSV):** gera a planilha da fase 1 pronta, com uma linha por site.

O painel **só lê**. Para responder, a modelo escreve na aba do próprio site. Ele não clica nem escreve nada sozinho.

## Instalar (uma vez)

1. Instale o **Node.js** (versão LTS) em nodejs.org.
2. Abra o terminal nesta pasta (`painel-multicam`) e rode:

   ```bash
   npm install
   ```

## Usar

```bash
npm run demo   # modo demonstração: mensagens e gorjetas inventadas, sem abrir os sites
npm start      # de verdade: abre os sites nas abas
```

Comece pelo `npm run demo` para conhecer a tela. No `npm start`, entre na conta de cada site pela aba dele.
O login fica guardado, então nas próximas vezes já abre logado.

## Configurar (`config/sites.js`)

Para cada site:

| Campo | O que é |
|---|---|
| `url` | Endereço que a aba abre. Depois de logar, troque pelo endereço da página de transmissão da modelo. |
| `ativo` | `false` tira o site do painel. |
| `valorToken` e `moedaToken` | Quanto **1 token/crédito vale para a modelo** e em que moeda (`BRL` ou `USD`). Está na área de ganhos de cada site. **Os valores que vêm no arquivo são exemplos: confira.** |
| `leitura` | Como o painel lê o chat (abaixo). |

No topo do arquivo ficam a **cotação do dólar** (`cotacao.USD`, atualize de vez em quando), a janela do ranking
e os limites do alerta.

### Uma configuração por modelo

Para cuidar de várias modelos, copie `config/sites.js` com outro nome (por exemplo `config/ana.js`) e abra com:

```bash
npm start -- --config=config/ana.js
```

## Configurar a leitura do chat

Quase nenhum site de cam tem API de chat. Por isso o painel lê o chat **direto da página**, usando
*seletores CSS* que dizem onde estão as mensagens. Cada site tem os seus, e **eles precisam ser preenchidos
uma vez** (e revistos se o site mudar o visual). Enquanto estiverem vazios, o card do site mostra
"⚠ Leitura do chat não configurada", mas a aba funciona normalmente.

| Seletor | O que aponta | Obrigatório |
|---|---|---|
| `mensagem` | Cada linha do chat | Sim |
| `usuario` | O nome de quem escreveu, dentro da linha | Recomendado |
| `texto` | Só o que a pessoa escreveu, dentro da linha | Recomendado |
| `gorjeta` | Algo que só existe nas linhas de aviso de gorjeta (uma classe, um ícone) | Recomendado |
| `espectadores` | O número de pessoas assistindo, em qualquer lugar da página | Não |

**Como descobrir:** abra a aba do site, entre na página de transmissão, clique em 🔧 (ferramentas),
clique com o botão direito numa mensagem do chat e escolha **Inspecionar**. Procure o elemento que envolve
a mensagem inteira e veja a classe dele (por exemplo `class="message-row"` vira o seletor `.message-row`).
Se tiver dificuldade, mande um print dessa tela que a gente monta os seletores juntos.

**Por que o seletor `gorjeta` importa:** sem ele, o painel reconhece gorjeta pela frase ("fulano tipped 50 tokens",
"deu 100 créditos"). Se a pessoa digitar essa frase no chat de brincadeira, o painel percebe quando os seletores
`usuario` e `texto` estão preenchidos. Mas o jeito garantido é ter o seletor `gorjeta`: aí só conta o que o site
marcou como gorjeta.

Se o aviso de gorjeta de algum site tiver outro formato, dá para colocar uma expressão própria em
`leitura.regexGorjeta`, com o grupo `tokens` (e, se quiser, `usuario`). Exemplo:
`'(?<usuario>\\S+) mandou um presente de (?<tokens>\\d+)'`. O mesmo vale para `leitura.regexPrivado`.

Detalhes da leitura:
- O que já estava no chat ao abrir a página (os primeiros 5 segundos) é histórico e não conta.
- A leitura continua com a aba escondida.

### Chaturbate pela API oficial

O Chaturbate oferece a quem transmite uma **API de eventos** (gorjetas e mensagens), que é mais confiável que ler
a página. Pegue o link na área de transmissão do Chaturbate (procure por *Events API* ou "API de eventos"),
copie `config/segredos.exemplo.json` para `config/segredos.local.json` e cole o link lá.

> Esse link funciona como uma senha. O `segredos.local.json` não vai para o GitHub (está no `.gitignore`).
> Nunca mande esse arquivo para ninguém.

## Limitações desta versão

- Os seletores dos sites (menos o Chaturbate) ainda **precisam ser preenchidos**, como explicado acima.
  Não dava para descobrir sem entrar numa conta de modelo.
- O painel não responde mensagens: clique na mensagem e responda na aba do site.
- Os números ficam só enquanto o programa está aberto. **Exporte o CSV no fim de cada live.**
- O show privado ainda é na mão, como no guia da fase 1. A troca automática é a fase 3.

## Para quem for mexer no código

- `main.cjs`: janela, abas isoladas e leitura da API do Chaturbate.
- `preload-site.cjs`: roda dentro de cada site e lê o chat (só leitura).
- `src/app.js`: a tela. `src/core/`: cálculo do ranking, leitura das mensagens, CSV e simulador.
- Testes: `npm test`.
