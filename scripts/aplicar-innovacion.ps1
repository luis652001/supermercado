# Aplica productores, presentaciones, FEFO, envases y seguimiento
# sobre la base que ya está corriendo (no borra pedidos ni usuarios).
$ErrorActionPreference = 'Stop'
$raiz = Split-Path -Parent $PSScriptRoot
$sql = Join-Path $raiz 'db\master\init\06_innovacion.sql'
if (-not (Test-Path $sql)) { throw "No se encontró $sql" }

Write-Host 'Aplicando innovación Mercado Verde en mariadb-master...'
Get-Content -Raw -Encoding utf8 $sql | docker compose -f (Join-Path $raiz 'docker-compose.yml') exec -T mariadb-master sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"'
if ($LASTEXITCODE -ne 0) { throw 'La migración no se aplicó. ¿Está levantado docker compose?' }

Write-Host 'Listo. Recarga el catálogo (Ctrl+F5). Si el catálogo sigue en caché, espera 2 minutos o reinicia catalogo-service.'
