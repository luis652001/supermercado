-- pagos_db: solo referencias a Stripe (nunca PAN, CVV ni fecha de tarjeta).
SET NAMES utf8mb4;
USE pagos_db;

CREATE TABLE IF NOT EXISTS pagos (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id            INT UNSIGNED NOT NULL,
    pedido_uuid          CHAR(36) NOT NULL,
    id_cliente           INT UNSIGNED NOT NULL,
    monto                DECIMAL(10,2) NOT NULL,
    moneda               CHAR(3) NOT NULL DEFAULT 'GTQ',
    estado               ENUM('iniciado', 'completado', 'fallido', 'cancelado') NOT NULL DEFAULT 'iniciado',
    proveedor            VARCHAR(20) NOT NULL DEFAULT 'stripe',
    payment_intent_id    VARCHAR(120) NULL,
    checkout_session_id  VARCHAR(120) NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pagos_pedido_uuid (pedido_uuid),
    INDEX idx_pagos_session (checkout_session_id),
    INDEX idx_pagos_intent (payment_intent_id)
) ENGINE=InnoDB;
