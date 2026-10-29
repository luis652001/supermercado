<?php
declare(strict_types=1);

// Carga .env manualmente (fuera de Docker) o usa getenv dentro del contenedor.
$raiz = dirname(__DIR__);
if (!getenv('EMAILJS_SERVICE_ID') && is_file("$raiz/.env")) {
    foreach (file("$raiz/.env", FILE_IGNORE_NEW_LINES) as $linea) {
        if (preg_match('/^([A-Z_]+)=(.*)$/', $linea, $m) && getenv($m[1]) === false) {
            putenv("$m[1]=$m[2]");
        }
    }
}

$payload = [
    'service_id' => getenv('EMAILJS_SERVICE_ID') ?: '',
    'template_id' => getenv('EMAILJS_TEMPLATE_ID') ?: '',
    'user_id' => getenv('EMAILJS_PUBLIC_KEY') ?: '',
    'template_params' => [
        'pedido_uuid' => 'test-local',
        'plantilla' => 'pago_confirmado',
        'mensaje' => 'Prueba SuperAhorro GT',
    ],
];
$private = getenv('EMAILJS_PRIVATE_KEY') ?: '';
if ($private !== '' && $private !== 'xxx') {
    $payload['accessToken'] = $private;
}

$ch = curl_init('https://api.emailjs.com/api/v1.0/email/send');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 15,
]);
$raw = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP=$code\n";
echo substr((string) $raw, 0, 400), "\n";
echo 'service=', $payload['service_id'], ' template=', $payload['template_id'], "\n";
