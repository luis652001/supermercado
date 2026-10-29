<?php
declare(strict_types=1);

/**
 * Pruebas rápidas de la librería JWT (no requieren Docker).
 * Uso:  php scripts/test-jwt.php
 */
require_once __DIR__ . '/../services/shared/Jwt.php';

$secreto = str_repeat('a', 64);
$fallos = 0;

function prueba(string $nombre, callable $fn): void
{
    global $fallos;
    try {
        $fn();
        echo "[OK]    $nombre\n";
    } catch (Throwable $e) {
        $fallos++;
        echo "[FALLA] $nombre: {$e->getMessage()}\n";
    }
}

function esperaRechazo(callable $fn): void
{
    try {
        $fn();
    } catch (JwtException) {
        return;
    }
    throw new RuntimeException('Se aceptó un token que debía rechazarse');
}

$claims = ['iss' => Jwt::ISSUER, 'sub' => '1', 'rol' => 'cliente', 'exp' => time() + 3600];
$token = Jwt::encode($claims, $secreto);

prueba('Token válido se acepta', function () use ($token, $secreto) {
    if (Jwt::decode($token, $secreto)['rol'] !== 'cliente') {
        throw new RuntimeException('Claims incorrectos');
    }
});

prueba('Rechaza firma con otro secreto', fn () => esperaRechazo(fn () => Jwt::decode($token, str_repeat('b', 64))));

prueba('Rechaza escalamiento de rol (payload alterado a admin)', function () use ($token, $secreto) {
    [$h, , $s] = explode('.', $token);
    $payload = rtrim(strtr(base64_encode(json_encode(['rol' => 'admin'] + $GLOBALS['claims'])), '+/', '-_'), '=');
    esperaRechazo(fn () => Jwt::decode("$h.$payload.$s", $secreto));
});

prueba('Rechaza alg=none', function () use ($secreto) {
    $h = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
    $p = rtrim(strtr(base64_encode(json_encode(['iss' => Jwt::ISSUER, 'rol' => 'admin', 'exp' => time() + 60])), '+/', '-_'), '=');
    esperaRechazo(fn () => Jwt::decode("$h.$p.", $secreto));
});

prueba('Rechaza token expirado', fn () => esperaRechazo(fn () => Jwt::decode(
    Jwt::encode(['iss' => Jwt::ISSUER, 'exp' => time() - 3600], $secreto),
    $secreto
)));

prueba('Rechaza emisor distinto', fn () => esperaRechazo(fn () => Jwt::decode(
    Jwt::encode(['iss' => 'otro', 'exp' => time() + 60], $secreto),
    $secreto
)));

echo $fallos === 0 ? "\nTodas las pruebas pasaron.\n" : "\n$fallos prueba(s) fallaron.\n";
exit($fallos === 0 ? 0 : 1);
