# Prueba de punta a punta del avance de la semana 2 (catálogo, pedidos, Master-Slave, OWASP A01/A02/A03).
# Uso (con la plataforma levantada):  powershell -ExecutionPolicy Bypass -File scripts\prueba-semana2.ps1

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$base = 'http://localhost'
Set-Location (Split-Path $PSScriptRoot -Parent)

$envVars = @{}
Get-Content .env | Where-Object { $_ -match '^[A-Z_]+=' } | ForEach-Object {
    $k, $v = $_ -split '=', 2
    $envVars[$k] = $v
}

function Llamar($metodo, $ruta, $cuerpo = $null, $token = $null) {
    $headers = @{}
    if ($token) { $headers.Authorization = "Bearer $token" }
    $params = @{ Method = $metodo; Uri = "$base$ruta"; Headers = $headers; ContentType = 'application/json; charset=utf-8'; UseBasicParsing = $true }
    if ($null -ne $cuerpo) { $params.Body = [Text.Encoding]::UTF8.GetBytes(($cuerpo | ConvertTo-Json -Compress -Depth 5)) }
    try {
        $r = Invoke-WebRequest @params
        $texto = [Text.Encoding]::UTF8.GetString($r.RawContentStream.ToArray())
        return @{ status = [int]$r.StatusCode; datos = ($texto | ConvertFrom-Json) }
    } catch {
        $resp = $_.Exception.Response
        if (-not $resp) { throw }
        $contenido = $_.ErrorDetails.Message
        $datos = $null
        if ($contenido) { try { $datos = $contenido | ConvertFrom-Json } catch { $datos = $contenido } }
        return @{ status = [int]$resp.StatusCode; datos = $datos }
    }
}

function Verificar($nombre, $condicion, $detalle = '') {
    if ($condicion) { Write-Host "[OK]    $nombre" -ForegroundColor Green }
    else { Write-Host "[FALLA] $nombre $detalle" -ForegroundColor Red; $script:fallos++ }
}

function Login($email, $password) {
    for ($i = 0; $i -lt 4; $i++) {
        $r = Llamar POST '/api/auth/login' @{ email = $email; password = $password }
        if ($r.status -ne 429) { return $r.datos.token }
        Start-Sleep -Seconds 13
    }
    throw "No se pudo iniciar sesión con $email"
}

function Entrega($cambios = @{}) {
    $datos = $entrega.Clone()
    foreach ($k in $cambios.Keys) { $datos[$k] = $cambios[$k] }
    return $datos
}

function EventosDe($accion, $resultado = $null) {
    $ruta = "/api/auditoria/eventos?accion=$accion&limite=50"
    if ($resultado) { $ruta += "&resultado=$resultado" }
    return @((Llamar GET $ruta $null $tokenAdmin).datos.eventos)
}

$fallos = 0
$sufijo = Get-Random -Maximum 99999
$entrega = @{ direccion = '6a avenida 12-34, colonia Las Flores'; zona = 'Zona 10'; municipio = 'Guatemala'; telefono = '5555-1234'; referencias = 'Portón negro' }

Write-Host "`n=== 1. Catálogo público, caché Redis y lectura desde el Slave ===" -ForegroundColor Cyan
$r = Llamar GET '/api/catalogo/categorias'
Verificar 'Categorías sin iniciar sesión (200)' ($r.status -eq 200 -and $r.datos.categorias.Count -ge 5) "status=$($r.status)"
$consulta = "/api/catalogo/productos?q=a&por_pagina=48&pagina=1&x=$sufijo"
$r1 = Llamar GET $consulta
$r2 = Llamar GET $consulta
Verificar 'Listado de productos (200, 26+ productos)' ($r1.status -eq 200 -and $r1.datos.total -ge 20) "status=$($r1.status) total=$($r1.datos.total)"
Verificar "Lectura enrutada al Slave (fuente_bd=$($r1.datos.fuente_bd))" ($r1.datos.fuente_bd -eq 'slave')
Verificar "Primera consulta MISS y segunda HIT en Redis ($($r1.datos.cache) -> $($r2.datos.cache))" ($r1.datos.cache -eq 'MISS' -and $r2.datos.cache -eq 'HIT')
$r = Llamar GET '/api/catalogo/productos?q=%27%20OR%201%3D1%20--'
Verificar 'SQLi en búsqueda: bloqueada por WAF (403) o sin resultados' ($r.status -eq 403 -or ($r.status -eq 200 -and $r.datos.total -eq 0)) "status=$($r.status)"
$r = Llamar GET '/api/catalogo/productos?q=leche%25'
Verificar 'Comodín % escapado en LIKE (A03): no devuelve todo el catálogo' ($r.status -eq 200 -and $r.datos.total -eq 0) "status=$($r.status) total=$($r.datos.total)"

