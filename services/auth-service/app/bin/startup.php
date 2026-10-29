<?php
declare(strict_types=1);

/**
 * Se ejecuta al iniciar el contenedor: crea el administrador inicial
 * a partir de ADMIN_EMAIL / ADMIN_PASSWORD (.env) si todavía no existe.
 */
require_once __DIR__ . '/../../shared/bootstrap.php';

$email = mb_strtolower(trim(Config::get('ADMIN_EMAIL', '')));
$password = Config::get('ADMIN_PASSWORD', '');
if ($email === '' || $password === '') {
    fwrite(STDERR, "[startup] ADMIN_EMAIL/ADMIN_PASSWORD no definidos; no se crea admin.\n");
    exit(0);
}

$db = null;
for ($intento = 1; $intento <= 30; $intento++) {
    try {
        $db = Db::get();
        break;
    } catch (PDOException) {
        fwrite(STDERR, "[startup] Esperando base de datos ($intento/30)...\n");
        sleep(2);
    }
}
if ($db === null) {
    fwrite(STDERR, "[startup] No se pudo conectar a la base de datos.\n");
    exit(0);
}

$stmt = $db->prepare('SELECT id FROM usuarios WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    exit(0);
}

$db->prepare('INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES (?, ?, ?, \'admin\')')
    ->execute([
        Config::get('ADMIN_NOMBRE', 'Administrador'),
        $email,
        password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
    ]);
fwrite(STDERR, "[startup] Administrador inicial creado: $email\n");
