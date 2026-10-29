# Aplica pagos_db + usuario en un Master ya inicializado (sin borrar datos).
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)

$envVars = @{}
Get-Content .env | Where-Object { $_ -match '^[A-Z_]+=' } | ForEach-Object {
    $k, $v = $_ -split '=', 2
    $envVars[$k] = $v
}

$user = $envVars.PAGOS_DB_USER
$pass = $envVars.PAGOS_DB_PASSWORD
if (-not $user -or -not $pass) { throw 'Faltan PAGOS_DB_USER / PAGOS_DB_PASSWORD en .env' }

$sql = @"
CREATE DATABASE IF NOT EXISTS pagos_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pagos_db;
CREATE TABLE IF NOT EXISTS pagos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id INT UNSIGNED NOT NULL,
    pedido_uuid CHAR(36) NOT NULL,
    id_cliente INT UNSIGNED NOT NULL,
    monto DECIMAL(10,2) NOT NULL,
    moneda CHAR(3) NOT NULL DEFAULT 'GTQ',
    estado ENUM('iniciado','completado','fallido','cancelado') NOT NULL DEFAULT 'iniciado',
    proveedor VARCHAR(20) NOT NULL DEFAULT 'stripe',
    payment_intent_id VARCHAR(120) NULL,
    checkout_session_id VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pagos_pedido_uuid (pedido_uuid)
) ENGINE=InnoDB;
CREATE USER IF NOT EXISTS '$user'@'%' IDENTIFIED BY '$pass';
GRANT SELECT, INSERT, UPDATE ON pagos_db.* TO '$user'@'%';
FLUSH PRIVILEGES;
"@

$sql | docker compose exec -T mariadb-master sh -c 'mariadb -uroot -p$MARIADB_ROOT_PASSWORD 2>&1'
Write-Host "pagos_db listo." -ForegroundColor Green
