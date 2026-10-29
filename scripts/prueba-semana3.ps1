# Prueba semana 3: clima, pago demo, eventos de pago.
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$base = 'http://localhost'
Set-Location (Split-Path $PSScriptRoot -Parent)

function Llamar($metodo, $ruta, $cuerpo = $null, $token = $null) {
    $headers = @{}
    if ($token) { $headers.Authorization = "Bearer $token" }
    $params = @{ Method = $metodo; Uri = "$base$ruta"; Headers = $headers; ContentType = 'application/json; charset=utf-8'; UseBasicParsing = $true }
    if ($null -ne $cuerpo) { $params.Body = [Text.Encoding]::UTF8.GetBytes(($cuerpo | ConvertTo-Json -Compress -Depth 5)) }
    try {
        $r = Invoke-WebRequest @params
        return @{ status = [int]$r.StatusCode; datos = ($r.Content | ConvertFrom-Json) }
    } catch {
        $resp = $_.Exception.Response
        if (-not $resp) { throw }
        $contenido = $_.ErrorDetails.Message
        $datos = $null
        if ($contenido) { try { $datos = $contenido | ConvertFrom-Json } catch { $datos = $contenido } }
        return @{ status = [int]$resp.StatusCode; datos = $datos }
    }
}
function Verificar($n, $c, $d = '') {
    if ($c) { Write-Host "[OK]    $n" -ForegroundColor Green } else { Write-Host "[FALLA] $n $d" -ForegroundColor Red; $script:fallos++ }
}

$fallos = 0
$sufijo = Get-Random -Maximum 99999
$envVars = @{}
Get-Content .env | Where-Object { $_ -match '^[A-Z_]+=' } | ForEach-Object { $k, $v = $_ -split '=', 2; $envVars[$k] = $v }

Write-Host "`n=== Salud semana 3 ===" -ForegroundColor Cyan
Verificar 'pagos health' ((Llamar GET '/api/pagos/health').status -eq 200)
Verificar 'notificaciones health' ((Llamar GET '/api/notificaciones/health').status -eq 200)
$clima = Llamar GET '/api/notificaciones/clima'
Verificar 'clima responde (OpenWeather o mensaje demo)' ($clima.status -eq 200)

Write-Host "`n=== Pago demo sobre un pedido pendiente ===" -ForegroundColor Cyan
$cliente = @{ nombre = 'Pago Demo'; email = "pago$sufijo@correo.gt"; password = 'Cliente2026' }
Verificar 'registro' ((Llamar POST '/api/auth/registro' $cliente).status -eq 201)
$token = (Llamar POST '/api/auth/login' @{ email = $cliente.email; password = $cliente.password }).datos.token
$prod = (Llamar GET '/api/catalogo/productos?por_pagina=1').datos.productos[0]
Llamar PUT '/api/pedidos/carrito/items' @{ producto_id = $prod.id; cantidad = 1 } $token | Out-Null
$pedido = (Llamar POST '/api/pedidos/' @{
    direccion = 'Zona 1 prueba pago'; municipio = 'Guatemala'; telefono = '55551212'
} $token).datos.pedido
Verificar 'pedido pendiente creado' ($pedido.estado -eq 'pendiente')

$checkout = Llamar POST '/api/pagos/checkout' @{ pedido_uuid = $pedido.uuid } $token
Verificar 'checkout inicia (demo o stripe)' ($checkout.status -eq 200) "status=$($checkout.status)"
if ($checkout.datos.modo -eq 'demo') {
    $demo = Llamar POST '/api/pagos/demo-confirmar' @{ pedido_uuid = $pedido.uuid } $token
    Verificar 'demo-confirmar 200' ($demo.status -eq 200) "status=$($demo.status)"
} elseif ($checkout.datos.checkout_url) {
    Verificar 'URL de Stripe Checkout presente' ($checkout.datos.checkout_url -match 'stripe')
}

Start-Sleep 1
$pedido2 = (Llamar GET "/api/pedidos/$($pedido.uuid)" $null $token).datos.pedido
Verificar "pedido quedó pagado (estado=$($pedido2.estado))" ($pedido2.estado -eq 'pagado')

$admin = (Llamar POST '/api/auth/login' @{ email = $envVars.ADMIN_EMAIL; password = $envVars.ADMIN_PASSWORD }).datos.token
$res = Llamar GET '/api/auditoria/eventos/resumen' $null $admin
Verificar "tipos de evento >= 12 (hay $($res.datos.tipos_de_evento))" ($res.datos.tipos_de_evento -ge 12)
$acciones = @($res.datos.por_accion | ForEach-Object { $_.accion })
Verificar 'PAGO_INICIADO en bitácora' ($acciones -contains 'PAGO_INICIADO')
Verificar 'PAGO_CONFIRMADO en bitácora' ($acciones -contains 'PAGO_CONFIRMADO')

Write-Host ''
if ($fallos -eq 0) { Write-Host 'TODAS LAS PRUEBAS DE LA SEMANA 3 PASARON' -ForegroundColor Green }
else { Write-Host "$fallos prueba(s) fallaron" -ForegroundColor Red; exit 1 }
