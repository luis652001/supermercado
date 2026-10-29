-- Permite retirar efectivo de la caja del mes.
USE catalogo_db;

ALTER TABLE caja_movimientos
    MODIFY tipo ENUM('inyeccion', 'compra', 'retiro') NOT NULL;
