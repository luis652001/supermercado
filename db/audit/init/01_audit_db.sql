-- audit_db: base del security-audit-service, en un contenedor y volumen separados
-- de las bases de negocio (estructura según sección 6.1 del enunciado).
CREATE DATABASE IF NOT EXISTS audit_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE audit_db;

CREATE TABLE IF NOT EXISTS security_audit_logs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `timestamp`   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    usuario_id    INT NULL,
    usuario_email VARCHAR(120) NULL,
    rol           ENUM('admin', 'cliente', 'anonimo') NOT NULL DEFAULT 'anonimo',
    accion        VARCHAR(80) NOT NULL,
    servicio      VARCHAR(50) NOT NULL,
    pedido_id     INT NULL,
    pago_id       INT NULL,
    dato_anterior JSON NULL,
    dato_nuevo    JSON NULL,
    ip_origen     VARCHAR(45) NULL,
    user_agent    VARCHAR(255) NULL,
    resultado     ENUM('EXITO', 'FALLO', 'FRAUDE') NOT NULL,
    INDEX idx_audit_timestamp (`timestamp`),
    INDEX idx_audit_accion (accion),
    INDEX idx_audit_usuario (usuario_id),
    INDEX idx_audit_pedido (pedido_id),
    INDEX idx_audit_resultado (resultado)
) ENGINE=InnoDB;

-- Append-only reforzado en la BD: aunque alguien tenga acceso directo,
-- UPDATE y DELETE fallan.
CREATE TRIGGER IF NOT EXISTS trg_audit_no_update
    BEFORE UPDATE ON security_audit_logs FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_audit_logs es append-only: UPDATE no permitido';

CREATE TRIGGER IF NOT EXISTS trg_audit_no_delete
    BEFORE DELETE ON security_audit_logs FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_audit_logs es append-only: DELETE no permitido';
