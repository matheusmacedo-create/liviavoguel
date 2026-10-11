-- Esquema do MySQL (Hostinger: hPanel > Bancos de dados > phpMyAdmin > Importar este arquivo).
-- O saldo é SEMPRE a soma de lv_extrato.creditos do usuário; não existe coluna de saldo para editar.

CREATE TABLE IF NOT EXISTS lv_usuarios (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  senha_hash VARCHAR(255) NOT NULL,
  criado_em DATETIME NOT NULL,
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Um pedido = uma cobrança na Unicopag. `codigo` é o id público (o que o navegador vê).
CREATE TABLE IF NOT EXISTS lv_pedidos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo CHAR(32) NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  pacote TINYINT UNSIGNED NOT NULL,
  creditos INT UNSIGNED NOT NULL,
  valor_centavos INT UNSIGNED NOT NULL,
  metodo VARCHAR(10) NOT NULL,                -- pix | cartao
  status VARCHAR(12) NOT NULL,                -- pendente | pago | falhou | expirado | estornado
  unicopag_hash VARCHAR(64) NULL,
  unicopag_status VARCHAR(40) NULL,
  pix_copia_cola TEXT NULL,
  pix_imagem MEDIUMTEXT NULL,
  expira_em DATETIME NULL,
  bandeira VARCHAR(30) NULL,
  ultimos4 CHAR(4) NULL,
  criado_em DATETIME NOT NULL,
  atualizado_em DATETIME NOT NULL,
  consultado_em DATETIME NULL,
  pago_em DATETIME NULL,
  UNIQUE KEY uq_pedidos_codigo (codigo),
  UNIQUE KEY uq_pedidos_hash (unicopag_hash),
  KEY ix_pedidos_usuario (usuario_id, criado_em),
  CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id) REFERENCES lv_usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extrato (ledger): só cresce. Compra = +créditos; gorjeta e privado = −créditos; estorno = −créditos da compra.
-- A chave única (pedido_id, motivo) impede creditar a mesma compra duas vezes, mesmo com dois avisos ao mesmo tempo.
CREATE TABLE IF NOT EXISTS lv_extrato (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  creditos INT NOT NULL,
  motivo VARCHAR(20) NOT NULL,                -- compra | estorno | gorjeta | privado
  pedido_id INT UNSIGNED NULL,
  descricao VARCHAR(190) NULL,
  criado_em DATETIME NOT NULL,
  UNIQUE KEY uq_extrato_pedido (pedido_id, motivo),
  KEY ix_extrato_usuario (usuario_id),
  CONSTRAINT fk_extrato_usuario FOREIGN KEY (usuario_id) REFERENCES lv_usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de eventos: freios (tentativas de login, cobranças por hora) e rastro dos avisos da Unicopag.
CREATE TABLE IF NOT EXISTS lv_eventos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tipo VARCHAR(30) NOT NULL,
  chave VARCHAR(190) NOT NULL,
  detalhe VARCHAR(255) NULL,
  criado_em DATETIME NOT NULL,
  KEY ix_eventos (tipo, chave, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
