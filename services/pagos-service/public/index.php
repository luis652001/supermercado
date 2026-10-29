<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../app/PagosService.php';

Http::run(function (): void {
    $path = Http::path();
    $method = Http::method();

    // Webhook de Stripe: sin JWT; la autenticación es la firma Stripe-Signature.
    if ($method === 'POST' && $path === '/webhook') {
        PagosService::webhookStripe();
    }

    (new Router())
        ->add('GET', '/health', fn () => Http::json(200, ['status' => 'ok', 'servicio' => 'pagos-service']))
        ->add('POST', '/checkout', [PagosService::class, 'iniciarCheckout'])
        ->add('POST', '/demo-confirmar', [PagosService::class, 'demoConfirmar'])
        ->add('POST', '/contra-entrega', [PagosService::class, 'contraEntrega'])
        ->add('POST', '/contra-entrega/anular', [PagosService::class, 'anularContraEntrega'])
        ->add('GET', '/metodos', [PagosService::class, 'misMetodos'])
        ->add('POST', '/metodos', [PagosService::class, 'guardarTarjeta'])
        ->add('POST', '/metodos/canjear', [PagosService::class, 'canjearRegalo'])
        ->add('DELETE', '/metodos/(\d+)', [PagosService::class, 'quitarMetodo'])
        ->add('GET', '/pedido/([0-9a-f-]{36})', [PagosService::class, 'estadoPago'])
        ->add('GET', '/admin/reporte', [PagosService::class, 'adminReporte'])
        ->add('GET', '/admin/capital', [PagosService::class, 'adminCapital'])
        ->add('GET', '/admin/pagos', [PagosService::class, 'adminListar'])
        ->add('POST', '/admin/tarjetas-regalo', [PagosService::class, 'emitirRegalo'])
        ->add('POST', '/admin/cobrar-efectivo', [PagosService::class, 'cobrarEfectivo'])
        ->add('POST', '/interno/liberar-efectivo', [PagosService::class, 'internoLiberarEfectivo'])
        ->add('POST', '/interno/cobrado', [PagosService::class, 'internoCobrado'])
        ->dispatch();
});