Write-Host "`n=== 2. Clientes de prueba ===" -ForegroundColor Cyan
$clienteA = @{ nombre = 'Ana Prueba'; email = "ana$sufijo@correo.gt"; password = 'Cliente2026' }
$clienteB = @{ nombre = 'Beto Prueba'; email = "beto$sufijo@correo.gt"; password = 'Cliente2026' }
Verificar 'Registro cliente A' ((Llamar POST '/api/auth/registro' $clienteA).status -eq 201)
Verificar 'Registro cliente B' ((Llamar POST '/api/auth/registro' $clienteB).status -eq 201)
$r = Llamar POST '/api/auth/login' @{ email = $clienteB.email; password = 'Equivocada99' }
Verificar 'Login con contraseña incorrecta (401, LOGIN_CLIENTE_FALLIDO)' ($r.status -eq 401) "status=$($r.status)"
$tokenA = Login $clienteA.email $clienteA.password
$tokenB = Login $clienteB.email $clienteB.password
$tokenAdmin = Login $envVars.ADMIN_EMAIL $envVars.ADMIN_PASSWORD
Verificar 'Tokens de A, B y admin obtenidos' ($tokenA -and $tokenB -and $tokenAdmin)
Verificar 'Admin entra al panel (ACCESO_ADMIN_PANEL)' ((Llamar GET '/api/auth/admin/acceso' $null $tokenAdmin).status -eq 200)

Write-Host "`n=== 3. Carrito en Redis y pedido con precios del servidor ===" -ForegroundColor Cyan
$producto = $r1.datos.productos | Where-Object { $_.stock -ge 10 } | Select-Object -First 1
$stockInicial = $producto.stock
$r = Llamar PUT '/api/pedidos/carrito/items' @{ producto_id = $producto.id; cantidad = 2 }
Verificar 'Carrito exige sesión (401 sin token)' ($r.status -eq 401) "status=$($r.status)"
$r = Llamar PUT '/api/pedidos/carrito/items' @{ producto_id = $producto.id; cantidad = 2 } $tokenA
$esperado = [math]::Round($producto.precio * 2, 2)
Verificar "Carrito calcula el total en el servidor (Q$($r.datos.total))" ($r.status -eq 200 -and [double]$r.datos.total -eq $esperado) "status=$($r.status) esperado=$esperado"
$r = Llamar PUT '/api/pedidos/carrito/items' @{ producto_id = $producto.id; cantidad = 999 } $tokenA
Verificar 'Cantidad fuera de rango rechazada (422)' ($r.status -eq 422) "status=$($r.status)"

