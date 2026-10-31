<?php
declare(strict_types=1);

final class CatalogoService
{
    private const CACHE_TTL = 120;
    private const CLAVE_VERSION = 'catalogo:version';
    private const UNIDADES = ['unidad', 'lb', 'kg', 'litro', 'paquete', 'docena', 'galon'];
    private const MAX_ITEMS_PEDIDO = 50;
    private const MAX_CANTIDAD_ITEM = 50;
    private const COLUMNAS = 'p.id, p.categoria_id, c.nombre AS categoria, c.icono, p.nombre, p.descripcion, p.precio,
                              p.unidad, p.stock, p.imagen_url, p.destacado, p.activo, p.updated_at,
                              p.productor_id, p.retornable, p.deposito_envase, p.precio_compra, p.inyeccion, p.margen_utilidad, p.conservacion';

    // ---------------------------------------------------------------- Público

    public static function listarCategorias(): never
    {
        [$categorias, $desdeCache] = Cache::remember(self::claveCache('categorias'), self::CACHE_TTL, static function (): array {
            return Db::read()->query(
                'SELECT c.id, c.nombre, c.icono, COUNT(p.id) AS productos
                 FROM categorias c
                 LEFT JOIN productos p ON p.categoria_id = c.id AND p.activo = 1
                 WHERE c.activa = 1
                 GROUP BY c.id, c.nombre, c.icono, c.orden
                 ORDER BY c.orden, c.nombre'
            )->fetchAll();
        });

        Http::json(200, ['categorias' => $categorias, 'cache' => $desdeCache ? 'HIT' : 'MISS']);
    }

    public static function listarProductos(): never
    {
        $categoria = self::enteroPositivo($_GET['categoria'] ?? null);
        $busqueda = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($busqueda) > 60) {
            throw new HttpError(422, 'La búsqueda no puede superar 60 caracteres');
        }
        $destacados = ($_GET['destacados'] ?? '') === '1';
        $pagina = min(max((int) ($_GET['pagina'] ?? 1), 1), 100);
        $porPagina = min(max((int) ($_GET['por_pagina'] ?? 24), 1), 48);

        $clave = self::claveCache('productos:' . md5(json_encode([$categoria, mb_strtolower($busqueda), $destacados, $pagina, $porPagina])));
        [$datos, $desdeCache] = Cache::remember($clave, self::CACHE_TTL, static function () use ($categoria, $busqueda, $destacados, $pagina, $porPagina): array {
            $where = ['p.activo = 1', 'c.activa = 1'];
            $params = [];
            if ($categoria !== null) {
                $where[] = 'p.categoria_id = ?';
                $params[] = $categoria;
            }
            if ($busqueda !== '') {
                $where[] = 'p.nombre LIKE ?';
                $params[] = '%' . addcslashes($busqueda, '%_\\') . '%';
            }
            if ($destacados) {
                $where[] = 'p.destacado = 1';
            }
            $filtro = implode(' AND ', $where);
            $db = Db::read();

            $total = $db->prepare("SELECT COUNT(*) FROM productos p JOIN categorias c ON c.id = p.categoria_id WHERE $filtro");
            $total->execute($params);

            $stmt = $db->prepare(
                'SELECT ' . self::COLUMNAS . " FROM productos p JOIN categorias c ON c.id = p.categoria_id
                 WHERE $filtro ORDER BY p.destacado DESC, p.nombre LIMIT ? OFFSET ?"
            );
            $posicion = 1;
            foreach ($params as $valor) {
                $stmt->bindValue($posicion++, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->bindValue($posicion++, $porPagina, PDO::PARAM_INT);
            $stmt->bindValue($posicion, ($pagina - 1) * $porPagina, PDO::PARAM_INT);
            $stmt->execute();

            return [
                'productos' => self::sinCosto(self::publicarLista($stmt->fetchAll(), $db)),
                'total' => (int) $total->fetchColumn(),
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'fuente_bd' => Db::fuenteLectura(),
            ];
        });

        $datos['cache'] = $desdeCache ? 'HIT' : 'MISS';
        Http::json(200, $datos);
    }

    public static function verProducto(string $id): never
    {
        $stmt = Db::read()->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM productos p JOIN categorias c ON c.id = p.categoria_id
             WHERE p.id = ? AND p.activo = 1'
        );
        $stmt->execute([(int) $id]);
        $fila = $stmt->fetch() ?: throw new HttpError(404, 'Producto no encontrado');
        $producto = self::sinCosto(self::publicarLista([$fila], Db::read()))[0];
        Http::json(200, ['producto' => $producto, 'fuente_bd' => Db::fuenteLectura()]);
    }

    public static function listarProductores(): never
    {
        [$productores, $desdeCache] = Cache::remember(self::claveCache('productores'), self::CACHE_TTL, static function (): array {
            return Db::read()->query(
                'SELECT pr.id, pr.nombre, pr.region, pr.descripcion, pr.impacto,
                        (SELECT COUNT(*) FROM productos p WHERE p.productor_id = pr.id AND p.activo = 1) AS productos
                 FROM productores pr WHERE pr.activo = 1 ORDER BY pr.nombre'
            )->fetchAll();
        });
        $lista = array_map(static function (array $fila): array {
            $fila['id'] = (int) $fila['id'];
            $fila['productos'] = (int) $fila['productos'];
            return $fila;
        }, $productores);
        Http::json(200, ['productores' => $lista, 'cache' => $desdeCache ? 'HIT' : 'MISS']);
    }

    // ---------------------------------------------------------------- Admin

    public static function adminListar(): never
    {
        Guard::requirePermiso('catalogo.gestionar');
        $db = Db::get();
        $productos = $db->query(
            'SELECT ' . self::COLUMNAS . ' FROM productos p JOIN categorias c ON c.id = p.categoria_id ORDER BY c.orden, p.nombre'
        )->fetchAll();
        Http::json(200, ['productos' => self::publicarLista($productos, $db)]);
    }

    public static function adminClasificacion(): never
    {
        Guard::requirePermiso('catalogo.gestionar');
        $db = Db::get();
        $categorias = $db->query(
            'SELECT c.id, c.nombre, c.icono, COUNT(p.id) AS productos
             FROM categorias c LEFT JOIN productos p ON p.categoria_id = c.id
             GROUP BY c.id, c.nombre, c.icono, c.orden
             ORDER BY c.orden, c.nombre'
        )->fetchAll();
        $marcas = $db->query(
            'SELECT pr.id, pr.nombre, pr.region, COUNT(p.id) AS productos
             FROM productores pr LEFT JOIN productos p ON p.productor_id = pr.id
             WHERE pr.activo = 1
             GROUP BY pr.id, pr.nombre, pr.region
             ORDER BY pr.nombre'
        )->fetchAll();
        Http::json(200, [
            'categorias' => array_map(static function (array $fila): array {
                return [
                    'id' => (int) $fila['id'],
                    'nombre' => $fila['nombre'],
                    'icono' => $fila['icono'],
                    'productos' => (int) $fila['productos'],
                ];
            }, $categorias),
            'marcas' => array_map(static function (array $fila): array {
                return [
                    'id' => (int) $fila['id'],
                    'nombre' => $fila['nombre'],
                    'region' => $fila['region'],
                    'productos' => (int) $fila['productos'],
                ];
            }, $marcas),
        ]);
    }

    public static function adminCrearCategoria(): never
    {
        Guard::requirePermiso('catalogo.gestionar');
        $cuerpo = ['categoria_nueva' => trim((string) (Http::body()['nombre'] ?? ''))];
        self::aplicarCategoriaNueva($cuerpo);
        if (empty($cuerpo['categoria_id'])) {
            throw new HttpError(422, 'Escribe el nombre de la categoría');
        }
        Cache::invalidar(self::CLAVE_VERSION);
        self::adminClasificacion();
    }

