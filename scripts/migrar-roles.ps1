# Amplía el ENUM de roles sin borrar datos.
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$sql = @"
ALTER TABLE auth_db.usuarios
  MODIFY rol ENUM('cliente','admin','operador','contador') NOT NULL DEFAULT 'cliente';
"@
$sql | docker compose exec -T mariadb-master sh -c 'mariadb -uroot -p$MARIADB_ROOT_PASSWORD 2>&1'
Write-Host 'Roles actualizados en auth_db.usuarios' -ForegroundColor Green
