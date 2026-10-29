-- Separa lo que va en frío de lo fresco que se guarda sin refrigerar.
SET NAMES utf8mb4;
USE catalogo_db;

ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS conservacion ENUM('refrigerado', 'ambiente') NOT NULL DEFAULT 'ambiente';

UPDATE productos p
JOIN categorias c ON c.id = p.categoria_id
SET p.conservacion = 'refrigerado'
WHERE c.nombre IN ('Lácteos y Huevos', 'Carnes y Embutidos');
