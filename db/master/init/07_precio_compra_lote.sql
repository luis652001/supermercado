-- Precio de compra interno y fecha de ingreso del lote.
-- Idempotente: una instalación nueva y una base que ya tiene datos.
SET NAMES utf8mb4;
USE catalogo_db;

ALTER TABLE productos ADD COLUMN IF NOT EXISTS precio_compra DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE lotes ADD COLUMN IF NOT EXISTS fecha_ingreso DATE NULL;
UPDATE lotes SET fecha_ingreso = DATE(created_at) WHERE fecha_ingreso IS NULL;
