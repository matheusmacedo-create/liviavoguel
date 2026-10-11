# Fase 1 — Guia do OBS para transmitir em vários sites ao mesmo tempo

Objetivo desta fase: transmitir em todos os sites de uma vez, sem programar nada,
e anotar quanto cada site rende. Depois de 2 a 4 semanas dá para saber quais sites
valem a pena e só então partir para o painel (fase 2).

Tempo para montar tudo: umas 2 horas na primeira vez.

---

## 0. Antes de começar (não pule)

- [ ] **Leia o contrato de cada site.** Procure por "exclusividade", "simultâneo",
      "multistream" ou "outras plataformas". Se um site proibir, ele fica de fora
      ou você transmite nele em outro horário. Comece pelo CameraPrivê.
- [ ] **Confira se cada site aceita OBS.** No painel da modelo, procure algo como
      "Transmitir com OBS", "Software externo", "Encoder externo" ou "RTMP".
      Site que só transmite pelo navegador fica fora do multistream (dá para abrir
      ele separado, pela webcam no navegador, mas isso pesa no computador).
- [ ] **Ative o bloqueio por região** em cada site que tiver essa opção
      (bloquear a cidade ou o estado dela, por exemplo).
- [ ] **Meça a internet** em speedtest.net com o cabo ligado. Anote o **upload**.

### Quanto de internet precisa

Conta simples: `bitrate × número de sites × 1,3` (os 30% são de folga).

| Qualidade | Bitrate por site | 2 sites | 3 sites | 4 sites |
|---|---|---|---|---|
| 720p 30fps | 3.000 kbps | 8 Mbps | 12 Mbps | 16 Mbps |
| 1080p 30fps | 5.000 kbps | 13 Mbps | 20 Mbps | 26 Mbps |

Se o upload não der, transmita em 720p ou em menos sites. **Use cabo de rede, não Wi-Fi.**

---

## 1. Instalar

1. Baixe o **OBS Studio** em obsproject.com (versão mais recente) e instale.
2. Baixe o plugin **Multiple RTMP Outputs** (autor: *sorayuki*). Ele está no GitHub
   (`sorayuki/obs-multi-rtmp`, aba *Releases*) e no fórum do OBS. Pegue o instalador
   do seu sistema (Windows ou Mac) e instale com o OBS **fechado**.
3. Abra o OBS. Se aparecer o assistente de configuração automática, pode cancelar:
   vamos configurar na mão.
4. No menu **Docas** (ou *Docks*), confira se aparece a doca **Multiple output**.
   Se não aparecer, o plugin não instalou: reinstale com o OBS fechado.

---

## 2. Configurar a imagem e o som

Abra **Configurações** (canto inferior direito).

### Vídeo
- **Resolução base (tela):** a do monitor.
- **Resolução de saída:** `1280x720` (comece por aqui) ou `1920x1080` se a internet e o PC aguentarem.
- **FPS:** `30`.

### Saída → modo **Avançado** → aba **Transmissão**
- **Codificador:**
  - Placa de vídeo NVIDIA → `NVIDIA NVENC H.264`
  - AMD → `AMD HW H.264`
  - Mac → `Apple VT H264 Hardware`
  - Sem placa de vídeo → `x264`
- **Controle de taxa:** `CBR`
- **Taxa de bits:** `3000` (720p) ou `5000` (1080p)
- **Intervalo de keyframe:** `2` segundos (se ficar em 0, alguns sites recusam a transmissão)
- **Perfil:** `main` ou `high`

### Áudio
- **Taxa de amostragem:** `48 kHz`
- **Bitrate de áudio:** `128` ou `160`

> Se algum site recomendar números diferentes no painel dele, use os do site.

---

## 3. Montar as cenas

Crie 3 cenas (botão **+** em *Cenas*):

| Cena | O que tem dentro | Quando usar |
|---|---|---|
| **AO VIVO** | Câmera (*Dispositivo de captura de vídeo*) + microfone | Show normal |
| **VOLTO JÁ** | Imagem de fundo com "Volto já 💋" + música baixa | Intervalo, ir ao banheiro, ajeitar algo |
| **PRIVADO** | Imagem "Em show privado, volto logo" | Só se for preciso tampar a imagem |

Dicas:
- Coloque uma luz de frente para o rosto. Iluminação muda mais que câmera cara.
- Confira o fundo: nada de documento, correspondência, janela com vista
  reconhecível ou foto de família.
- Em **Configurações → Teclas de atalho**, defina uma tecla para cada cena
  (por exemplo F1, F2, F3). Assim a troca é rápida.

---

## 4. Cadastrar os sites no plugin

Em cada site, entre no painel da modelo, abra a opção de transmitir com OBS e copie
**dois dados**: o **servidor** (começa com `rtmp://` ou `rtmps://`) e a **chave de transmissão**.

> A chave de transmissão é como uma senha. Nunca mostre na tela, não mande por
> WhatsApp sem necessidade e, se vazar, gere uma nova no site.

No OBS, na doca **Multiple output**, clique em **Adicionar novo destino** e preencha
para cada site:

