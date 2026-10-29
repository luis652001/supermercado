-- Capital de la tienda: caja del mes y costo guardado en cada venta.
SET NAMES utf8mb4;

USE catalogo_db;

CREATE TABLE IF NOT EXISTS caja_movimientos (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mes        CHAR(7) NOT NULL,
    tipo       ENUM('inyeccion', 'compra', 'retiro') NOT NULL,
    monto      DECIMAL(10,2) NOT NULL,
    nota       VARCHAR(200) NULL,
    lote_id    INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_caja_mes (mes, id)
) ENGINE=InnoDB;

USE pedidos_db;

ALTER TABLE detalle_pedidos
    ADD COLUMN IF NOT EXISTS costo_unitario DECIMAL(10,2) NULL;