$r = Llamar POST '/api/pedidos/' (Entrega @{ total = 0.01; items = @(@{ producto_id = $producto.id; precio = 0.01 }) }) $tokenA
Verificar 'Pedido con precio manipulado rechazado (400, auditado como FRAUDE)' ($r.status -eq 400) "status=$($r.status)"
$r = Llamar POST '/api/pedidos/' (Entrega @{ telefono = '123' }) $tokenA
Verificar 'Teléfono inválido rechazado (422)' ($r.status -eq 422) "status=$($r.status)"
$r = Llamar POST '/api/pedidos/' (Entrega @{ notas = '<script>alert(1)</script>Tocar timbre' }) $tokenA
Verificar 'Pedido creado (201)' ($r.status -eq 201) "status=$($r.status) $($r.datos | ConvertTo-Json -Compress)"
$pedido = $r.datos.pedido
Verificar "Total del pedido = precio real del catálogo x 2 (Q$($pedido.total))" ([double]$pedido.total -eq $esperado)
Verificar 'Se expone UUID y NO el id secuencial' ($pedido.uuid -match '^[0-9a-f-]{36}$' -and -not $pedido.PSObject.Properties['id'])
Verificar 'Etiquetas HTML eliminadas de las notas (A03)' ($pedido.notas -eq 'alert(1)Tocar timbre') "notas=$($pedido.notas)"
$r = Llamar GET '/api/pedidos/carrito' $null $tokenA
Verificar 'Carrito vacío después de comprar' ($r.datos.items.Count -eq 0)
Start-Sleep -Seconds 1
$r = Llamar GET "/api/catalogo/productos/$($producto.id)"
Verificar "Stock descontado ($stockInicial -> $($r.datos.producto.stock))" ($r.datos.producto.stock -eq $stockInicial - 2)

Write-Host "`n=== 4. Control de acceso (A01) ===" -ForegroundColor Cyan
Verificar 'El dueño ve su pedido (200)' ((Llamar GET "/api/pedidos/$($pedido.uuid)" $null $tokenA).status -eq 200)
$r = Llamar GET "/api/pedidos/$($pedido.uuid)" $null $tokenB
Verificar 'Otro cliente NO ve el pedido ajeno (404, no revela que existe)' ($r.status -eq 404) "status=$($r.status)"
$r = Llamar GET '/api/pedidos/' $null $tokenB
Verificar 'Mis pedidos de B no incluye pedidos de A' ($r.status -eq 200 -and @($r.datos.pedidos | Where-Object { $_.uuid -eq $pedido.uuid }).Count -eq 0)
$r = Llamar PUT "/api/catalogo/admin/productos/$($producto.id)" @{ precio = 0.5 } $tokenA
Verificar 'Cliente NO puede cambiar precios (403)' ($r.status -eq 403) "status=$($r.status)"
$r = Llamar PUT "/api/pedidos/admin/pedidos/$($pedido.uuid)/estado" @{ estado = 'entregado' } $tokenA
Verificar 'Cliente NO puede cambiar el estado de su pedido (403)' ($r.status -eq 403) "status=$($r.status)"
$r = Llamar GET '/api/pedidos/admin/clientes/exportar' $null $tokenA
Verificar 'Cliente NO puede exportar clientes (403)' ($r.status -eq 403) "status=$($r.status)"
$r = Llamar POST '/api/catalogo/interno/stock/reponer' @{ items = @(@{ producto_id = $producto.id; cantidad = 50 }) } $tokenA
Verificar 'Endpoints /interno NO son accesibles desde fuera (404 en gateway)' ($r.status -eq 404) "status=$($r.status)"
$codigo = docker compose exec -T pedidos-service curl -s -o /dev/null -w "%{http_code}" -X POST http://catalogo-service:8082/interno/stock/reponer -H "Authorization: Bearer $tokenA" -H 'Content-Type: application/json' -d '{}'
Verificar "Zero Trust: token de usuario rechazado en /interno dentro de la red ($codigo)" ($codigo -in '401', '403')

Write-Host "`n=== 5. Administración de catálogo y pedidos ===" -ForegroundColor Cyan
$precioOriginal = $producto.precio
$nuevoPrecio = [math]::Round($precioOriginal + 1.25, 2)
$r = Llamar PUT "/api/catalogo/admin/productos/$($producto.id)" @{ precio = $nuevoPrecio } $tokenAdmin
Verificar "Admin cambia precio Q$precioOriginal -> Q$nuevoPrecio (PRECIO_MODIFICADO)" ($r.status -eq 200 -and $r.datos.producto.precio -eq $nuevoPrecio) "status=$($r.status)"
$r = Llamar GET $consulta
Verificar 'Caché invalidada: el listado ya muestra el precio nuevo' (($r.datos.productos | Where-Object { $_.id -eq $producto.id }).precio -eq $nuevoPrecio)
Llamar PUT "/api/catalogo/admin/productos/$($producto.id)" @{ precio = $precioOriginal } $tokenAdmin | Out-Null
$r = Llamar PUT "/api/catalogo/admin/productos/$($producto.id)" @{ imagen_url = 'https://evil.example.com/x.png' } $tokenAdmin
Verificar 'Imagen solo desde res.cloudinary.com (422)' ($r.status -eq 422) "status=$($r.status)"

