-- La venta se arma con costo + inyección + margen de utilidad.
SET NAMES utf8mb4;

USE catalogo_db;

ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS inyeccion DECIMAL(10,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS margen_utilidad DECIMAL(10,2) NOT NULL DEFAULT 0;

UPDATE productos
SET margen_utilidad = ROUND(GREATEST(precio - precio_compra, 0), 2)
WHERE inyeccion = 0 AND margen_utilidad = 0;

ALTER TABLE historial_precios
    ADD COLUMN IF NOT EXISTS inyeccion_anterior DECIMAL(10,2) NULL,
    ADD COLUMN IF NOT EXISTS margen_anterior DECIMAL(10,2) NULL,
    ADD COLUMN IF NOT EXISTS inyeccion_nueva DECIMAL(10,2) NULL,
    ADD COLUMN IF NOT EXISTS margen_nueva DECIMAL(10,2) NULL;

USE pedidos_db;

ALTER TABLE detalle_pedidos
    ADD COLUMN IF NOT EXISTS margen_unitario DECIMAL(10,2) NULL;
