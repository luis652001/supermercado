<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../app/CatalogoService.php';

Http::run(function (): void {
    (new Router())
        ->add('GET', '/health', fn () => Http::json(200, ['status' => 'ok', 'servicio' => 'catalogo-service']))
        // Público: el catálogo se navega sin registro.
        ->add('GET', '/categorias', [CatalogoService::class, 'listarCategorias'])
        ->add('GET', '/productos', [CatalogoService::class, 'listarProductos'])
        ->add('GET', '/productos/(\d+)', [CatalogoService::class, 'verProducto'])
        ->add('GET', '/productores', [CatalogoService::class, 'listarProductores'])
        ->add('GET', '/admin/clasificacion', [CatalogoService::class, 'adminClasificacion'])
        ->add('POST', '/admin/categorias', [CatalogoService::class, 'adminCrearCategoria'])
        ->add('POST', '/admin/marcas', [CatalogoService::class, 'adminCrearMarca'])
        ->add('GET', '/admin/capital', [CatalogoService::class, 'adminCapital'])
        ->add('POST', '/admin/capital/inyectar', [CatalogoService::class, 'adminInyectarCapital'])
        ->add('POST', '/admin/capital/retirar', [CatalogoService::class, 'adminRetirarCapital'])
        ->add('GET', '/admin/lotes', [CatalogoService::class, 'adminListarLotes'])
        ->add('POST', '/admin/lotes', [CatalogoService::class, 'adminCrearLote'])
        ->add('GET', '/admin/lotes/(\d+)/precios', [CatalogoService::class, 'adminHistorialPrecio'])
        ->add('PUT', '/admin/lotes/(\d+)/precio', [CatalogoService::class, 'adminCambiarPrecioLote'])
        ->add('PUT', '/admin/lotes/(\d+)', [CatalogoService::class, 'adminActualizarLote'])
        // Admin (JWT con rol admin).
        ->add('GET', '/admin/productos', [CatalogoService::class, 'adminListar'])
        ->add('POST', '/admin/productos', [CatalogoService::class, 'adminCrear'])
        ->add('PUT', '/admin/productos/(\d+)', [CatalogoService::class, 'adminActualizar'])
        ->add('DELETE', '/admin/productos/(\d+)', [CatalogoService::class, 'adminDesactivar'])
        ->add('POST', '/admin/cloudinary/firma', [CatalogoService::class, 'firmaCloudinary'])
        // Interno: solo pedidos-service con JWT de servicio; el gateway bloquea /interno.
        ->add('POST', '/interno/productos/consulta', [CatalogoService::class, 'internoConsultar'])
        ->add('POST', '/interno/stock/descontar', [CatalogoService::class, 'internoDescontarStock'])
        ->add('POST', '/interno/stock/reponer', [CatalogoService::class, 'internoReponerStock'])
        ->dispatch();
});
