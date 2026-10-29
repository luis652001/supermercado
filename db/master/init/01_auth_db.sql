-- auth_db: usuarios del auth-service (estructura según sección 11 del enunciado)
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS auth_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE auth_db;

CREATE TABLE IF NOT EXISTS usuarios (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre            VARCHAR(100) NOT NULL,
    email             VARCHAR(120) NOT NULL,
    password_hash     VARCHAR(255) NOT NULL,
    rol               ENUM('cliente', 'admin', 'operador', 'contador') NOT NULL DEFAULT 'cliente',
    activo            TINYINT(1) NOT NULL DEFAULT 1,
    intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_hasta   DATETIME NULL,
    ultimo_login      DATETIME NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_usuarios_email (email)
) ENGINE=InnoDB;

-- Bases de los demás servicios (catálogo y pedidos en 03_ y 04_; pagos en la semana 3)
CREATE DATABASE IF NOT EXISTS catalogo_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS pedidos_db  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS pagos_db    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
