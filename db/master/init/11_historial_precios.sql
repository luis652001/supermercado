-- Historial de precios: se guarda el valor anterior y después se aplica el nuevo.
SET NAMES utf8mb4;
USE catalogo_db;

CREATE TABLE IF NOT EXISTS historial_precios (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id      INT UNSIGNED NOT NULL,
    lote_id          INT UNSIGNED NULL,
    venta_anterior   DECIMAL(10,2) NOT NULL,
    compra_anterior  DECIMAL(10,2) NOT NULL,
    venta_nueva      DECIMAL(10,2) NOT NULL,
    compra_nueva     DECIMAL(10,2) NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_historial_producto (producto_id, id),
    INDEX idx_historial_lote (lote_id)
) ENGINE=InnoDB;
