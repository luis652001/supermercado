<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../app/AuditService.php';

Http::run(function (): void {
    $metodo = Http::method();
    $ruta = Http::path();

    if (in_array($metodo, ['PUT', 'PATCH', 'DELETE'], true)) {
        header('Allow: GET, POST');
        Http::json(405, ['error' => 'security_audit_logs es append-only: no se permite modificar ni eliminar registros']);
    }

    match ("$metodo $ruta") {
        'GET /health' => Http::json(200, ['status' => 'ok', 'servicio' => 'security-audit-service']),
        'POST /eventos' => AuditService::registrar(),
        'GET /eventos' => AuditService::listar(),
        'GET /eventos/resumen' => AuditService::resumen(),
        default => Http::json(404, ['error' => 'Ruta no encontrada']),
    };
});