$nombreNuevo = "Producto Prueba $sufijo"
$r = Llamar POST '/api/catalogo/admin/productos' @{ nombre = $nombreNuevo; precio = 9.99; stock = 1; categoria_id = 1; unidad = 'unidad' } $tokenAdmin
Verificar 'Admin crea producto con stock 1 (201)' ($r.status -eq 201) "status=$($r.status)"
$nuevo = $r.datos.producto
Llamar PUT '/api/pedidos/carrito/items' @{ producto_id = $nuevo.id; cantidad = 1 } $tokenB | Out-Null
$r = Llamar POST '/api/pedidos/' $entrega $tokenB
Verificar 'Cliente B compra la última unidad (PRODUCTO_AGOTADO)' ($r.status -eq 201) "status=$($r.status)"
$pedidoB = $r.datos.pedido
$r = Llamar PUT '/api/pedidos/carrito/items' @{ producto_id = $nuevo.id; cantidad = 1 } $tokenA
Verificar 'Producto agotado ya no se puede agregar (409)' ($r.status -eq 409) "status=$($r.status)"
$r = Llamar PUT "/api/pedidos/admin/pedidos/$($pedidoB.uuid)/estado" @{ estado = 'cancelado' } $tokenAdmin
Verificar 'Admin cancela el pedido de B (200)' ($r.status -eq 200) "status=$($r.status)"
Start-Sleep -Seconds 1
$r = Llamar GET "/api/catalogo/productos/$($nuevo.id)"
Verificar "Cancelar repone el stock ($($r.datos.producto.stock))" ($r.datos.producto.stock -eq 1)

$ok = $true
foreach ($estado in 'preparando', 'en_camino', 'entregado') {
    $ok = $ok -and ((Llamar PUT "/api/pedidos/admin/pedidos/$($pedido.uuid)/estado" @{ estado = $estado } $tokenAdmin).status -eq 200)
}
Verificar 'Flujo pendiente -> preparando -> en_camino -> entregado' $ok
$r = Llamar PUT "/api/pedidos/admin/pedidos/$($pedido.uuid)/estado" @{ estado = 'pendiente' } $tokenAdmin
Verificar 'Transición inválida entregado -> pendiente (409)' ($r.status -eq 409) "status=$($r.status)"

$csv = Invoke-WebRequest "$base/api/pedidos/admin/clientes/exportar" -Headers @{ Authorization = "Bearer $tokenAdmin" } -UseBasicParsing
$textoCsv = [Text.Encoding]::UTF8.GetString($csv.RawContentStream.ToArray())
Verificar 'Exportación CSV de clientes (EXPORTACION_CLIENTES)' ($csv.StatusCode -eq 200 -and $textoCsv -match 'id_cliente,nombre,email' -and $textoCsv -match "ana$sufijo")
Llamar DELETE "/api/catalogo/admin/productos/$($nuevo.id)" $null $tokenAdmin | Out-Null

