-- Contas da área da modelo (painel-modelo.html). Importe DEPOIS de schema.sql (phpMyAdmin > Importar).
-- Não existe cadastro pelo site: cada conta é criada com scripts/criar_conta_modelo.php, que gera o INSERT.
-- `slug` é o da modelo em assets/js/modelos.js e api/lib/modelos.php (LV_MODELOS).

CREATE TABLE IF NOT EXISTS lv_modelos_contas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  email VARCHAR(190) NOT NULL,
  senha_hash VARCHAR(255) NOT NULL,
  ativa TINYINT(1) NOT NULL DEFAULT 1,       -- 0 = bloqueada (não entra mais, sem apagar o histórico)
  criado_em DATETIME NOT NULL,
  ultimo_login_em DATETIME NULL,
  UNIQUE KEY uq_modelos_contas_email (email),
  UNIQUE KEY uq_modelos_contas_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Status "ao vivo" de cada modelo, ligado e desligado no painel. Só vale enquanto o painel avisa que
-- continua aberto (visto_em recente); se a modelo fechar sem desligar, o site a mostra offline sozinho.
CREATE TABLE IF NOT EXISTS lv_modelos_ao_vivo (
  slug VARCHAR(40) NOT NULL PRIMARY KEY,
  online TINYINT(1) NOT NULL DEFAULT 0,
  desde DATETIME NULL,                       -- quando entrou ao vivo (null = offline)
  visto_em DATETIME NOT NULL                 -- último aviso do painel
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perfil público que a modelo edita no painel ("Meu perfil"). Sem linha aqui, vale o que está em
-- assets/js/modelos.js. tags e idiomas são listas em JSON.
CREATE TABLE IF NOT EXISTS lv_modelos_perfil (
  slug VARCHAR(40) NOT NULL PRIMARY KEY,
  nome VARCHAR(40) NOT NULL,
  cidade VARCHAR(60) NOT NULL,
  bio TEXT NOT NULL,
  tags TEXT NOT NULL,
  idiomas TEXT NOT NULL,
  atualizado_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuração da sala, editada no painel ("Configurar sala"). Sem linha aqui, valem os padrões de
-- api/lib/modelos.php (preço do privado) e nenhuma meta. A meta conta as gorjetas lançadas depois de
-- meta_desde_id (o último id de lv_extrato quando a meta foi definida), e recomeça quando a modelo troca
-- o título ou o valor. Pelo id, e não pelo horário, uma gorjeta do mesmo segundo nunca cai na meta errada.
-- bloqueadas é uma lista em JSON.
CREATE TABLE IF NOT EXISTS lv_modelos_sala (
  slug VARCHAR(40) NOT NULL PRIMARY KEY,
  preco_privado INT UNSIGNED NOT NULL,
  meta_titulo VARCHAR(60) NULL,
  meta_alvo INT UNSIGNED NULL,
  meta_desde_id INT UNSIGNED NULL,
  boas_vindas VARCHAR(120) NOT NULL,
  bloqueadas TEXT NOT NULL,
  atualizado_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat das salas. Mensagens de quem assiste e avisos de gorjeta (com o recado), na ordem do id.
-- O show privado não aparece aqui, por discrição.
CREATE TABLE IF NOT EXISTS lv_chat (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  usuario_id INT UNSIGNED NULL,
  apelido VARCHAR(20) NOT NULL,
  tipo VARCHAR(10) NOT NULL,                 -- msg | gorjeta
  texto VARCHAR(255) NOT NULL,
  creditos INT UNSIGNED NULL,                -- valor da gorjeta
  criado_em DATETIME NOT NULL,
  KEY ix_chat_sala (slug, id),
  KEY ix_chat_usuario (usuario_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Moderação do chat pela modelo. Mensagem apagada continua em lv_chat (histórico), mas some da sala; as salas
-- abertas recebem os ids apagados recentes e tiram da tela. Bloqueado não escreve mais na sala daquela modelo.
CREATE TABLE IF NOT EXISTS lv_chat_apagadas (
  chat_id INT UNSIGNED NOT NULL PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  apagada_em DATETIME NOT NULL,
  KEY ix_apagadas_sala (slug, apagada_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lv_chat_bloqueios (
  slug VARCHAR(40) NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  criado_em DATETIME NOT NULL,
  PRIMARY KEY (slug, usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Saques da modelo. A chave Pix fica em lv_modelos_pix (só a própria modelo vê). Cada pedido guarda os
-- créditos sacados e o valor em centavos do momento do pedido. A equipe paga por fora e marca no phpMyAdmin:
--   UPDATE lv_saques SET status = 'pago', pago_em = UTC_TIMESTAMP() WHERE id = <id>;
--   UPDATE lv_saques SET status = 'recusado', observacao = '<motivo>' WHERE id = <id>;   -- os créditos voltam a ficar disponíveis
CREATE TABLE IF NOT EXISTS lv_modelos_pix (
  slug VARCHAR(40) NOT NULL PRIMARY KEY,
  tipo VARCHAR(10) NOT NULL,                 -- cpf | email | celular | aleatoria
  chave VARCHAR(80) NOT NULL,
  atualizado_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lv_saques (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  creditos INT UNSIGNED NOT NULL,
  valor_centavos INT UNSIGNED NOT NULL,
  tipo_chave VARCHAR(10) NOT NULL,
  chave VARCHAR(80) NOT NULL,
  status VARCHAR(10) NOT NULL,               -- pendente | pago | recusado
  observacao VARCHAR(190) NULL,
  pedido_em DATETIME NOT NULL,
  pago_em DATETIME NULL,
  KEY ix_saques_modelo (slug, pedido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estornos (chargeback/reembolso) que caem sobre créditos já gastos com a modelo. Quando uma compra é estornada
-- depois de o comprador ter gastado os créditos dela, o que ficou sem dinheiro por trás é descontado do que as
-- modelos que receberam esses gastos podem sacar (os gastos mais recentes primeiro). Ver lib/creditos.php.
CREATE TABLE IF NOT EXISTS lv_modelos_estornos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  pedido_id INT UNSIGNED NOT NULL,
  creditos INT UNSIGNED NOT NULL,
  criado_em DATETIME NOT NULL,
  UNIQUE KEY uq_estornos_pedido_modelo (pedido_id, slug),
  KEY ix_estornos_modelo (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fotos que a modelo envia pelo painel. O arquivo fica em uploads/modelos/<slug>/<arquivo> (fora do Git; o deploy
-- não apaga). Uma por modelo é a capa; as outras formam a galeria, na ordem de `ordem`.
CREATE TABLE IF NOT EXISTS lv_modelos_fotos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL,
  arquivo VARCHAR(80) NOT NULL,
  capa TINYINT(1) NOT NULL DEFAULT 0,
  ordem INT NOT NULL DEFAULT 0,
  largura INT UNSIGNED NOT NULL,
  altura INT UNSIGNED NOT NULL,
  criado_em DATETIME NOT NULL,
  KEY ix_fotos_modelo (slug, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contas da equipe (equipe.html): marcam saques como pagos ou recusados e bloqueiam ou reativam modelos.
-- Criadas só por scripts/criar_conta_equipe.php (que imprime o INSERT). Login separado do de cliente e do de modelo.
CREATE TABLE IF NOT EXISTS lv_equipe_contas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  senha_hash VARCHAR(255) NOT NULL,
  ativa TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL,
  ultimo_login_em DATETIME NULL,
  UNIQUE KEY uq_equipe_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transmissão de cada modelo, preenchida pela equipe (equipe.html) com os dados do serviço de vídeo (ou do servidor
-- próprio). hls_url = link .m3u8 que a sala toca; rtmp_servidor e rtmp_chave = o que a modelo cola no OBS.
-- A chave é segredo: só aparece para a própria modelo e para a equipe, nunca para quem assiste.
CREATE TABLE IF NOT EXISTS lv_modelos_stream (
  slug VARCHAR(40) NOT NULL PRIMARY KEY,
  hls_url VARCHAR(255) NOT NULL,
  rtmp_servidor VARCHAR(255) NOT NULL,
  rtmp_chave VARCHAR(255) NOT NULL,
  atualizado_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aviso automático do servidor de vídeo (api/stream-evento.php): a modelo começou a transmitir no OBS. Enquanto houver
-- linha aqui (renovada pelo servidor, ou no máximo LV_PUBLICANDO_VALIDADE depois), a sala fica ao vivo sem o botão do
-- painel. Ao parar, o servidor avisa e a linha sai. Desligar no painel depois de começar vence o aviso.
CREATE TABLE IF NOT EXISTS lv_modelos_publicando (
  slug VARCHAR(40) NOT NULL PRIMARY KEY,
  desde DATETIME NOT NULL,
  visto_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
