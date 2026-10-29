-- pedidos_db: clientes, pedidos, detalle y direcciones (sección 11 del enunciado).
-- El uuid es el identificador público; el id secuencial nunca se expone al cliente.
SET NAMES utf8mb4;
USE pedidos_db;

CREATE TABLE IF NOT EXISTS clientes (
    id_cliente INT UNSIGNED PRIMARY KEY,
    nombre     VARCHAR(100) NOT NULL,
    email      VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pedidos (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid              CHAR(36) NOT NULL,
    id_cliente        INT UNSIGNED NOT NULL,
    total             DECIMAL(10,2) NOT NULL,
    estado            ENUM('pendiente', 'pagado', 'preparando', 'en_camino', 'entregado', 'cancelado') NOT NULL DEFAULT 'pendiente',
    direccion_entrega VARCHAR(200) NOT NULL,
    zona              VARCHAR(30) NULL,
    municipio         VARCHAR(60) NOT NULL,
    telefono          VARCHAR(20) NOT NULL,
    referencias       VARCHAR(200) NULL,
    notas             VARCHAR(300) NULL,
    fecha             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pedidos_uuid (uuid),
    INDEX idx_pedidos_cliente (id_cliente, fecha),
    INDEX idx_pedidos_estado (estado),
    CONSTRAINT fk_pedidos_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS detalle_pedidos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id       INT UNSIGNED NOT NULL,
    producto_id     INT UNSIGNED NOT NULL,
    nombre_producto VARCHAR(120) NOT NULL,
    precio_unitario DECIMAL(10,2) NOT NULL,
    cantidad        SMALLINT UNSIGNED NOT NULL,
    subtotal        DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_detalle_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id) ON DELETE CASCADE,
    INDEX idx_detalle_producto (producto_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS direcciones_entrega (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_cliente  INT UNSIGNED NOT NULL,
    alias       VARCHAR(40) NOT NULL DEFAULT 'Principal',
    direccion   VARCHAR(200) NOT NULL,
    zona        VARCHAR(30) NULL,
    municipio   VARCHAR(60) NOT NULL,
    telefono    VARCHAR(20) NOT NULL,
    referencias VARCHAR(200) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_direccion_alias (id_cliente, alias),
    CONSTRAINT fk_direccion_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente)
) ENGINE=InnoDB;
