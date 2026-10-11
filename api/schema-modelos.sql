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
