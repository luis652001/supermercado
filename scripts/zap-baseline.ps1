# Escaneo OWASP ZAP baseline (gratis, con Docker).
# Uso:  powershell -ExecutionPolicy Bypass -File scripts\zap-baseline.ps1
# Requisito: la tienda debe estar en http://host.docker.internal:80

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
New-Item -ItemType Directory -Force -Path docs\evidencias | Out-Null

Write-Host "Ejecutando ZAP baseline contra http://host.docker.internal ..." -ForegroundColor Cyan
docker run --rm -v "${PWD}/docs/evidencias:/zap/wrk:rw" -t ghcr.io/zaproxy/zaproxy:stable `
  zap-baseline.py -t http://host.docker.internal -r zap-report.html -I

Write-Host "Reporte: docs\evidencias\zap-report.html" -ForegroundColor Green
