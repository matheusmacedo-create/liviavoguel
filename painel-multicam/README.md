# Painel Multicam (fases 2, 3 e 4)

Programa de computador que junta numa tela só:

- **Transmissão pelo painel (fase 3):** o OBS manda **um** sinal para o painel, e o painel repassa para todos
  os sites. No show privado, um clique em **🔒 Privado aqui** pausa os outros sites e deixa só aquele no ar.

- **Ranking dos sites:** quanto cada um está rendendo agora em **R$ por minuto** (últimos 5 minutos),
  quanto rendeu na sessão, pessoas assistindo e privados. O site que mais rende fica em primeiro com 🔥.
- **Chat de todos os sites junto**, com a cor de cada site. Gorjetas aparecem em verde com o valor em reais,
  e quem já deu gorjeta ganha um 💎 com o total da sessão.
- **Filtros:** "Só gorjetas", "Só quem já deu gorjeta" e esconder sites. Com 4 chats, isso é o que salva.
- **Alerta "site esquentou"** com um bip, quando chegam várias gorjetas em pouco tempo num site.
- **Abas com cada site**, já logado. Clique numa mensagem ou num card e a aba daquele site abre pra responder.
- **Exportar sessão (CSV):** gera a planilha da fase 1 pronta, com uma linha por site.
- **Histórico (fase 4):** o painel guarda cada live sozinho e mostra quanto cada site rende **por hora**, um mapa
  de calor de **qual dia e horário rendem mais** e os **melhores horários para a agenda**.

No chat, o painel **só lê**. Para responder, a modelo escreve na aba do próprio site. Ele não clica nem escreve
nada nos sites sozinho.

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

## Transmitir pelo painel e o show privado automático (fase 3)

Na fase 1, o plugin *Multiple output* do OBS mandava o vídeo direto para cada site. Esse plugin não deixa
outro programa ligar ou desligar cada site, então não dava para automatizar o privado. Na fase 3 o caminho muda:

```
OBS  ──►  painel (no próprio computador)  ──►  CameraPrivê
                                          ──►  Stripchat
                                          ──►  Cam4
                                          ──►  Chaturbate
```

O vídeo não é recodificado, então o painel quase não usa processador. O upload continua o mesmo da fase 1:
o vídeo sai uma vez para cada site.

### Configurar (uma vez)

1. Copie `config/segredos.exemplo.json` para `config/segredos.local.json` (se ainda não fez) e preencha,
   em `transmissao`, o **servidor** e a **chave** de cada site. São os mesmos dados que você colocou no
   plugin na fase 1. Site sem chave fica fora do repasse.
2. Abra o painel (`npm start`) e clique em **Configurar o OBS**. Ele mostra o que colocar no OBS:
   em **Configurações → Transmissão**, serviço **Personalizado**, servidor `rtmp://127.0.0.1:1935/live` e chave `painel`.
3. **Pare as saídas do plugin Multiple output.** Se os dois estiverem ligados, cada site recebe o vídeo duas vezes.

### Usar

1. Abra o painel **antes** de começar a live.
2. No OBS, clique em **Iniciar transmissão**. Na barra de transmissão do painel aparece "📡 OBS: recebendo" e
   cada site passa de 🟡 "Conectando" para 🟢 "No ar".
3. **Show privado:** clique em **🔒 Privado aqui** no site do privado. Os outros sites são pausados na hora e
   aparece uma faixa rosa "EM PRIVADO NO ...". Acabou o privado: **Encerrar privado e voltar para todos**.
4. Para tirar um site da live sem parar os outros: **Desligar** na barra. **Ligar** de novo para voltar.

Quando o painel lê no chat um aviso de privado do site, ele mostra um alerta e você clica para pausar os
outros. Para o painel pausar sozinho, mude `privadoAutomatico` para `true` em `config/sites.js`. O privado
sempre termina no botão, porque o fim do privado nem sempre aparece no chat.

Detalhes:
- Se um site cair, o painel tenta de novo sozinho (espera um pouco mais a cada tentativa) e mostra o erro em 🔴.
  As mensagens de erro nunca mostram a chave do site.
- Se a internet não der conta de algum site, o painel reconecta só aquele site.
- O painel só aceita o vídeo do próprio computador. Ninguém da rede consegue mandar vídeo para ele.
- Fechar o painel encerra o repasse: os sites saem do ar mesmo que o OBS continue transmitindo.

## Histórico e agenda (fase 4)

O painel guarda a live sozinho: a cada minuto, ao exportar, ao zerar a sessão e ao fechar. Não precisa
fazer nada. Clique em **Histórico** no topo para ver:

- **Quanto cada site rende por hora de live**, o total, as gorjetas, os privados e o melhor horário de cada um.
- **Mapa de calor** com os dias da semana e as horas: quanto mais claro o quadrado, mais a live rendeu
  (R$ por hora, todos os sites somados). Passe o mouse para ver o valor. Quadrado tracejado é horário sem live.
- **Melhores horários para a agenda:** os 5 horários que mais renderam. Só entram horários com pelo menos meia
  hora de live somada, para alguns minutos de sorte não enganarem.
- **Exportar histórico (CSV):** todas as lives, uma linha por site, nas colunas da planilha da fase 1.

Como ler os números:
- O R$ por hora de cada site considera a live inteira, como se o site tivesse ficado no ar o tempo todo.
  Se você desligou um site no meio da live, o R$ por hora dele aparece menor do que foi.
- A live que está acontecendo entra na conta depois de 10 minutos.
- Quanto mais lives guardadas, mais confiável fica. Com 2 a 4 semanas já dá para montar a agenda.

O histórico fica no computador, na pasta de dados do programa (no Windows, `%APPDATA%\painel-multicam`; no Mac,
`~/Library/Application Support/painel-multicam`), um arquivo por configuração (`historico-sites.json`,
`historico-ana.json`...). Para começar do zero, apague esse arquivo com o painel fechado.

## Limitações desta versão

- Os seletores dos sites (menos o Chaturbate) ainda **precisam ser preenchidos**, como explicado acima.
  Não dava para descobrir sem entrar numa conta de modelo.
- O painel não responde mensagens: clique na mensagem e responda na aba do site.
- O histórico fica só neste computador. Para ter uma cópia, use **Exportar histórico (CSV)** de vez em quando.
- O repasse foi testado com um OBS e sites simulados (ffmpeg), não com os sites de verdade. Na primeira live,
  confira a sala pública de cada site pelo celular, como no guia da fase 1.

## Para quem for mexer no código

- `main.cjs`: janela, abas isoladas, leitura da API do Chaturbate e o repasse da transmissão.
- `repasse.js`: recebe o OBS (ffmpeg) e mantém um envio por site. `src/core/flv.js` copia o vídeo para cada
  site e faz o site que entra no meio começar num quadro-chave. `src/core/transmissao.js`: a regra do privado.
- `src/core/historico.js`: análise das lives guardadas (R$ por hora, mapa por dia e hora, melhores horários).
- `preload-site.cjs`: roda dentro de cada site e lê o chat (só leitura).
- `src/app.js`: a tela. `src/core/`: cálculo do ranking, leitura das mensagens, CSV e simulador.
- Testes: `npm test`.