Write-Host "`n=== 6. Replicación Master-Slave ===" -ForegroundColor Cyan
Start-Sleep -Seconds 1
$salida = "SELECT COUNT(*) FROM catalogo_db.productos WHERE nombre = '$nombreNuevo';" | docker compose exec -T mariadb-slave sh -c 'mariadb -N -uroot -p$MARIADB_ROOT_PASSWORD 2>&1'
Verificar 'El producto creado en el Master ya está en el Slave' ("$salida".Trim() -eq '1') "$salida"
$estadoSlave = 'SHOW SLAVE STATUS\G' | docker compose exec -T mariadb-slave sh -c 'mariadb -uroot -p$MARIADB_ROOT_PASSWORD 2>&1'
$io = ($estadoSlave | Select-String 'Slave_IO_Running:').ToString().Trim()
$sqlHilo = ($estadoSlave | Select-String 'Slave_SQL_Running:').ToString().Trim()
Verificar "$io / $sqlHilo" ($io -match 'Yes' -and $sqlHilo -match 'Yes')
$salida = 'UPDATE catalogo_db.productos SET stock = 0 WHERE id = 1;' | docker compose exec -T mariadb-slave sh -c 'mariadb -u$CATALOGO_DB_USER -p$CATALOGO_DB_PASSWORD 2>&1'
Verificar 'El Slave rechaza escrituras (read-only)' ("$salida" -match 'read-only|denied') "$salida"

Write-Host "`n=== 7. Bitácora: eventos de la semana 2 ===" -ForegroundColor Cyan
Verificar 'Logout de B (TOKEN_REVOCADO)' ((Llamar POST '/api/auth/logout' $null $tokenB).status -eq 200)
Verificar 'PEDIDO_CREADO con resultado FRAUDE registrado' (@(EventosDe 'PEDIDO_CREADO' 'FRAUDE' | Where-Object { $_.usuario_email -eq $clienteA.email }).Count -ge 1)
Verificar 'PEDIDO_CREADO EXITO registrado' (@(EventosDe 'PEDIDO_CREADO' 'EXITO' | Where-Object { $_.usuario_email -eq $clienteA.email }).Count -ge 1)
Verificar 'PRECIO_MODIFICADO registrado' (@(EventosDe 'PRECIO_MODIFICADO').Count -ge 2)
Verificar 'PRODUCTO_AGOTADO registrado (con pedido_id)' (@(EventosDe 'PRODUCTO_AGOTADO' | Where-Object { $_.pedido_id }).Count -ge 1)
Verificar 'PEDIDO_ESTADO_CAMBIADO registrado' (@(EventosDe 'PEDIDO_ESTADO_CAMBIADO').Count -ge 4)
Verificar 'EXPORTACION_CLIENTES registrado' (@(EventosDe 'EXPORTACION_CLIENTES').Count -ge 1)
$r = Llamar GET '/api/auditoria/eventos/resumen' $null $tokenAdmin
Verificar "Tipos de evento distintos en la bitácora: $($r.datos.tipos_de_evento) de $($r.datos.tipos_requeridos) (meta semana 2: 8+)" ($r.datos.tipos_de_evento -ge 8)
$r.datos.por_accion | Format-Table accion, resultado, total -AutoSize | Out-String | Write-Host

Write-Host "=== 8. Hardening y alta disponibilidad ===" -ForegroundColor Cyan
foreach ($servicio in 'catalogo-service', 'pedidos-service') {
    $usuario = (docker compose exec -T $servicio whoami).Trim()
    Verificar "$servicio corre como usuario no-root ($usuario)" ($usuario -eq 'www-data')
}
$puertos = docker ps --format '{{.Names}} {{.Ports}}'
Verificar 'Ninguna base de datos ni Redis publica puertos al host' (-not ($puertos | Where-Object { $_ -match '^(mariadb|redis)' -and $_ -match '0\.0\.0\.0' }))

cmd /c 'docker compose stop mariadb-slave 2>&1' | Out-Null
$r = Llamar GET "/api/catalogo/productos?q=e&failover=$sufijo"
Verificar "Slave caído: el catálogo sigue funcionando desde el Master (fuente_bd=$($r.datos.fuente_bd))" ($r.status -eq 200 -and $r.datos.fuente_bd -like 'master*') "status=$($r.status)"
cmd /c 'docker compose start mariadb-slave 2>&1' | Out-Null
Write-Host '        Slave reiniciado; vuelve a replicar automáticamente.'

Write-Host ''
if ($fallos -eq 0) { Write-Host 'TODAS LAS PRUEBAS DE LA SEMANA 2 PASARON' -ForegroundColor Green }
else { Write-Host "$fallos prueba(s) fallaron" -ForegroundColor Red; exit 1 }
