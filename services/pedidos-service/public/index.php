<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../app/PedidosService.php';

const UUID = '([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})';

Http::run(function (): void {
    (new Router())
        ->add('GET', '/health', fn () => Http::json(200, ['status' => 'ok', 'servicio' => 'pedidos-service']))
        // Cliente autenticado
        ->add('GET', '/carrito', [PedidosService::class, 'verCarrito'])
        ->add('PUT', '/carrito/items', [PedidosService::class, 'actualizarItem'])
        ->add('DELETE', '/carrito', [PedidosService::class, 'vaciarCarrito'])
        ->add('GET', '/direccion', [PedidosService::class, 'miDireccion'])
        ->add('PUT', '/direccion', [PedidosService::class, 'guardarDireccion'])
        ->add('GET', '/envases', [PedidosService::class, 'miBilletera'])
        ->add('POST', '/envases/acreditar', [PedidosService::class, 'acreditarEnvases'])
        ->add('GET', '/', [PedidosService::class, 'misPedidos'])
        ->add('POST', '/', [PedidosService::class, 'crearPedido'])
        ->add('GET', '/' . UUID, [PedidosService::class, 'verPedido'])
        ->add('POST', '/' . UUID . '/cancelar', [PedidosService::class, 'cancelarMiPedido'])
        ->add('POST', '/' . UUID . '/calificar', [PedidosService::class, 'calificar'])
        ->add('GET', '/' . UUID . '/mensajes', [PedidosService::class, 'listarMensajes'])
        ->add('POST', '/' . UUID . '/mensajes', [PedidosService::class, 'enviarMensaje'])
        // Admin
        ->add('GET', '/admin/reporte', [PedidosService::class, 'adminReporte'])
        ->add('GET', '/admin/capital', [PedidosService::class, 'adminCapital'])
        ->add('GET', '/admin/pedidos', [PedidosService::class, 'adminListar'])
        ->add('PUT', '/admin/pedidos/' . UUID . '/estado', [PedidosService::class, 'adminCambiarEstado'])
        ->add('PUT', '/admin/pedidos/' . UUID . '/ruta', [PedidosService::class, 'adminActualizarRuta'])
        ->add('GET', '/admin/clientes', [PedidosService::class, 'adminListarClientes'])
        ->add('GET', '/admin/clientes/exportar', [PedidosService::class, 'adminExportarClientes'])
        // Interno (solo pagos-service / notificaciones con JWT de servicio)
        ->add('POST', '/interno/pedidos/consulta', [PedidosService::class, 'internoConsultar'])
        ->add('POST', '/interno/pedidos/marcar-pagado', [PedidosService::class, 'internoMarcarPagado'])
        ->dispatch();
});

