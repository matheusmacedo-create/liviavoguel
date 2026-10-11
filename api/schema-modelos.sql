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
