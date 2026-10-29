<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../app/NotificacionesService.php';

Http::run(function (): void {
    (new Router())
        ->add('GET', '/health', fn () => Http::json(200, ['status' => 'ok', 'servicio' => 'notificaciones-service']))
        ->add('GET', '/clima', [NotificacionesService::class, 'clima'])
        ->add('POST', '/interno/enviar', [NotificacionesService::class, 'enviarInterno'])
        ->dispatch();
});