- **Nome:** o nome do site (ex.: `Stripchat`)
- **Servidor RTMP:** o servidor copiado
- **Chave:** a chave copiada
- **Codificador:** se o plugin oferecer usar o mesmo codificador da transmissão
  principal, prefira essa opção (pesa menos no PC). Se usar um codificador próprio,
  repita as configurações do passo 2.
- **Sincronizar início/parada com a transmissão principal:** deixe **desmarcado**.
  Assim cada site liga e desliga sozinho, o que é essencial no show privado (passo 6).

Repita para todos os sites. **Não precisa** preencher *Configurações → Transmissão*
do OBS: todos os sites ficam no plugin, cada um com seu botão de **Iniciar / Parar**.

---

## 5. Montar a mesa dos chats

O ideal são **2 telas**: OBS numa, chats na outra (pode ser uma TV ou monitor barato).

1. Crie um **perfil separado do navegador para cada site** (no Chrome: ícone do
   perfil → *Adicionar*). Assim o login de um não atrapalha o outro.
2. Abra a página de transmissão de cada site no seu perfil e divida a segunda tela
   em partes (no Windows: arraste a janela para o canto, ou tecla Windows + setas).
3. **Deixe o som ligado só nos alertas de gorjeta** e silencie o resto. Senão vira barulho.
4. Num celular ou numa aba anônima, abra a sala **pública** de cada site para ver
   como os clientes estão vendo (imagem, som e atraso).

---

## 6. Regra do show privado (a mais importante)

Quando alguém pagar um privado num site, **os outros sites não podem continuar
recebendo a imagem.** É questão de respeito com quem pagou e de regra dos sites.

Como fazer:

1. Aceite o privado no site **X**.
2. Na doca **Multiple output**, clique em **Parar** em todos os sites, **menos o X**.
3. Faça o show.
4. Acabou o privado: clique em **Iniciar** de novo nos outros sites.

Se quiser avisar antes, troque para a cena **VOLTO JÁ** por uns segundos, diga no
chat "vou pro privado, já volto", e só então pare os outros.

> Por que não basta trocar de cena? Porque o OBS manda **a mesma imagem para todos**.
> Se você mudar para PRIVADO, o cliente do privado também vê a tela "volto já".
> Por isso, nesta fase, o jeito certo é parar as outras saídas.
> Na fase 3 o painel faz isso sozinho.

---

## 7. Teste antes da primeira live de verdade

Escolha um horário fraco e faça um teste de 15 minutos:

- [ ] Iniciar um site por vez na doca e conferir a sala pública dele no celular.
- [ ] Com todos ligados, olhar a barra de baixo do OBS:
  - **Quadros perdidos** (rede) acima de 1% → baixe a taxa de bits ou tire um site.
  - **Quadros pulados por codificação** → baixe para 720p ou use o codificador da placa de vídeo.
  - **CPU** acima de 80% → mesma coisa.
- [ ] Testar o passo 6 (parar todos menos um e ligar de novo).
- [ ] Conferir se o som está bom e sem eco (fone de ouvido ajuda).

### Problemas comuns

| Problema | O que fazer |
|---|---|
| Um site não conecta | Confira o servidor e a chave (gere uma nova), e se o keyframe está em 2. |
| Imagem travando para todos | Internet. Baixe a taxa de bits, tire um site ou use cabo. |
| Imagem travando só num site | Problema do servidor do site. Veja se ele oferece outro servidor (de outra região). |
| PC esquentando ou travando | Use o codificador da placa de vídeo e 720p. Feche programas abertos. |
| Atraso grande num site | É normal ter de 5 a 20 segundos de diferença entre os sites. |

---

## 8. Anotar os números (é para isso que serve a fase 1)

No fim de **cada live**, preencha uma linha por site. Uma planilha simples resolve:

| Data | Início | Fim | Site | Pico de pessoas | Ganho (tokens/créditos) | Ganho em R$ | Privados | Observação |
|---|---|---|---|---|---|---|---|---|
| 12/10 | 21:00 | 23:30 | Stripchat | 340 | 1.200 tk | R$ 180 | 2 | Sábado bombou |
| 12/10 | 21:00 | 23:30 | CameraPrivê | 85 | — | R$ 95 | 1 | |

Para o **Ganho em R$**, descubra quanto cada site paga por token ou crédito
(está na área de pagamentos/ganhos do painel da modelo) e anote aqui:

| Site | Quanto vale 1 token/crédito para a modelo | Repasse (% que fica com ela) |
|---|---|---|
| CameraPrivê | | |
| Cam4 | | |
| Chaturbate | | |
| Stripchat | | |

Depois de 2 a 4 semanas, olhe:
- Qual site rende mais **R$ por hora**?
- Qual dia e horário rende mais em cada site?
- Algum site só atrapalha (pouco dinheiro e muito chat para responder)? Corte ele.

Com esses números, a fase 2 (painel com chat unificado e ranking de R$/min) já
nasce sabendo quais sites importam.

---

## Resumo de bolso

1. Contrato liberado? Bloqueio por região ligado?
2. OBS aberto, cena **AO VIVO**, câmera e som conferidos.
3. Doca **Multiple output** → **Iniciar** em cada site.
4. Chats na segunda tela, som só das gorjetas.
5. Privado num site → **Parar** os outros. Acabou → **Iniciar** de novo.
6. Fim da live → anotar os números na planilha.
