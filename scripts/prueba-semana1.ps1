# Prueba de punta a punta del avance de la semana 1.
# Uso (con la plataforma levantada):  powershell -ExecutionPolicy Bypass -File scripts\prueba-semana1.ps1

$ErrorActionPreference = 'Stop'
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
    $params = @{ Method = $metodo; Uri = "$base$ruta"; Headers = $headers; ContentType = 'application/json'; UseBasicParsing = $true }
    if ($cuerpo) { $params.Body = ($cuerpo | ConvertTo-Json -Compress) }
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

function Verificar($nombre, $condicion, $detalle = '') {
    if ($condicion) { Write-Host "[OK]    $nombre" -ForegroundColor Green }
    else { Write-Host "[FALLA] $nombre $detalle" -ForegroundColor Red; $script:fallos++ }
}

function LoginInterno($email, $password) {
    $json = (@{ email = $email; password = $password } | ConvertTo-Json -Compress).Replace('"', '\"')
    $salida = docker compose exec -T auth-service curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost:8081/login -H "Content-Type: application/json" -d $json
    return [int]$salida
}

$fallos = 0
$sufijo = Get-Random -Maximum 99999
$cliente = @{ nombre = 'Cliente Prueba'; email = "cliente$sufijo@correo.gt"; password = 'Cliente2026' }

Write-Host "`n=== 1. Salud de los servicios ===" -ForegroundColor Cyan
Verificar 'Gateway responde (frontend)' ((Invoke-WebRequest "$base/" -UseBasicParsing).StatusCode -eq 200)
$r = Llamar GET '/api/auth/health'
Verificar 'auth-service accesible a través del gateway' ($r.status -eq 200)

Write-Host "`n=== 2. Registro y login (A07) ===" -ForegroundColor Cyan
$r = Llamar POST '/api/auth/registro' $cliente
Verificar 'Registro de cliente nuevo (201)' ($r.status -eq 201) "status=$($r.status)"
$r = Llamar POST '/api/auth/registro' @{ nombre = 'X'; email = "debil$sufijo@correo.gt"; password = '123' }
Verificar 'Rechaza contraseña débil (422)' ($r.status -eq 422) "status=$($r.status)"
$r = Llamar POST '/api/auth/login' @{ email = $cliente.email; password = $cliente.password }
Verificar 'Login de cliente devuelve JWT' ($r.status -eq 200 -and $r.datos.token) "status=$($r.status)"
$tokenCliente = $r.datos.token

Write-Host "`n=== 3. Escalamiento de privilegios (STRIDE E / A01) ===" -ForegroundColor Cyan
$r = Llamar GET '/api/auditoria/eventos' $null $tokenCliente
Verificar 'Cliente NO puede leer la bitácora (403)' ($r.status -eq 403) "status=$($r.status)"
$r = Llamar GET '/api/auth/admin/acceso' $null $tokenCliente
Verificar 'Cliente NO entra al panel admin (403, queda auditado)' ($r.status -eq 403) "status=$($r.status)"
$partes = $tokenCliente.Split('.')
$payload = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String(($partes[1].Replace('-', '+').Replace('_', '/')).PadRight([math]::Ceiling($partes[1].Length / 4) * 4, '=')))
$falso = ($payload -replace '"rol":"cliente"', '"rol":"admin"')
$falsoB64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($falso)).TrimEnd('=').Replace('+', '-').Replace('/', '_')
$r = Llamar GET '/api/auditoria/eventos' $null "$($partes[0]).$falsoB64.$($partes[2])"
Verificar 'JWT alterado a rol admin es rechazado (401)' ($r.status -eq 401) "status=$($r.status)"

Write-Host "`n=== 4. Bloqueo por fuerza bruta (5 intentos -> 15 min) ===" -ForegroundColor Cyan
$codigos = 1..5 | ForEach-Object { LoginInterno $cliente.email 'Incorrecta123' }
Verificar '5 intentos fallidos devuelven 401' (($codigos | Where-Object { $_ -ne 401 }).Count -eq 0) "codigos=$codigos"
$codigo = LoginInterno $cliente.email $cliente.password
Verificar 'Con la contraseña CORRECTA la cuenta sigue bloqueada (423)' ($codigo -eq 423) "codigo=$codigo"

