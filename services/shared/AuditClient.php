<?php
declare(strict_types=1);

/**
 * Envía eventos al security-audit-service con un JWT de servicio.
 * Si el servicio de auditoría está caído, la operación de negocio continúa
 * y el evento queda en el log del contenedor marcado como AUDIT-PENDIENTE.
 */
final class AuditClient
{
    public static function log(string $accion, string $resultado, array $datos = []): void
    {
        $evento = array_merge([
            'ip_origen' => Http::clientIp(),
            'user_agent' => Http::userAgent(),
        ], $datos, [
            'accion' => $accion,
            'resultado' => $resultado,
        ]);

        try {
            $ch = curl_init(rtrim(Config::get('AUDIT_SERVICE_URL'), '/') . '/eventos');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($evento, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . Guard::issueServiceToken(),
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => 2,
            ]);
            $respuesta = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($respuesta === false || $status !== 201) {
                throw new RuntimeException("respuesta HTTP $status");
            }
        } catch (Throwable $e) {
            error_log('[AUDIT-PENDIENTE] ' . json_encode($evento, JSON_UNESCAPED_UNICODE) . ' motivo=' . $e->getMessage());
        }
    }
}
