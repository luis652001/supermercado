-- Permite devolver el saldo de regalo si el cliente anula el pago contra entrega.
SET NAMES utf8mb4;
USE pagos_db;
ALTER TABLE pagos ADD COLUMN IF NOT EXISTS regalo_id INT UNSIGNED NULL;
