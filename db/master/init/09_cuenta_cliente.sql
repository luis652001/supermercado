-- Perfil del cliente, punto de entrega y tarjetas (nunca PAN ni CVV).
SET NAMES utf8mb4;

USE auth_db;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS telefono VARCHAR(20) NULL;

USE pedidos_db;
ALTER TABLE direcciones_entrega ADD COLUMN IF NOT EXISTS lat DECIMAL(9,6) NULL;
ALTER TABLE direcciones_entrega ADD COLUMN IF NOT EXISTS lng DECIMAL(9,6) NULL;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS destino_lat DECIMAL(9,6) NULL;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS destino_lng DECIMAL(9,6) NULL;

USE pagos_db;
ALTER TABLE pagos ADD COLUMN IF NOT EXISTS descuento_regalo DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE pagos ADD COLUMN IF NOT EXISTS metodo_etiqueta VARCHAR(80) NULL;

CREATE TABLE IF NOT EXISTS metodos_pago (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_cliente  INT UNSIGNED NULL,
    tipo        ENUM('normal', 'regalo') NOT NULL,
    alias       VARCHAR(40) NOT NULL,
    marca       VARCHAR(20) NULL,
    ultimos4    CHAR(4) NULL,
    saldo       DECIMAL(10,2) NOT NULL DEFAULT 0,
    codigo      CHAR(8) NULL,
    activa      TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_metodos_codigo (codigo),
    INDEX idx_metodos_cliente (id_cliente, activa)
) ENGINE=InnoDB;
