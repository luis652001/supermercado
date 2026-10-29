<?php
declare(strict_types=1);

final class NotificacionesService
{
    public static function clima(): never
    {
        // Público (rate-limited en gateway): clima de Guatemala para la entrega.
        $key = Config::get('OPENWEATHER_API_KEY', '');
        if ($key === '' || $key === 'xxx') {
            Http::json(200, [
                'disponible' => false,
                'mensaje' => 'OpenWeather no configurado (gratis en openweathermap.org).',
                'ciudad' => 'Guatemala',
            ]);
        }

        [$cache, $hit] = Cache::remember('clima:guatemala', 600, static function () use ($key): array {
            $url = 'https://api.openweathermap.org/data/2.5/weather?q=Guatemala%20City,GT&appid='
                . urlencode($key) . '&units=metric&lang=es';
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($raw === false || $code >= 400) {
                throw new HttpError(503, 'No se pudo obtener el clima');
            }
            $data = json_decode($raw, true) ?: [];
            return [
                'disponible' => true,
                'ciudad' => $data['name'] ?? 'Guatemala',
                'descripcion' => $data['weather'][0]['description'] ?? '',
                'temp_c' => isset($data['main']['temp']) ? round((float) $data['main']['temp'], 1) : null,
                'humedad' => $data['main']['humidity'] ?? null,
                'icono' => $data['weather'][0]['icon'] ?? null,
            ];
        });

        $cache['cache'] = $hit ? 'HIT' : 'MISS';
        Http::json(200, $cache);
    }

    public static function enviarInterno(): never
    {
        Guard::service(['pagos-service', 'pedidos-service']);
        $cuerpo = Http::body();
        $plantilla = (string) ($cuerpo['plantilla'] ?? '');
        $uuid = (string) ($cuerpo['pedido_uuid'] ?? '');

        $resultado = self::enviarEmailjs($plantilla, $cuerpo);
        if (!$resultado['ok']) {
            error_log('[NOTIF] Fallo EmailJS: ' . $resultado['detalle'] . ' pedido=' . $uuid);
        }

        // La notificación no es uno de los 14 eventos obligatorios; el pago ya audita PAGO_CONFIRMADO.
        Http::json($resultado['ok'] ? 200 : 503, [
            'enviado' => $resultado['ok'],
            'detalle' => $resultado['detalle'],
        ]);
    }

    private static function enviarEmailjs(string $plantilla, array $datos): array
    {
        $service = Config::get('EMAILJS_SERVICE_ID', '');
        $template = Config::get('EMAILJS_TEMPLATE_ID', '');
        $user = Config::get('EMAILJS_PUBLIC_KEY', '');
        $private = Config::get('EMAILJS_PRIVATE_KEY', '');

        if ($service === '' || $template === '' || $user === '' || $service === 'xxx') {
            // Modo demo: no falla el flujo de negocio; queda auditado como FALLO suave.
            error_log('[NOTIF-DEMO] plantilla=' . $plantilla . ' pedido=' . ($datos['pedido_uuid'] ?? ''));
            return ['ok' => true, 'detalle' => 'demo_sin_emailjs (configure EMAILJS_* en .env)'];
        }

        $payload = [
            'service_id' => $service,
            'template_id' => $template,
            'user_id' => $user,
            'template_params' => [
                'pedido_uuid' => $datos['pedido_uuid'] ?? '',
                'plantilla' => $plantilla,
                'mensaje' => $plantilla === 'pago_confirmado'
                    ? 'Tu pago fue confirmado. Pronto prepararemos tu pedido.'
                    : 'Actualización de tu pedido en SuperAhorro GT.',
            ],
        ];
        if ($private !== '' && $private !== 'xxx') {
            $payload['accessToken'] = $private;
        }

        $ch = curl_init('https://api.emailjs.com/api/v1.0/email/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false || $code >= 400) {
            return ['ok' => false, 'detalle' => is_string($raw) ? mb_substr($raw, 0, 200) : curl_error($ch)];
        }
        return ['ok' => true, 'detalle' => 'emailjs_ok'];
    }
}
