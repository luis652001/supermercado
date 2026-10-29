<?php
declare(strict_types=1);

/**
 * Llamadas REST entre microservicios. Cada llamada lleva un JWT de servicio
 * recién emitido (Zero Trust) y reenvía la IP y el navegador del cliente
 * original para que la auditoría registre el origen real.
 */
final class ServiceClient
{
    /** @return array{0: int, 1: array} [status, cuerpo] */
    public static function call(string $metodo, string $url, ?array $cuerpo = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . Guard::issueServiceToken(),
                'X-Real-IP: ' . Http::clientIp(),
                'User-Agent: ' . Http::userAgent(),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
        }

        $respuesta = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false || $status === 0) {
            error_log("[ServiceClient] $metodo $url falló: $error");
            throw new HttpError(503, 'Un servicio interno no está disponible, intente de nuevo en unos minutos');
        }
        return [$status, json_decode($respuesta, true) ?? []];
    }
}