Write-Host "`n=== 5. Rate limiting del gateway en /login ===" -ForegroundColor Cyan
$codigosGw = 1..8 | ForEach-Object { (Llamar POST '/api/auth/login' @{ email = "nadie$sufijo@correo.gt"; password = 'Nada12345' }).status }
Verificar 'Nginx responde 429 al exceder el límite por IP' ($codigosGw -contains 429) "codigos=$codigosGw"

Write-Host "`n=== 6. WAF básico ===" -ForegroundColor Cyan
$r = Llamar GET "/api/auth/health?id=1%27%20OR%20%271%27=%271"
Verificar "SQL injection en query string bloqueada (403)" ($r.status -eq 403) "status=$($r.status)"
$r = Llamar GET "/?q=%3Cscript%3Ealert(1)%3C/script%3E"
Verificar 'XSS en query string bloqueado (403)' ($r.status -eq 403) "status=$($r.status)"

Write-Host "`n=== 7. Admin, bitácora y logout ===" -ForegroundColor Cyan
Start-Sleep -Seconds 13
$r = Llamar POST '/api/auth/login' @{ email = $envVars.ADMIN_EMAIL; password = $envVars.ADMIN_PASSWORD }
Verificar 'Login de admin' ($r.status -eq 200) "status=$($r.status)"
$tokenAdmin = $r.datos.token
$r = Llamar GET '/api/auth/admin/acceso' $null $tokenAdmin
Verificar 'Admin accede al panel (ACCESO_ADMIN_PANEL)' ($r.status -eq 200) "status=$($r.status)"
$r = Llamar POST '/api/auth/logout' $null $tokenCliente
Verificar 'Logout del cliente (TOKEN_REVOCADO)' ($r.status -eq 200) "status=$($r.status)"
$r = Llamar GET '/api/auth/me' $null $tokenCliente
Verificar 'Token revocado ya no sirve (401)' ($r.status -eq 401) "status=$($r.status)"
$r = Llamar GET '/api/auditoria/eventos/resumen' $null $tokenAdmin
Verificar 'Resumen de bitácora disponible para admin' ($r.status -eq 200) "status=$($r.status)"
if ($r.status -eq 200) {
    Write-Host ("        Registros: {0} | Tipos de evento: {1} de {2}" -f $r.datos.total_registros, $r.datos.tipos_de_evento, $r.datos.tipos_requeridos)
    $r.datos.por_accion | Format-Table accion, resultado, total -AutoSize | Out-String | Write-Host
}

Write-Host "=== 8. Bitácora append-only ===" -ForegroundColor Cyan
$r = Llamar DELETE '/api/auditoria/eventos' $null $tokenAdmin
Verificar 'DELETE por la API bloqueado en el gateway (403)' ($r.status -eq 403) "status=$($r.status)"
$salida = 'DELETE FROM security_audit_logs;' | docker compose exec -T mariadb-audit sh -c 'mariadb -u$AUDIT_DB_USER -p$AUDIT_DB_PASSWORD audit_db 2>&1'
Verificar 'DELETE directo en la BD denegado al usuario del servicio' ("$salida" -match 'denied') "$salida"
$salida = "UPDATE security_audit_logs SET resultado='EXITO';" | docker compose exec -T mariadb-audit sh -c 'mariadb -uroot -p$MARIADB_ROOT_PASSWORD audit_db 2>&1'
Verificar 'Incluso root no puede hacer UPDATE (trigger)' ("$salida" -match 'append-only') "$salida"

Write-Host "`n=== 9. Hardening Docker (SEC-5) ===" -ForegroundColor Cyan
$puertos = docker ps --format '{{.Names}} {{.Ports}}'
$puertos | Write-Host
Verificar 'MariaDB y Redis NO publican puertos al host' (-not ($puertos | Where-Object { $_ -match '^(mariadb|redis)' -and $_ -match '0\.0\.0\.0' }))
$usuario = docker compose exec -T auth-service whoami
Verificar "auth-service corre como usuario no-root ($usuario)" ($usuario.Trim() -eq 'www-data')

Write-Host ''
if ($fallos -eq 0) { Write-Host 'TODAS LAS PRUEBAS PASARON' -ForegroundColor Green }
else { Write-Host "$fallos prueba(s) fallaron" -ForegroundColor Red; exit 1 }