    public static function adminCrearMarca(): never
    {
        Guard::requirePermiso('catalogo.gestionar');
        $entrada = Http::body();
        $cuerpo = [
            'marca_nombre' => trim((string) ($entrada['nombre'] ?? '')),
            'marca_region' => trim((string) ($entrada['region'] ?? '')),
        ];
        self::aplicarMarcaNueva($cuerpo);
        if (empty($cuerpo['productor_id'])) {
            throw new HttpError(422, 'Escribe el nombre de la marca');
        }
        Cache::invalidar(self::CLAVE_VERSION);
        self::adminClasificacion();
    }

    public static function adminCrear(): never
    {
        $admin = Guard::requirePermiso('catalogo.gestionar');
        $cuerpo = Http::body();
        $db = Db::get();
        $db->beginTransaction();
        try {
            self::aplicarCategoriaNueva($cuerpo);
            self::aplicarMarcaNueva($cuerpo);
            $datos = self::validarProducto($cuerpo, false);
            if (empty($datos['categoria_id'])) {
                throw new HttpError(422, 'El producto tiene que quedar amarrado a una categoría');
            }
            if (empty($datos['productor_id'])) {
                throw new HttpError(422, 'El producto tiene que quedar amarrado a una marca');
            }
            $datos['updated_by'] = (int) $admin['sub'];
            $columnas = array_keys($datos);
            $db->prepare(sprintf(
                'INSERT INTO productos (%s) VALUES (%s)',
                implode(', ', $columnas),
                implode(', ', array_fill(0, count($columnas), '?'))
            ))->execute(array_values($datos));
            $id = (int) $db->lastInsertId();
            self::sincronizarPresentaciones($db, $id, $cuerpo, (float) $datos['precio']);
            self::guardarLoteInicial($id, (int) $datos['stock'], $cuerpo, (float) ($datos['precio_compra'] ?? 0));
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $producto = self::buscarEnMaster($id);
        Cache::invalidar(self::CLAVE_VERSION);
        AuditClient::log('PRECIO_MODIFICADO', 'EXITO', Guard::actor($admin) + [
            'dato_anterior' => null,
            'dato_nuevo' => ['producto_id' => $producto['id'], 'nombre' => $producto['nombre'], 'precio' => $producto['precio'], 'motivo' => 'producto_nuevo'],
        ]);

        Http::json(201, ['producto' => $producto]);
    }

    public static function adminActualizar(string $id): never
    {
        $admin = Guard::requirePermiso('catalogo.gestionar');
        $id = (int) $id;
        $cuerpo = Http::body();
        $cambios = self::validarProducto($cuerpo, true);
        if (!$cambios) {
            throw new HttpError(422, 'No se envió ningún campo válido para actualizar');
        }
        $cambioPrecio = false;
        $ventaAnterior = 0.0;
        $compraAnterior = 0.0;
        $ventaNueva = 0.0;
        $compraNueva = 0.0;

        $db = Db::get();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT id, nombre, precio, precio_compra, inyeccion, margen_utilidad, stock FROM productos WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $anterior = $stmt->fetch() ?: throw new HttpError(404, 'Producto no encontrado');
            $ventaAnterior = round((float) $anterior['precio'], 2);
            $compraAnterior = round((float) $anterior['precio_compra'], 2);
            $inyeccionAnterior = round((float) $anterior['inyeccion'], 2);
            $margenAnterior = round((float) $anterior['margen_utilidad'], 2);
            $ventaNueva = array_key_exists('precio', $cambios) ? (float) $cambios['precio'] : $ventaAnterior;
            $compraNueva = array_key_exists('precio_compra', $cambios) ? (float) $cambios['precio_compra'] : $compraAnterior;
            $inyeccionNueva = array_key_exists('inyeccion', $cambios) ? (float) $cambios['inyeccion'] : $inyeccionAnterior;
            $margenNuevo = array_key_exists('margen_utilidad', $cambios) ? (float) $cambios['margen_utilidad'] : $margenAnterior;
            $cambioPrecio = $ventaAnterior !== $ventaNueva || $compraAnterior !== $compraNueva
                || $inyeccionAnterior !== $inyeccionNueva || $margenAnterior !== $margenNuevo;
            if ($cambioPrecio) {
                self::registrarPrecio($db, $id, null, $ventaAnterior, $compraAnterior, $ventaNueva, $compraNueva, $inyeccionAnterior, $margenAnterior, $inyeccionNueva, $margenNuevo);
            }
            self::sincronizarPresentaciones($db, $id, $cuerpo, $ventaNueva);

            $cambios['updated_by'] = (int) $admin['sub'];
            $sets = implode(', ', array_map(static fn (string $col): string => "$col = ?", array_keys($cambios)));
            $db->prepare("UPDATE productos SET $sets WHERE id = ?")->execute([...array_values($cambios), $id]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $producto = self::buscarEnMaster($id);
        Cache::invalidar(self::CLAVE_VERSION);

        if ($cambioPrecio) {
            AuditClient::log('PRECIO_MODIFICADO', 'EXITO', Guard::actor($admin) + [
                'dato_anterior' => [
                    'producto_id' => $id,
                    'nombre' => $anterior['nombre'],
                    'precio' => $ventaAnterior,
                    'precio_compra' => $compraAnterior,
                ],
                'dato_nuevo' => [
                    'producto_id' => $id,
                    'nombre' => $producto['nombre'],
                    'precio' => $ventaNueva,
                    'precio_compra' => $compraNueva,
                ],
            ]);
        }
        if (isset($cambios['stock']) && $producto['stock'] === 0 && (int) $anterior['stock'] > 0) {
            self::auditarAgotado($id, $producto['nombre'], (int) $anterior['stock'], null, Guard::actor($admin));
        }

        Http::json(200, ['producto' => $producto]);
    }

    public static function adminDesactivar(string $id): never
    {
        Guard::requirePermiso('catalogo.gestionar');
        $stmt = Db::get()->prepare('UPDATE productos SET activo = 0 WHERE id = ?');
        $stmt->execute([(int) $id]);
        if ($stmt->rowCount() === 0) {
            throw new HttpError(404, 'Producto no encontrado o ya inactivo');
        }
        Cache::invalidar(self::CLAVE_VERSION);
        Http::json(200, ['mensaje' => 'Producto desactivado (no se elimina para conservar el historial de pedidos)']);
    }

    /** Firma para que el navegador del admin suba la imagen directo a Cloudinary (signed upload). */
    public static function firmaCloudinary(): never
    {
        Guard::requirePermiso('catalogo.gestionar');
        $cloud = Config::get('CLOUDINARY_CLOUD_NAME', '');
        $apiKey = Config::get('CLOUDINARY_API_KEY', '');
        $secreto = Config::get('CLOUDINARY_API_SECRET', '');
        if ($cloud === '' || $apiKey === '' || $secreto === '' || $cloud === 'xxx') {
            throw new HttpError(503, 'Cloudinary no está configurado en el archivo .env');
        }

        $timestamp = time();
        $carpeta = 'superahorro/productos';
        Http::json(200, [
            'cloud_name' => $cloud,
            'api_key' => $apiKey,
            'timestamp' => $timestamp,
            'folder' => $carpeta,
            'signature' => sha1("folder=$carpeta&timestamp=$timestamp" . $secreto),
        ]);
    }

    // ---------------------------------------------------------------- Interno (solo pedidos-service)

    public static function internoConsultar(): never
    {
        Guard::service(['pedidos-service']);
        $ids = array_values(array_unique(array_filter(
            array_map([self::class, 'enteroPositivo'], (array) (Http::body()['ids'] ?? []))
        )));
        if (!$ids || count($ids) > self::MAX_ITEMS_PEDIDO) {
            throw new HttpError(422, 'Lista de productos inválida');
        }

        $stmt = Db::get()->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM productos p JOIN categorias c ON c.id = p.categoria_id
             WHERE p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);
        Http::json(200, ['productos' => self::publicarLista($stmt->fetchAll(), Db::get())]);
    }

    public static function adminCapital(): never
    {
        self::exigirCaja();
        $mes = self::mesConsulta($_GET['mes'] ?? null);
        $db = Db::get();
        $filas = $db->query('SELECT id, nombre, stock, precio, precio_compra, inyeccion, margen_utilidad FROM productos WHERE activo = 1 ORDER BY nombre')->fetchAll();
        $productos = [];
        $capital = 0.0;
        foreach ($filas as $fila) {
            $stock = (int) $fila['stock'];
            $compra = round((float) $fila['precio_compra'], 2);
            $inyeccion = round((float) $fila['inyeccion'], 2);
            $margen = round((float) $fila['margen_utilidad'], 2);
            $venta = round((float) $fila['precio'], 2);
            $invertido = round($stock * $compra, 2);
            $capital += $invertido;
            $productos[] = [
                'id' => (int) $fila['id'],
                'nombre' => $fila['nombre'],
                'stock' => $stock,
                'precio_compra' => $compra,
                'inyeccion' => $inyeccion,
                'precio_venta' => $venta,
                'capital' => $invertido,
                'margen' => $margen,
            ];
        }
        $stmt = $db->prepare(
            'SELECT tipo, monto, nota, created_at FROM caja_movimientos WHERE mes = ? ORDER BY id DESC'
        );
        $stmt->execute([$mes]);
        $movimientos = [];
        $inyectado = 0.0;
        $compras = 0.0;
        $retiros = 0.0;
        foreach ($stmt->fetchAll() as $mov) {
            $monto = round((float) $mov['monto'], 2);
            if ($mov['tipo'] === 'inyeccion') {
                $inyectado += $monto;
            } elseif ($mov['tipo'] === 'retiro') {
                $retiros += $monto;
            } else {
                $compras += $monto;
            }
            $movimientos[] = [
                'tipo' => $mov['tipo'],
                'monto' => $monto,
                'nota' => $mov['nota'],
                'created_at' => $mov['created_at'],
            ];
        }
        Http::json(200, [
            'mes' => $mes,
            'capital_productos' => round($capital, 2),
            'productos' => $productos,
            'inyectado' => round($inyectado, 2),
            'compras' => round($compras, 2),
            'retiros' => round($retiros, 2),
            'movimientos' => $movimientos,
        ]);
    }

    public static function adminInyectarCapital(): never
    {
        $actor = self::exigirCaja();
        $cuerpo = Http::body();
        $monto = filter_var($cuerpo['monto'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($monto === false || $monto <= 0 || $monto > 999999.99) {
            throw new HttpError(422, 'El monto a inyectar debe ser mayor a 0');
        }
        $nota = trim(strip_tags((string) ($cuerpo['nota'] ?? '')));
        if (mb_strlen($nota) > 200) {
            throw new HttpError(422, 'La nota no puede superar 200 caracteres');
        }
        $mes = self::mesConsulta($cuerpo['mes'] ?? null);
        $monto = round((float) $monto, 2);
        self::registrarCaja(Db::get(), $mes, 'inyeccion', $monto, $nota !== '' ? $nota : 'Inyección de efectivo', null);
        AuditClient::log('CAPITAL_INYECTADO', 'EXITO', Guard::actor($actor) + [
            'dato_nuevo' => ['mes' => $mes, 'monto' => $monto, 'nota' => $nota],
        ]);
        Http::json(200, ['mensaje' => 'Efectivo inyectado', 'mes' => $mes, 'monto' => $monto]);
    }

    public static function adminRetirarCapital(): never
    {
        $actor = self::exigirCaja();
        $cuerpo = Http::body();
        $monto = filter_var($cuerpo['monto'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($monto === false || $monto <= 0 || $monto > 999999.99) {
            throw new HttpError(422, 'El monto a retirar debe ser mayor a 0');
        }
        $nota = trim(strip_tags((string) ($cuerpo['nota'] ?? '')));
        if (mb_strlen($nota) > 200) {
            throw new HttpError(422, 'La nota no puede superar 200 caracteres');
        }
        $mes = self::mesConsulta($cuerpo['mes'] ?? null);
        $monto = round((float) $monto, 2);
        $cobrado = self::cobradoDelMes($mes);

        $db = Db::get();
        $db->beginTransaction();
        try {
            $saldos = self::saldosCaja($db, $mes, true);
            $disponible = round($saldos['inyectado'] + $cobrado - $saldos['compras'] - $saldos['retiros'], 2);
            if ($monto > $disponible) {
                throw new HttpError(422, 'No hay suficiente efectivo en la caja de este mes. Disponible: Q' . number_format($disponible, 2, '.', ''));
            }
            self::registrarCaja($db, $mes, 'retiro', $monto, $nota !== '' ? $nota : 'Retiro de efectivo', null);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        AuditClient::log('CAPITAL_RETIRADO', 'EXITO', Guard::actor($actor) + [
            'dato_nuevo' => ['mes' => $mes, 'monto' => $monto, 'nota' => $nota],
        ]);
        Http::json(200, ['mensaje' => 'Efectivo retirado', 'mes' => $mes, 'monto' => $monto]);
    }

    public static function adminListarLotes(): never
    {
        Guard::requirePermiso('catalogo.gestionar');
        $filas = Db::get()->query(
            'SELECT l.id, l.codigo, l.cantidad, l.fecha_ingreso, l.fecha_vencimiento, l.temperatura, l.estado, l.nota,
                    p.id AS producto_id, p.nombre AS producto, p.conservacion, p.precio AS precio_venta, p.precio_compra,
                    h.venta_anterior, h.compra_anterior, h.created_at AS precio_en
             FROM lotes l
             JOIN productos p ON p.id = l.producto_id
             LEFT JOIN historial_precios h ON h.producto_id = p.id AND h.id = (
                 SELECT MAX(hp.id) FROM historial_precios hp WHERE hp.producto_id = p.id
             )
             ORDER BY l.fecha_vencimiento, l.id'
        )->fetchAll();
        Http::json(200, ['lotes' => array_map([self::class, 'normalizarLote'], $filas)]);
    }

    public static function adminCrearLote(): never
    {
        $admin = Guard::requirePermiso('catalogo.gestionar');
        $cuerpo = Http::body();
        $productoId = self::enteroPositivo($cuerpo['producto_id'] ?? null);
        $codigo = strtoupper(trim((string) ($cuerpo['codigo'] ?? '')));
        $cantidad = filter_var($cuerpo['cantidad'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        $fecha = self::fecha($cuerpo['fecha_vencimiento'] ?? '');
        $temperatura = self::temperatura($cuerpo['temperatura'] ?? null);
        $nota = trim(strip_tags((string) ($cuerpo['nota'] ?? '')));
        if (!$productoId || !preg_match('/^[A-Z0-9-]{4,40}$/', $codigo) || $cantidad === false || !$fecha) {
            throw new HttpError(422, 'Lote inválido: producto, código (A-Z, 0-9, guion), cantidad y fecha YYYY-MM-DD');
        }
        if (mb_strlen($nota) > 200) {
            throw new HttpError(422, 'La nota del lote no puede superar 200 caracteres');
        }
        [$venta, $compra, $inyeccion, $margen] = self::leerDesglose($cuerpo);

        $db = Db::get();
        $db->beginTransaction();
        $cambioPrecio = false;
        $ventaAnterior = 0.0;
        $compraAnterior = 0.0;
        $inyeccionAnterior = 0.0;
        $margenAnterior = 0.0;
        try {
            $stmt = $db->prepare('SELECT precio, precio_compra, inyeccion, margen_utilidad FROM productos WHERE id = ? FOR UPDATE');
            $stmt->execute([$productoId]);
            $producto = $stmt->fetch() ?: throw new HttpError(404, 'Producto no encontrado');
            $ventaAnterior = round((float) $producto['precio'], 2);
            $compraAnterior = round((float) $producto['precio_compra'], 2);
            $inyeccionAnterior = round((float) $producto['inyeccion'], 2);
            $margenAnterior = round((float) $producto['margen_utilidad'], 2);
            $ingreso = self::fecha($cuerpo['fecha_ingreso'] ?? '') ?? date('Y-m-d');
            if ($fecha < $ingreso) {
                throw new HttpError(422, 'La fecha de vencimiento no puede ser anterior al ingreso');
            }
            $db->prepare(
                'INSERT INTO lotes (producto_id, codigo, cantidad, fecha_ingreso, fecha_vencimiento, temperatura, estado, nota)
                 VALUES (?, ?, ?, ?, ?, ?, \'en_camara\', ?)'
            )->execute([$productoId, $codigo, $cantidad, $ingreso, $fecha, $temperatura, $nota !== '' ? $nota : null]);
            $loteId = (int) $db->lastInsertId();
            $cambioPrecio = $ventaAnterior !== $venta || $compraAnterior !== $compra
                || $inyeccionAnterior !== $inyeccion || $margenAnterior !== $margen;
            if ($cambioPrecio) {
                self::registrarPrecio($db, $productoId, $loteId, $ventaAnterior, $compraAnterior, $venta, $compra, $inyeccionAnterior, $margenAnterior, $inyeccion, $margen);
                $db->prepare('UPDATE productos SET precio = ?, precio_compra = ?, inyeccion = ?, margen_utilidad = ?, updated_by = ? WHERE id = ?')
                    ->execute([$venta, $compra, $inyeccion, $margen, (int) $admin['sub'], $productoId]);
            }
            $db->prepare('UPDATE productos SET stock = stock + ? WHERE id = ?')->execute([$cantidad, $productoId]);
            self::registrarCaja($db, substr($ingreso, 0, 7), 'compra', round($cantidad * $compra, 2), 'Lote ' . $codigo, $loteId);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpError(409, 'Ya existe un lote con ese código');
            }
            throw $e;
        }
        Cache::invalidar(self::CLAVE_VERSION);
        if ($cambioPrecio) {
            AuditClient::log('PRECIO_MODIFICADO', 'EXITO', Guard::actor($admin) + [
                'dato_anterior' => [
                    'producto_id' => $productoId,
                    'precio' => $ventaAnterior,
                    'precio_compra' => $compraAnterior,
                ],
                'dato_nuevo' => [
                    'codigo' => $codigo,
                    'producto_id' => $productoId,
                    'precio' => $venta,
                    'precio_compra' => $compra,
                ],
            ]);
        }
        AuditClient::log('LOTE_MERMA', 'EXITO', Guard::actor($admin) + [
            'dato_anterior' => null,
            'dato_nuevo' => ['codigo' => $codigo, 'producto_id' => $productoId, 'cantidad' => $cantidad, 'vence' => $fecha, 'motivo' => 'lote_recibido'],
        ]);
        self::adminListarLotes();
    }

    public static function adminHistorialPrecio(string $id): never
    {
        Guard::requirePermiso('catalogo.gestionar');
        $lote = self::loteConPrecio((int) $id);
        $stmt = Db::get()->prepare(
            'SELECT venta_anterior, compra_anterior, venta_nueva, compra_nueva, created_at
             FROM historial_precios WHERE producto_id = ? ORDER BY id DESC LIMIT 12'
        );
        $stmt->execute([$lote['producto_id']]);
        Http::json(200, [
            'lote' => $lote,
            'historial' => array_map([self::class, 'normalizarHistorial'], $stmt->fetchAll()),
        ]);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} venta, costo, inyección, margen */
    private static function leerDesglose(array $cuerpo): array
    {
        $compra = self::montoPrecio($cuerpo['precio_compra'] ?? null, 'El costo', true);
        $inyeccion = self::montoPrecio($cuerpo['inyeccion'] ?? 0, 'La inyección', true);
        $margen = self::montoPrecio($cuerpo['margen_utilidad'] ?? 0, 'El margen', true);
        $venta = round($compra + $inyeccion + $margen, 2);
        if ($venta <= 0 || $venta > 99999.99) {
            throw new HttpError(422, 'La venta es costo + inyección + margen y tiene que ser mayor a 0');
        }
        return [$venta, $compra, $inyeccion, $margen];
    }

    private static function montoPrecio(mixed $valor, string $nombre, bool $permiteCero): float
    {
        if ($valor === '' || $valor === null) {
            $valor = 0;
        }
        $monto = filter_var($valor, FILTER_VALIDATE_FLOAT);
        if ($monto === false || $monto > 99999.99 || ($permiteCero ? $monto < 0 : $monto <= 0)) {
            throw new HttpError(422, $permiteCero ? "$nombre no puede ser negativo" : "$nombre debe ser mayor a 0");
        }
        return round((float) $monto, 2);
    }

    public static function adminCambiarPrecioLote(string $id): never
    {
        $admin = Guard::requirePermiso('catalogo.gestionar');
        [$venta, $compra, $inyeccion, $margen] = self::leerDesglose(Http::body());

        $db = Db::get();
        $db->beginTransaction();
        try {
            $lote = self::loteConPrecio((int) $id, true);
            $ventaAnterior = $lote['precio_venta'];
            $compraAnterior = $lote['precio_compra'];
            $inyeccionAnterior = $lote['inyeccion'];
            $margenAnterior = $lote['margen_utilidad'];
            if ($ventaAnterior === $venta && $compraAnterior === $compra && $inyeccionAnterior === $inyeccion && $margenAnterior === $margen) {
                throw new HttpError(422, 'Esos ya son los precios actuales');
            }
            self::registrarPrecio($db, $lote['producto_id'], $lote['id'], $ventaAnterior, $compraAnterior, $venta, $compra, $inyeccionAnterior, $margenAnterior, $inyeccion, $margen);
            $db->prepare('UPDATE productos SET precio = ?, precio_compra = ?, inyeccion = ?, margen_utilidad = ?, updated_by = ? WHERE id = ?')
                ->execute([$venta, $compra, $inyeccion, $margen, (int) $admin['sub'], $lote['producto_id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        Cache::invalidar(self::CLAVE_VERSION);
        AuditClient::log('PRECIO_MODIFICADO', 'EXITO', Guard::actor($admin) + [
            'dato_anterior' => [
                'lote_id' => (int) $id,
                'producto_id' => $lote['producto_id'],
                'precio' => $ventaAnterior,
                'precio_compra' => $compraAnterior,
            ],
            'dato_nuevo' => [
                'lote_id' => (int) $id,
                'codigo' => $lote['codigo'],
                'precio' => $venta,
                'precio_compra' => $compra,
            ],
        ]);
        self::adminListarLotes();
    }

    public static function adminActualizarLote(string $id): never
    {
        $admin = Guard::requirePermiso('catalogo.gestionar');
        $estado = (string) (Http::body()['estado'] ?? '');
        if (!in_array($estado, ['recibido', 'en_camara', 'listo', 'merma'], true)) {
            throw new HttpError(422, 'Estado de lote inválido');
        }

        $db = Db::get();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT id, producto_id, codigo, cantidad, estado FROM lotes WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $id]);
            $lote = $stmt->fetch() ?: throw new HttpError(404, 'Lote no encontrado');
            $db->prepare('UPDATE lotes SET estado = ? WHERE id = ?')->execute([$estado, $lote['id']]);

            $stockNuevo = null;
            if ($estado === 'merma' && $lote['estado'] !== 'merma') {
                $db->prepare('UPDATE productos SET stock = GREATEST(stock - ?, 0) WHERE id = ?')
                    ->execute([(int) $lote['cantidad'], (int) $lote['producto_id']]);
                $stock = $db->prepare('SELECT nombre, stock FROM productos WHERE id = ?');
                $stock->execute([(int) $lote['producto_id']]);
                $producto = $stock->fetch();
                $stockNuevo = $producto ? (int) $producto['stock'] : null;
                if ($stockNuevo === 0) {
                    self::auditarAgotado((int) $lote['producto_id'], (string) $producto['nombre'], (int) $lote['cantidad'], null, Guard::actor($admin));
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        Cache::invalidar(self::CLAVE_VERSION);
        AuditClient::log('LOTE_MERMA', $estado === 'merma' ? 'EXITO' : 'EXITO', Guard::actor($admin) + [
            'dato_anterior' => ['codigo' => $lote['codigo'], 'estado' => $lote['estado'], 'cantidad' => (int) $lote['cantidad']],
            'dato_nuevo' => ['codigo' => $lote['codigo'], 'estado' => $estado, 'stock' => $stockNuevo],
        ]);
        self::adminListarLotes();
    }

    public static function internoDescontarStock(): never
    {
        Guard::service(['pedidos-service']);
        $cuerpo = Http::body();
        $pedidoId = self::enteroPositivo($cuerpo['pedido_id'] ?? null) ?? throw new HttpError(422, 'pedido_id inválido');
        $items = self::validarItems($cuerpo['items'] ?? null);

        $db = Db::get();
        $db->beginTransaction();
        try {
            $ids = array_keys($items);
            $stmt = $db->prepare(
                'SELECT id, nombre, stock, activo FROM productos WHERE id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE'
            );
            $stmt->execute($ids);
            $productos = array_column($stmt->fetchAll(), null, 'id');

            $faltantes = [];
            foreach ($items as $productoId => $cantidad) {
                $producto = $productos[$productoId] ?? null;
                if (!$producto || !(int) $producto['activo'] || (int) $producto['stock'] < $cantidad) {
                    $faltantes[] = [
                        'producto_id' => $productoId,
                        'nombre' => $producto['nombre'] ?? null,
                        'disponible' => $producto && (int) $producto['activo'] ? (int) $producto['stock'] : 0,
                        'solicitado' => $cantidad,
                    ];
                }
            }
            if ($faltantes) {
                $db->rollBack();
                Http::json(409, ['error' => 'Stock insuficiente', 'faltantes' => $faltantes]);
            }

            $descontar = $db->prepare('UPDATE productos SET stock = stock - ? WHERE id = ?');
            $agotados = [];
            foreach ($items as $productoId => $cantidad) {
                $descontar->execute([$cantidad, $productoId]);
                if ((int) $productos[$productoId]['stock'] === $cantidad) {
                    $agotados[] = $productos[$productoId];
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        Cache::invalidar(self::CLAVE_VERSION);
        foreach ($agotados as $producto) {
            self::auditarAgotado((int) $producto['id'], $producto['nombre'], (int) $producto['stock'], $pedidoId, ['rol' => 'anonimo']);
        }
        Http::json(200, ['ok' => true, 'agotados' => array_map(static fn (array $p): int => (int) $p['id'], $agotados)]);
    }

    public static function internoReponerStock(): never
    {
        Guard::service(['pedidos-service']);
        $items = self::validarItems(Http::body()['items'] ?? null);

        $db = Db::get();
        $db->beginTransaction();
        $reponer = $db->prepare('UPDATE productos SET stock = stock + ? WHERE id = ?');
        foreach ($items as $productoId => $cantidad) {
            $reponer->execute([$cantidad, $productoId]);
        }
        $db->commit();

        Cache::invalidar(self::CLAVE_VERSION);
        Http::json(200, ['ok' => true]);
    }

    /** @param array<string, mixed> $cuerpo */
    private static function aplicarCategoriaNueva(array &$cuerpo): void
    {
        $nombre = trim((string) ($cuerpo['categoria_nueva'] ?? ''));
        if ($nombre === '') {
            return;
        }
        if (!preg_match('/^[\p{L}\p{N} ]{2,60}$/u', $nombre)) {
            throw new HttpError(422, 'La categoría nueva debe tener de 2 a 60 letras o números');
        }
        $db = Db::get();
        $ya = $db->prepare('SELECT id FROM categorias WHERE nombre = ?');
        $ya->execute([$nombre]);
        $id = $ya->fetchColumn();
        if (!$id) {
            $orden = (int) $db->query('SELECT COALESCE(MAX(orden), 0) + 1 FROM categorias')->fetchColumn();
            $db->prepare('INSERT INTO categorias (nombre, icono, orden) VALUES (?, ?, ?)')->execute([$nombre, '🛒', $orden]);
            $id = (int) $db->lastInsertId();
        }
        $cuerpo['categoria_id'] = (int) $id;
    }

    /** @param array<string, mixed> $cuerpo */
    private static function aplicarMarcaNueva(array &$cuerpo): void
    {
        $nombre = trim((string) ($cuerpo['marca_nombre'] ?? ''));
        if ($nombre === '') {
            return;
        }
        if (!preg_match('/^[\p{L}\p{N} .\'-]{2,120}$/u', $nombre)) {
            throw new HttpError(422, 'El nombre de la marca no es válido');
        }
        $region = trim((string) ($cuerpo['marca_region'] ?? ''));
        if ($region === '') {
            $region = 'Guatemala';
        }
        if (!preg_match('/^[\p{L}\p{N} .\'-]{2,80}$/u', $region)) {
            throw new HttpError(422, 'La región de la marca no es válida');
        }
        $db = Db::get();
        $ya = $db->prepare('SELECT id FROM productores WHERE nombre = ? AND activo = 1');
        $ya->execute([$nombre]);
        $id = $ya->fetchColumn();
        if (!$id) {
            $db->prepare(
                'INSERT INTO productores (nombre, region, descripcion, impacto) VALUES (?, ?, ?, ?)'
            )->execute([
                $nombre,
                $region,
                'Marca registrada desde el catálogo.',
                'Queda asociada a los productos que se vendan con este origen.',
            ]);
            $id = (int) $db->lastInsertId();
        }
        $cuerpo['productor_id'] = (int) $id;
    }

    /** @param array<string, mixed> $cuerpo */
    private static function sincronizarPresentaciones(\PDO $db, int $productoId, array $cuerpo, float $ventaUnitaria): void
    {
        if (!array_key_exists('presentaciones', $cuerpo)) {
            self::guardarPresentacionInicial($productoId, $cuerpo);
            return;
        }
        if (!is_array($cuerpo['presentaciones'])) {
            throw new HttpError(422, 'Las presentaciones no tienen un formato válido');
        }
        if (count($cuerpo['presentaciones']) > 8) {
            throw new HttpError(422, 'Un producto puede tener hasta 8 presentaciones');
        }
        $ids = [];
        $vistos = [];
        foreach ($cuerpo['presentaciones'] as $item) {
            if (!is_array($item)) {
                throw new HttpError(422, 'Hay una presentación inválida');
            }
            $nombre = trim((string) ($item['nombre'] ?? ''));
            if ($nombre === '') {
                continue;
            }
            if (!preg_match('/^[\p{L}\p{N} .\'-]{2,80}$/u', $nombre)) {
                throw new HttpError(422, 'El nombre de la presentación no es válido');
            }
            $clave = mb_strtolower($nombre);
            if (isset($vistos[$clave])) {
                throw new HttpError(422, 'No repitas el nombre de una presentación');
            }
            $vistos[$clave] = true;
            $factor = filter_var($item['factor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
            if ($factor === false) {
                throw new HttpError(422, 'Cada presentación indica cuántas unidades base incluye');
            }
            $precio = round($factor * $ventaUnitaria, 2);
            if ($precio <= 0 || $precio > 99999.99) {
                throw new HttpError(422, 'Primero arma la venta base. La presentación la multiplica por lo que incluye');
            }
            $id = self::enteroPositivo($item['id'] ?? null);
            if ($id) {
                $propia = $db->prepare('SELECT id FROM presentaciones WHERE id = ? AND producto_id = ?');
                $propia->execute([$id, $productoId]);
                if (!$propia->fetch()) {
                    $id = null;
                }
            }
            if ($id) {
                $db->prepare('UPDATE presentaciones SET nombre = ?, factor = ?, precio = ?, activa = 1 WHERE id = ? AND producto_id = ?')
                    ->execute([$nombre, $factor, $precio, $id, $productoId]);
                $ids[] = $id;
                continue;
            }
            $ya = $db->prepare('SELECT id FROM presentaciones WHERE producto_id = ? AND nombre = ?');
            $ya->execute([$productoId, $nombre]);
            $existente = $ya->fetchColumn();
            if ($existente) {
                $db->prepare('UPDATE presentaciones SET factor = ?, precio = ?, activa = 1 WHERE id = ?')
                    ->execute([$factor, $precio, (int) $existente]);
                $ids[] = (int) $existente;
                continue;
            }
            $db->prepare('INSERT INTO presentaciones (producto_id, nombre, factor, precio) VALUES (?, ?, ?, ?)')
                ->execute([$productoId, $nombre, $factor, $precio]);
            $ids[] = (int) $db->lastInsertId();
        }
        if ($ids) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("UPDATE presentaciones SET activa = 0 WHERE producto_id = ? AND id NOT IN ($marcas)")
                ->execute([$productoId, ...$ids]);
        } else {
            $db->prepare('UPDATE presentaciones SET activa = 0 WHERE producto_id = ?')->execute([$productoId]);
        }
    }

    /** @param array<string, mixed> $cuerpo */
    private static function guardarPresentacionInicial(int $productoId, array $cuerpo): void
    {
        $nombre = trim((string) ($cuerpo['presentacion_nombre'] ?? ''));
        if ($nombre === '') {
            return;
        }
        if (!preg_match('/^[\p{L}\p{N} .\'-]{2,80}$/u', $nombre)) {
            throw new HttpError(422, 'El nombre de la presentación no es válido');
        }
        $factor = filter_var($cuerpo['presentacion_factor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        $precio = filter_var($cuerpo['presentacion_precio'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($factor === false || $precio === false || $precio <= 0 || $precio > 99999.99) {
            throw new HttpError(422, 'La presentación necesita cuántas unidades base incluye y su precio de venta');
        }
        Db::get()->prepare(
            'INSERT INTO presentaciones (producto_id, nombre, factor, precio) VALUES (?, ?, ?, ?)'
        )->execute([$productoId, $nombre, $factor, round($precio, 2)]);
    }

    /** @param array<string, mixed> $cuerpo */
    private static function guardarLoteInicial(int $productoId, int $stock, array $cuerpo, float $precioCompra): void
    {
        $codigo = strtoupper(trim((string) ($cuerpo['lote_codigo'] ?? '')));
        $venceRaw = (string) ($cuerpo['lote_vence'] ?? '');
        if ($codigo === '' && $venceRaw === '') {
            return;
        }
        $vence = self::fecha($venceRaw);
        $ingreso = self::fecha($cuerpo['lote_ingreso'] ?? '') ?? date('Y-m-d');
        if (!preg_match('/^[A-Z0-9-]{4,40}$/', $codigo) || !$vence) {
            throw new HttpError(422, 'El lote necesita código (letras, números o guion) y fecha de vencimiento');
        }
        if ($vence < $ingreso) {
            throw new HttpError(422, 'La fecha de vencimiento no puede ser anterior al ingreso');
        }
        if ($stock < 1) {
            throw new HttpError(422, 'Para registrar un lote el stock tiene que ser al menos 1');
        }
        $db = Db::get();
        try {
            $db->prepare(
                'INSERT INTO lotes (producto_id, codigo, cantidad, fecha_ingreso, fecha_vencimiento, estado)
                 VALUES (?, ?, ?, ?, ?, \'en_camara\')'
            )->execute([$productoId, $codigo, $stock, $ingreso, $vence]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpError(409, 'Ya existe un lote con ese código');
            }
            throw $e;
        }
        self::registrarCaja($db, substr($ingreso, 0, 7), 'compra', round($stock * $precioCompra, 2), 'Lote inicial ' . $codigo, (int) $db->lastInsertId());
    }

    // ---------------------------------------------------------------- Validación

    private static function validarProducto(array $cuerpo, bool $parcial): array
    {
        $datos = [];

        if (array_key_exists('nombre', $cuerpo)) {
            $nombre = trim((string) $cuerpo['nombre']);
            if (!preg_match('/^[\p{L}\p{N} .,\'()%\/&+-]{2,120}$/u', $nombre)) {
                throw new HttpError(422, 'Nombre inválido (2 a 120 caracteres, sin símbolos especiales)');
            }
            $datos['nombre'] = $nombre;
        }
        if (array_key_exists('descripcion', $cuerpo)) {
            $descripcion = trim(strip_tags((string) $cuerpo['descripcion']));
            if (mb_strlen($descripcion) > 500) {
                throw new HttpError(422, 'La descripción no puede superar 500 caracteres');
            }
            $datos['descripcion'] = $descripcion === '' ? null : $descripcion;
        }
        if (array_key_exists('precio', $cuerpo) && !array_key_exists('inyeccion', $cuerpo) && !array_key_exists('margen_utilidad', $cuerpo)) {
            $precio = filter_var($cuerpo['precio'], FILTER_VALIDATE_FLOAT);
            if ($precio === false || $precio <= 0 || $precio > 99999.99) {
                throw new HttpError(422, 'Precio inválido (mayor a 0 y menor a 100,000)');
            }
            $datos['precio'] = round($precio, 2);
        }
        if (array_key_exists('precio_compra', $cuerpo) && $cuerpo['precio_compra'] !== '' && $cuerpo['precio_compra'] !== null) {
            $datos['precio_compra'] = self::montoPrecio($cuerpo['precio_compra'], 'El costo', true);
        }
        if (array_key_exists('inyeccion', $cuerpo) || array_key_exists('margen_utilidad', $cuerpo)) {
            if (!array_key_exists('precio_compra', $datos)) {
                throw new HttpError(422, 'El costo tiene que venir junto con la inyección y el margen');
            }
            $inyeccion = self::montoPrecio($cuerpo['inyeccion'] ?? 0, 'La inyección', true);
            $margen = self::montoPrecio($cuerpo['margen_utilidad'] ?? 0, 'El margen', true);
            $venta = round($datos['precio_compra'] + $inyeccion + $margen, 2);
            if ($venta <= 0 || $venta > 99999.99) {
                throw new HttpError(422, 'La venta es costo + inyección + margen y tiene que ser mayor a 0');
            }
            $datos['inyeccion'] = $inyeccion;
            $datos['margen_utilidad'] = $margen;
            $datos['precio'] = $venta;
        }
        if (array_key_exists('productor_id', $cuerpo) && $cuerpo['productor_id'] !== '' && $cuerpo['productor_id'] !== null) {
            $productor = self::enteroPositivo($cuerpo['productor_id']);
            $existe = Db::get()->prepare('SELECT id FROM productores WHERE id = ? AND activo = 1');
            $existe->execute([$productor]);
            if (!$productor || !$existe->fetch()) {
                throw new HttpError(422, 'Marca o productor inexistente');
            }
            $datos['productor_id'] = $productor;
        }
        if (array_key_exists('stock', $cuerpo)) {
            $stock = filter_var($cuerpo['stock'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
            if ($stock === false) {
                throw new HttpError(422, 'Stock inválido (entero entre 0 y 100,000)');
            }
            $datos['stock'] = $stock;
        }
        if (array_key_exists('categoria_id', $cuerpo)) {
            $categoria = self::enteroPositivo($cuerpo['categoria_id']);
            $existe = Db::get()->prepare('SELECT id FROM categorias WHERE id = ?');
            $existe->execute([$categoria]);
            if (!$categoria || !$existe->fetch()) {
                throw new HttpError(422, 'Categoría inexistente');
            }
            $datos['categoria_id'] = $categoria;
        }
        if (array_key_exists('unidad', $cuerpo)) {
            if (!in_array($cuerpo['unidad'], self::UNIDADES, true)) {
                throw new HttpError(422, 'Unidad inválida: ' . implode(', ', self::UNIDADES));
            }
            $datos['unidad'] = $cuerpo['unidad'];
        }
        if (array_key_exists('conservacion', $cuerpo) && $cuerpo['conservacion'] !== '') {
            if (!in_array($cuerpo['conservacion'], ['refrigerado', 'ambiente'], true)) {
                throw new HttpError(422, 'La conservación debe ser refrigerado o ambiente');
            }
            $datos['conservacion'] = $cuerpo['conservacion'];
        }
        if (array_key_exists('imagen_url', $cuerpo)) {
            $url = $cuerpo['imagen_url'];
            if ($url !== null && $url !== '' && !preg_match('#^https://res\.cloudinary\.com/[a-z0-9_-]+/image/upload/[A-Za-z0-9_/.,-]{1,200}$#', (string) $url)) {
                throw new HttpError(422, 'La imagen debe ser una URL de Cloudinary (https://res.cloudinary.com/...)');
            }
            $datos['imagen_url'] = $url ?: null;
        }
        foreach (['destacado', 'activo'] as $bandera) {
            if (array_key_exists($bandera, $cuerpo)) {
                $datos[$bandera] = filter_var($cuerpo[$bandera], FILTER_VALIDATE_BOOL) ? 1 : 0;
            }
        }

        if (!$parcial) {
            foreach (['nombre', 'precio', 'stock', 'categoria_id', 'unidad'] as $requerido) {
                if (!array_key_exists($requerido, $datos)) {
                    throw new HttpError(422, "El campo $requerido es obligatorio");
                }
            }
        }
        return $datos;
    }

    /** @return array<int, int> producto_id => cantidad */
    private static function validarItems(mixed $items): array
    {
        if (!is_array($items) || !$items || count($items) > self::MAX_ITEMS_PEDIDO) {
            throw new HttpError(422, 'Lista de items inválida');
        }
        $resultado = [];
        foreach ($items as $item) {
            $productoId = self::enteroPositivo($item['producto_id'] ?? null);
            $cantidad = filter_var($item['cantidad'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_CANTIDAD_ITEM]]);
            if ($productoId === null || $cantidad === false) {
                throw new HttpError(422, 'Item inválido');
            }
            $resultado[$productoId] = ($resultado[$productoId] ?? 0) + $cantidad;
        }
        return $resultado;
    }

    // ---------------------------------------------------------------- Utilidades

    private static function auditarAgotado(int $id, string $nombre, int $stockAnterior, ?int $pedidoId, array $actor): void
    {
        AuditClient::log('PRODUCTO_AGOTADO', 'EXITO', $actor + [
            'pedido_id' => $pedidoId,
            'dato_anterior' => ['producto_id' => $id, 'nombre' => $nombre, 'stock' => $stockAnterior],
            'dato_nuevo' => ['producto_id' => $id, 'nombre' => $nombre, 'stock' => 0],
        ]);
    }

    private static function buscarEnMaster(int $id): array
    {
        $stmt = Db::get()->prepare('SELECT ' . self::COLUMNAS . ' FROM productos p JOIN categorias c ON c.id = p.categoria_id WHERE p.id = ?');
        $stmt->execute([$id]);
        return self::normalizar($stmt->fetch() ?: throw new HttpError(404, 'Producto no encontrado'));
    }

    private static function normalizar(array $fila): array
    {
        $fila['id'] = (int) $fila['id'];
        $fila['categoria_id'] = (int) $fila['categoria_id'];
        $fila['precio'] = round((float) $fila['precio'], 2);
        $fila['stock'] = (int) $fila['stock'];
        $fila['destacado'] = (bool) $fila['destacado'];
        $fila['activo'] = (bool) $fila['activo'];
        $fila['productor_id'] = isset($fila['productor_id']) && $fila['productor_id'] !== null ? (int) $fila['productor_id'] : null;
        $fila['retornable'] = (bool) ($fila['retornable'] ?? false);
        $fila['deposito_envase'] = round((float) ($fila['deposito_envase'] ?? 0), 2);
        $fila['precio_compra'] = round((float) ($fila['precio_compra'] ?? 0), 2);
        $fila['inyeccion'] = round((float) ($fila['inyeccion'] ?? 0), 2);
        $fila['margen_utilidad'] = round((float) ($fila['margen_utilidad'] ?? 0), 2);
        $fila['conservacion'] = ($fila['conservacion'] ?? 'ambiente') === 'refrigerado' ? 'refrigerado' : 'ambiente';
        return $fila;
    }

    /** @param list<array<string, mixed>> $productos */
    private static function sinCosto(array $productos): array
    {
        foreach ($productos as &$producto) {
            unset($producto['precio_compra'], $producto['inyeccion'], $producto['margen_utilidad']);
        }
        unset($producto);
        return $productos;
    }

    /** @param list<array<string, mixed>> $filas */
    private static function publicarLista(array $filas, PDO $db): array
    {
        return self::adjuntarInnovacion(array_map([self::class, 'normalizar'], $filas), $db);
    }

    /** @param list<array<string, mixed>> $productos */
    private static function adjuntarInnovacion(array $productos, PDO $db): array
    {
        if (!$productos) {
            return [];
        }
        $ids = array_map(static fn (array $p): int => $p['id'], $productos);
        $marcas = implode(',', array_fill(0, count($ids), '?'));

        $presentaciones = $db->prepare(
            "SELECT id, producto_id, nombre, factor, precio FROM presentaciones
             WHERE activa = 1 AND producto_id IN ($marcas) ORDER BY factor"
        );
        $presentaciones->execute($ids);
        $porProducto = [];
        foreach ($presentaciones->fetchAll() as $fila) {
            $porProducto[(int) $fila['producto_id']][] = [
                'id' => (int) $fila['id'],
                'nombre' => $fila['nombre'],
                'factor' => (int) $fila['factor'],
                'precio' => round((float) $fila['precio'], 2),
            ];
        }

        $productorIds = array_values(array_unique(array_filter(array_map(
            static fn (array $p): ?int => $p['productor_id'],
            $productos
        ))));
        $productores = [];
        if ($productorIds) {
            $marcasPr = implode(',', array_fill(0, count($productorIds), '?'));
            $consulta = $db->prepare("SELECT id, nombre, region, descripcion, impacto FROM productores WHERE id IN ($marcasPr)");
            $consulta->execute($productorIds);
            foreach ($consulta->fetchAll() as $fila) {
                $productores[(int) $fila['id']] = $fila;
            }
        }

        $frescuraStmt = $db->prepare(
            "SELECT producto_id, codigo, fecha_vencimiento, fecha_ingreso, temperatura
             FROM lotes WHERE estado IN ('recibido', 'en_camara', 'listo') AND producto_id IN ($marcas)
             ORDER BY fecha_vencimiento ASC, id ASC"
        );
        $frescuraStmt->execute($ids);
        $frescura = [];
        foreach ($frescuraStmt->fetchAll() as $fila) {
            $productoId = (int) $fila['producto_id'];
            if (isset($frescura[$productoId])) {
                continue;
            }
            $frescura[$productoId] = [
                'codigo' => $fila['codigo'],
                'vence' => $fila['fecha_vencimiento'],
                'ingreso' => $fila['fecha_ingreso'],
                'temperatura' => $fila['temperatura'] !== null ? (float) $fila['temperatura'] : null,
            ];
        }

        foreach ($productos as &$producto) {
            $producto['presentaciones'] = $porProducto[$producto['id']] ?? [];
            $origen = $productores[$producto['productor_id']] ?? null;
            $producto['productor'] = $origen['nombre'] ?? null;
            $producto['productor_region'] = $origen['region'] ?? null;
            $producto['productor_descripcion'] = $origen['descripcion'] ?? null;
            $producto['productor_impacto'] = $origen['impacto'] ?? null;
            $producto['frescura'] = $frescura[$producto['id']] ?? null;
        }
        unset($producto);
        return $productos;
    }

    private static function normalizarLote(array $fila): array
    {
        return [
            'id' => (int) $fila['id'],
            'codigo' => $fila['codigo'],
            'cantidad' => (int) $fila['cantidad'],
            'fecha_ingreso' => $fila['fecha_ingreso'] ?? null,
            'fecha_vencimiento' => $fila['fecha_vencimiento'],
            'temperatura' => $fila['temperatura'] !== null ? (float) $fila['temperatura'] : null,
            'estado' => $fila['estado'],
            'nota' => $fila['nota'],
            'producto_id' => (int) $fila['producto_id'],
            'producto' => $fila['producto'],
            'conservacion' => ($fila['conservacion'] ?? 'ambiente') === 'refrigerado' ? 'refrigerado' : 'ambiente',
            'precio_venta' => round((float) ($fila['precio_venta'] ?? 0), 2),
            'precio_compra' => round((float) ($fila['precio_compra'] ?? 0), 2),
            'venta_anterior' => isset($fila['venta_anterior']) && $fila['venta_anterior'] !== null ? round((float) $fila['venta_anterior'], 2) : null,
            'compra_anterior' => isset($fila['compra_anterior']) && $fila['compra_anterior'] !== null ? round((float) $fila['compra_anterior'], 2) : null,
            'precio_en' => $fila['precio_en'] ?? null,
        ];
    }

    private static function normalizarHistorial(array $fila): array
    {
        return [
            'venta_anterior' => round((float) $fila['venta_anterior'], 2),
            'compra_anterior' => round((float) $fila['compra_anterior'], 2),
            'venta_nueva' => round((float) $fila['venta_nueva'], 2),
            'compra_nueva' => round((float) $fila['compra_nueva'], 2),
            'created_at' => $fila['created_at'],
        ];
    }

    private static function loteConPrecio(int $id, bool $bloquear = false): array
    {
        $sql = 'SELECT l.id, l.codigo, l.producto_id, p.precio AS precio_venta, p.precio_compra, p.inyeccion, p.margen_utilidad
                FROM lotes l JOIN productos p ON p.id = l.producto_id WHERE l.id = ?';
        if ($bloquear) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = Db::get()->prepare($sql);
        $stmt->execute([$id]);
        $fila = $stmt->fetch() ?: throw new HttpError(404, 'Lote no encontrado');
        return [
            'id' => (int) $fila['id'],
            'codigo' => $fila['codigo'],
            'producto_id' => (int) $fila['producto_id'],
            'precio_venta' => round((float) $fila['precio_venta'], 2),
            'precio_compra' => round((float) $fila['precio_compra'], 2),
            'inyeccion' => round((float) $fila['inyeccion'], 2),
            'margen_utilidad' => round((float) $fila['margen_utilidad'], 2),
        ];
    }

    private static function registrarPrecio(\PDO $db, int $productoId, ?int $loteId, float $ventaAnterior, float $compraAnterior, float $ventaNueva, float $compraNueva, float $inyeccionAnterior = 0, float $margenAnterior = 0, float $inyeccionNueva = 0, float $margenNueva = 0): void
    {
        $db->prepare(
            'INSERT INTO historial_precios (producto_id, lote_id, venta_anterior, compra_anterior, venta_nueva, compra_nueva, inyeccion_anterior, margen_anterior, inyeccion_nueva, margen_nueva)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$productoId, $loteId, $ventaAnterior, $compraAnterior, $ventaNueva, $compraNueva, $inyeccionAnterior, $margenAnterior, $inyeccionNueva, $margenNueva]);
    }

    private static function exigirCaja(): array
    {
        return Guard::requirePermiso('pagos.ver');
    }

    private static function mesConsulta(?string $valor): string
    {
        $mes = trim((string) ($valor ?? ''));
        if ($mes === '') {
            $mes = date('Y-m');
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            throw new HttpError(422, 'El mes debe tener formato AAAA-MM');
        }
        return $mes;
    }

    private static function saldosCaja(\PDO $db, string $mes, bool $bloquear): array
    {
        $sql = 'SELECT tipo, monto FROM caja_movimientos WHERE mes = ?';
        if ($bloquear) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$mes]);
        $saldos = ['inyectado' => 0.0, 'compras' => 0.0, 'retiros' => 0.0];
        foreach ($stmt->fetchAll() as $mov) {
            $monto = round((float) $mov['monto'], 2);
            if ($mov['tipo'] === 'inyeccion') {
                $saldos['inyectado'] += $monto;
            } elseif ($mov['tipo'] === 'retiro') {
                $saldos['retiros'] += $monto;
            } else {
                $saldos['compras'] += $monto;
            }
        }
        return $saldos;
    }

    private static function cobradoDelMes(string $mes): float
    {
        $base = Config::get('PAGOS_SERVICE_URL', '');
        if ($base === '') {
            throw new HttpError(503, 'No se pudo comprobar el efectivo cobrado');
        }
        [$status, $respuesta] = ServiceClient::call('POST', rtrim($base, '/') . '/interno/cobrado', ['mes' => $mes]);
        if ($status !== 200 || !isset($respuesta['cobrado'])) {
            throw new HttpError(503, 'No se pudo comprobar el efectivo cobrado');
        }
        return round((float) $respuesta['cobrado'], 2);
    }

    private static function registrarCaja(\PDO $db, string $mes, string $tipo, float $monto, ?string $nota, ?int $loteId): void
    {
        $db->prepare(
            'INSERT INTO caja_movimientos (mes, tipo, monto, nota, lote_id) VALUES (?, ?, ?, ?, ?)'
        )->execute([$mes, $tipo, round($monto, 2), $nota, $loteId]);
    }

    private static function fecha(mixed $valor): ?string
    {
        $fecha = DateTime::createFromFormat('Y-m-d', (string) $valor);
        if (!$fecha || $fecha->format('Y-m-d') !== (string) $valor) {
            return null;
        }
        return $fecha->format('Y-m-d');
    }

    private static function temperatura(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $grados = filter_var($valor, FILTER_VALIDATE_FLOAT);
        if ($grados === false || $grados < -5 || $grados > 40) {
            throw new HttpError(422, 'Temperatura inválida (entre -5 y 40 °C)');
        }
        return round($grados, 1);
    }

    private static function claveCache(string $sufijo): string
    {
        return 'catalogo:v' . Cache::version(self::CLAVE_VERSION) . ':' . $sufijo;
    }

    private static function enteroPositivo(mixed $valor): ?int
    {
        $entero = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $entero === false ? null : $entero;
    }
}
