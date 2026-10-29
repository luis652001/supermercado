<?php
declare(strict_types=1);

/**
 * Genera .env a partir de .env.example con contraseñas y secretos aleatorios.
 * Si .env ya existe, solo agrega las variables nuevas (no cambia las existentes).
 * Uso:  php scripts/generar-env.php
 */
$raiz = dirname(__DIR__);
$destino = "$raiz/.env";

$aleatorios = [
    'DB_ROOT_PASSWORD' => 24,
    'AUTH_DB_PASSWORD' => 24,
    'CATALOGO_DB_PASSWORD' => 24,
    'PEDIDOS_DB_PASSWORD' => 24,
    'PAGOS_DB_PASSWORD' => 24,
    'DB_SLAVE_ROOT_PASSWORD' => 24,
    'REPLICATION_PASSWORD' => 24,
    'AUDIT_DB_ROOT_PASSWORD' => 24,
    'AUDIT_DB_PASSWORD' => 24,
    'REDIS_PASSWORD' => 24,
    'JWT_SECRET' => 64,
    'JWT_SERVICE_SECRET' => 64,
];

$existentes = [];
if (file_exists($destino)) {
    foreach (file($destino, FILE_IGNORE_NEW_LINES) as $linea) {
        if (preg_match('/^([A-Z_]+)=/', $linea, $m)) {
            $existentes[$m[1]] = true;
        }
    }
}

$adminPassword = null;
$nuevas = [];
foreach (file("$raiz/.env.example", FILE_IGNORE_NEW_LINES) as $linea) {
    if (!preg_match('/^([A-Z_]+)=/', $linea, $m) || isset($existentes[$m[1]])) {
        continue;
    }
    $clave = $m[1];
    if (isset($aleatorios[$clave])) {
        $linea = $clave . '=' . bin2hex(random_bytes(intdiv($aleatorios[$clave], 2)));
    } elseif ($clave === 'ADMIN_PASSWORD') {
        $adminPassword = 'Super' . bin2hex(random_bytes(4)) . 'A1';
        $linea = "ADMIN_PASSWORD=$adminPassword";
    }
    $nuevas[] = $linea;
}

if (!$nuevas) {
    echo ".env ya está completo; no hay variables nuevas.\n";
    exit(0);
}

if ($existentes) {
    file_put_contents($destino, "\n" . implode("\n", $nuevas) . "\n", FILE_APPEND);
    echo "Se agregaron a .env: " . implode(', ', array_map(static fn ($l) => strstr($l, '=', true), $nuevas)) . "\n";
} else {
    file_put_contents($destino, implode("\n", $nuevas) . "\n");
    echo ".env generado.\n";
}
if ($adminPassword !== null) {
    echo "Admin inicial: admin@superahorro.gt / $adminPassword\n";
}
