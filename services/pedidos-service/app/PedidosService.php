<?php
declare(strict_types=1);

final class PedidosService
{
    private const CARRITO_TTL = 172800;
    private const MAX_ITEMS = 50;
    private const MAX_CANTIDAD = 50;
    private const PATRON_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const ESTADOS = ['pendiente', 'pagado', 'preparando', 'en_camino', 'entregado', 'cancelado'];
    private const TRANSICIONES = [
        'pendiente' => ['pagado', 'preparando', 'cancelado'],
        'pagado' => ['preparando', 'cancelado'],
        'preparando' => ['en_camino', 'cancelado'],
        'en_camino' => ['entregado'],
        'entregado' => [],
        'cancelado' => [],
    ];
    // Campos que un cliente honesto nunca envía: el pedido se arma desde el carrito del servidor.
    private const CAMPOS_PROHIBIDOS = ['items', 'productos', 'precio', 'precios', 'precio_unitario', 'total', 'subtotal'];

    // ---------------------------------------------------------------- Carrito

    public static function verCarrito(): never
    {
        $cliente = Guard::user();
        Http::json(200, self::carritoVisible((int) $cliente['sub']));
    }

    public static function actualizarItem(): never
    {
        $cliente = Guard::user();
        $cuerpo = Http::body();
        $productoId = filter_var($cuerpo['producto_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cantidad = filter_var($cuerpo['cantidad'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_CANTIDAD]]);
        $presentacionId = filter_var($cuerpo['presentacion_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
        if ($productoId === false || $cantidad === false || $presentacionId === false) {
            throw new HttpError(422, 'producto_id o cantidad inválidos (cantidad de 0 a ' . self::MAX_CANTIDAD . ')');
        }

        $redis = RedisClient::get();
        $clave = self::claveCarrito((int) $cliente['sub']);
        $claveItem = $presentacionId > 0 ? $productoId . ':' . $presentacionId : (string) $productoId;

        if ($cantidad === 0) {
            $redis->hDel($clave, $claveItem);
        } else {
            $producto = self::consultarProductos([$productoId])[$productoId] ?? null;
            if (!$producto || !$producto['activo']) {
                throw new HttpError(404, 'Producto no disponible');
            }
            $oferta = self::resolverOferta($producto, (int) $presentacionId);
            if ($oferta === null) {
                throw new HttpError(404, 'Esa presentación no está disponible');
            }
            $otras = self::unidadesOtrasLineas($redis->hGetAll($clave) ?: [], $producto, $claveItem);
            $unidades = ($cantidad * $oferta['factor']) + $otras;
            if ($producto['stock'] < $unidades) {
                throw new HttpError(409, "Solo hay {$producto['stock']} unidades disponibles de {$producto['nombre']}");
            }
            if (!$redis->hExists($clave, $claveItem) && $redis->hLen($clave) >= self::MAX_ITEMS) {
                throw new HttpError(422, 'El carrito admite como máximo ' . self::MAX_ITEMS . ' productos distintos');
            }
            $redis->hSet($clave, $claveItem, (string) $cantidad);
            $redis->expire($clave, self::CARRITO_TTL);
        }

        Http::json(200, self::carritoVisible((int) $cliente['sub']));
    }

    public static function vaciarCarrito(): never
    {
        $cliente = Guard::user();
        RedisClient::get()->del(self::claveCarrito((int) $cliente['sub']));
        Http::json(200, ['items' => [], 'total' => 0]);
    }

    // ---------------------------------------------------------------- Pedidos del cliente

    public static function crearPedido(): never
    {
        $cliente = Guard::user();
        $clienteId = (int) $cliente['sub'];
        $cuerpo = Http::body();

        $camposSospechosos = array_values(array_intersect(self::CAMPOS_PROHIBIDOS, array_keys($cuerpo)));
        if ($camposSospechosos) {
            AuditClient::log('PEDIDO_CREADO', 'FRAUDE', Guard::actor($cliente) + [
                'dato_nuevo' => [
                    'motivo' => 'cliente_envio_precios_o_items',
                    'campos' => $camposSospechosos,
                    'valores_recibidos' => array_intersect_key($cuerpo, array_flip($camposSospechosos)),
                ],
            ]);
            throw new HttpError(400, 'Solicitud rechazada: los productos y precios se toman del carrito y del catálogo en el servidor');
        }

        $entrega = self::validarEntrega($cuerpo);
        $carrito = self::carritoDetallado($clienteId);
        if (!$carrito['items']) {
            throw new HttpError(422, 'El carrito está vacío');
        }
        $usarSaldo = filter_var($cuerpo['usar_saldo_envases'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $saldo = self::saldoEnvases($clienteId);
        $descuento = ($usarSaldo && $saldo > 0) ? round(min($saldo, max($carrito['total'] - 0.01, 0)), 2) : 0.0;
        $totalCobro = round($carrito['total'] - $descuento, 2);
        $hayRetornable = (bool) array_filter($carrito['items'], static fn (array $i): bool => !empty($i['retornable']));
        $pase = $hayRetornable ? bin2hex(random_bytes(4)) : null;
        $noDisponibles = array_values(array_filter($carrito['items'], static fn (array $i): bool => !$i['disponible']));
        if ($noDisponibles) {
            throw new HttpError(409, 'Algunos productos ya no tienen stock suficiente', ['no_disponibles' => $noDisponibles]);
        }

        $uuid = self::uuid4();
        $db = Db::get();
        $db->beginTransaction();
        try {
            $db->prepare(
                'INSERT INTO clientes (id_cliente, nombre, email) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), email = VALUES(email)'
            )->execute([$clienteId, $cliente['nombre'] ?? '', $cliente['email'] ?? '']);

            $db->prepare(
                'INSERT INTO pedidos (uuid, id_cliente, total, estado, direccion_entrega, zona, municipio, telefono, referencias, notas, descuento_envases, pase_envases, destino_lat, destino_lng)
                 VALUES (?, ?, ?, \'pendiente\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $uuid, $clienteId, $totalCobro,
                $entrega['direccion'], $entrega['zona'], $entrega['municipio'],
                $entrega['telefono'], $entrega['referencias'], $entrega['notas'],
                $descuento, $pase, $entrega['lat'], $entrega['lng'],
            ]);
            $pedidoId = (int) $db->lastInsertId();

            $detalle = $db->prepare(
                'INSERT INTO detalle_pedidos (pedido_id, producto_id, nombre_producto, precio_unitario, cantidad, subtotal, factor, costo_unitario)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($carrito['items'] as $item) {
                $nombre = $item['presentacion'] ? $item['nombre'] . ' · ' . $item['presentacion'] : $item['nombre'];
                $detalle->execute([
                    $pedidoId, $item['producto_id'], $nombre, $item['precio'], $item['cantidad'], $item['subtotal'], $item['factor'],
                    $item['costo_unitario'],
                ]);
            }
            if ($descuento > 0) {
                $db->prepare(
                    'INSERT INTO envases_movimientos (id_cliente, tipo, envases, monto, pedido_uuid, nota)
                     VALUES (?, \'uso\', 0, ?, ?, ?)'
                )->execute([$clienteId, $descuento, $uuid, 'Saldo de envases aplicado al pedido']);
            }

            self::upsertDireccion($db, $clienteId, $entrega);

            $items = self::agruparUnidades($carrito['items']);
            [$status, $respuesta] = ServiceClient::call('POST', self::urlCatalogo('/interno/stock/descontar'), [
                'pedido_id' => $pedidoId,
                'items' => $items,
            ]);
            if ($status === 409) {
                throw new HttpError(409, 'Stock insuficiente al confirmar el pedido', ['faltantes' => $respuesta['faltantes'] ?? []]);
            }
            if ($status !== 200) {
                throw new HttpError(503, 'No se pudo reservar el stock, intente de nuevo');
            }

            try {
                $db->commit();
            } catch (Throwable $e) {
                ServiceClient::call('POST', self::urlCatalogo('/interno/stock/reponer'), ['items' => $items]);
                throw $e;
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        RedisClient::get()->del(self::claveCarrito($clienteId));

        AuditClient::log('PEDIDO_CREADO', 'EXITO', Guard::actor($cliente) + [
            'pedido_id' => $pedidoId,
            'dato_nuevo' => [
                'uuid' => $uuid,
                'total' => $totalCobro,
                'descuento_envases' => $descuento,
                'estado' => 'pendiente',
                'items' => array_map(static fn (array $i): array => [
                    'producto_id' => $i['producto_id'],
                    'cantidad' => $i['cantidad'],
                    'precio_unitario' => $i['precio'],
                ], $carrito['items']),
            ],
        ]);

        Http::json(201, ['pedido' => self::buscarPedido($uuid, null)]);
    }

    public static function misPedidos(): never
    {
        $cliente = Guard::user();
        // A01: el filtro por cliente sale del JWT verificado, nunca de un parámetro de la petición.
        $stmt = Db::get()->prepare(
            'SELECT p.uuid, p.total, p.estado, p.fecha, COUNT(d.id) AS productos
             FROM pedidos p JOIN detalle_pedidos d ON d.pedido_id = p.id
             WHERE p.id_cliente = ?
             GROUP BY p.id ORDER BY p.id DESC LIMIT 50'
        );
        $stmt->execute([(int) $cliente['sub']]);
        Http::json(200, ['pedidos' => array_map([self::class, 'normalizarResumen'], $stmt->fetchAll())]);
    }

    public static function verPedido(string $uuid): never
    {
        $usuario = Guard::user();
        $esAdmin = Roles::puede((string) ($usuario['rol'] ?? ''), 'pedidos.gestionar');
        $pedido = self::buscarPedido($uuid, $esAdmin ? null : (int) $usuario['sub']);
        Http::json(200, ['pedido' => $pedido]);
    }

    public static function miDireccion(): never
    {
        $cliente = Guard::user();
        $stmt = Db::get()->prepare(
            'SELECT direccion, zona, municipio, telefono, referencias, lat, lng FROM direcciones_entrega
             WHERE id_cliente = ? AND alias = \'Principal\''
        );
        $stmt->execute([(int) $cliente['sub']]);
        Http::json(200, ['direccion' => self::normalizarDireccion($stmt->fetch() ?: null)]);
    }

    public static function guardarDireccion(): never
    {
        $cliente = Guard::user();
        $entrega = self::validarEntrega(Http::body());
        $db = Db::get();
        $id = (int) $cliente['sub'];
        $db->prepare(
            'INSERT INTO clientes (id_cliente, nombre, email) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), email = VALUES(email)'
        )->execute([$id, (string) ($cliente['nombre'] ?? ''), (string) ($cliente['email'] ?? '')]);
        self::upsertDireccion($db, $id, $entrega);
        Http::json(200, ['mensaje' => 'Ubicación guardada', 'direccion' => [
            'direccion' => $entrega['direccion'],
            'zona' => $entrega['zona'],
            'municipio' => $entrega['municipio'],
            'telefono' => $entrega['telefono'],
            'referencias' => $entrega['referencias'],
            'lat' => $entrega['lat'],
            'lng' => $entrega['lng'],
        ]]);
    }

    // ---------------------------------------------------------------- Admin

    public static function adminListar(): never
    {
        Guard::requirePermiso('pedidos.gestionar');
        $estado = in_array($_GET['estado'] ?? null, self::ESTADOS, true) ? $_GET['estado'] : null;
        $stmt = Db::get()->prepare(
            'SELECT p.uuid, p.id, p.total, p.estado, p.fecha, p.municipio, c.nombre AS cliente, c.email,
                    (SELECT COUNT(*) FROM detalle_pedidos d WHERE d.pedido_id = p.id) AS productos
             FROM pedidos p JOIN clientes c ON c.id_cliente = p.id_cliente'
            . ($estado ? ' WHERE p.estado = ?' : '')
            . ' ORDER BY p.id DESC LIMIT 100'
        );
        $stmt->execute($estado ? [$estado] : []);
        Http::json(200, [
            'pedidos' => array_map([self::class, 'normalizarResumen'], $stmt->fetchAll()),
            'transiciones' => self::TRANSICIONES,
        ]);
    }

    public static function adminCambiarEstado(string $uuid): never
    {
        $admin = Guard::requirePermiso('pedidos.gestionar');
        $nuevo = (string) (Http::body()['estado'] ?? '');
        if (!in_array($nuevo, self::ESTADOS, true)) {
            throw new HttpError(422, 'Estado inválido: ' . implode(', ', self::ESTADOS));
        }

        $db = Db::get();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT id, estado, id_cliente, descuento_envases FROM pedidos WHERE uuid = ? FOR UPDATE');
            $stmt->execute([self::validarUuid($uuid)]);
            $pedido = $stmt->fetch() ?: throw new HttpError(404, 'Pedido no encontrado');
            $anterior = $pedido['estado'];

            if (!in_array($nuevo, self::TRANSICIONES[$anterior], true)) {
                throw new HttpError(409, "No se puede pasar de '$anterior' a '$nuevo'", [
                    'permitidos' => self::TRANSICIONES[$anterior],
                ]);
            }
            $db->prepare('UPDATE pedidos SET estado = ? WHERE id = ?')->execute([$nuevo, $pedido['id']]);

            if ($nuevo === 'cancelado') {
                [$status] = ServiceClient::call('POST', self::urlCatalogo('/interno/stock/reponer'), [
                    'items' => self::lineasStock($db, (int) $pedido['id']),
                ]);
                if ($status !== 200) {
                    throw new HttpError(503, 'No se pudo reponer el stock; el pedido no se canceló');
                }
                self::revertirSaldoEnvases($db, (int) $pedido['id_cliente'], $uuid, (float) $pedido['descuento_envases']);
            }
            $db->commit();
            if ($nuevo === 'cancelado') {
                self::liberarEfectivo($uuid);
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        AuditClient::log('PEDIDO_ESTADO_CAMBIADO', 'EXITO', Guard::actor($admin) + [
            'pedido_id' => (int) $pedido['id'],
            'dato_anterior' => ['uuid' => $uuid, 'estado' => $anterior],
            'dato_nuevo' => ['uuid' => $uuid, 'estado' => $nuevo],
        ]);

        Http::json(200, ['pedido' => self::buscarPedido($uuid, null)]);
    }

    public static function adminListarClientes(): never
    {
        Guard::requirePermiso('clientes.exportar');
        Http::json(200, ['clientes' => self::consultarClientes()]);
    }

    /** El cliente solo puede cancelar mientras el pedido sigue pendiente (aún no pagado). */
    public static function cancelarMiPedido(string $uuid): never
    {
        $cliente = Guard::user();
        $db = Db::get();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT id, estado, id_cliente, descuento_envases FROM pedidos WHERE uuid = ? FOR UPDATE');
            $stmt->execute([self::validarUuid($uuid)]);
            $pedido = $stmt->fetch() ?: throw new HttpError(404, 'Pedido no encontrado');
            if ((int) $pedido['id_cliente'] !== (int) $cliente['sub'] && !Roles::puede((string) ($cliente['rol'] ?? ''), 'pedidos.gestionar')) {
                throw new HttpError(404, 'Pedido no encontrado');
            }
            if ($pedido['estado'] !== 'pendiente') {
                throw new HttpError(409, 'Solo puedes cancelar pedidos pendientes (antes de pagar)', [
                    'estado' => $pedido['estado'],
                ]);
            }
            $db->prepare('UPDATE pedidos SET estado = \'cancelado\' WHERE id = ?')->execute([$pedido['id']]);
            [$status] = ServiceClient::call('POST', self::urlCatalogo('/interno/stock/reponer'), [
                'items' => self::lineasStock($db, (int) $pedido['id']),
            ]);
            if ($status !== 200) {
                throw new HttpError(503, 'No se pudo reponer el stock; el pedido no se canceló');
            }
            self::revertirSaldoEnvases($db, (int) $pedido['id_cliente'], $uuid, (float) $pedido['descuento_envases']);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        self::liberarEfectivo($uuid);

        AuditClient::log('PEDIDO_ESTADO_CAMBIADO', 'EXITO', Guard::actor($cliente) + [
            'pedido_id' => (int) $pedido['id'],
            'dato_anterior' => ['uuid' => $uuid, 'estado' => 'pendiente'],
            'dato_nuevo' => ['uuid' => $uuid, 'estado' => 'cancelado', 'motivo' => 'cancelado_por_cliente'],
        ]);
        Http::json(200, ['pedido' => self::buscarPedido($uuid, null)]);
    }

    public static function internoConsultar(): never
    {
        Guard::service(['pagos-service']);
        $uuid = (string) (Http::body()['pedido_uuid'] ?? '');
        $stmt = Db::get()->prepare(
            'SELECT p.id, p.uuid, p.id_cliente, p.total, p.estado, c.email, c.nombre
             FROM pedidos p JOIN clientes c ON c.id_cliente = p.id_cliente WHERE p.uuid = ?'
        );
        $stmt->execute([self::validarUuid($uuid)]);
        $pedido = $stmt->fetch() ?: throw new HttpError(404, 'Pedido no encontrado');
        $pedido['id'] = (int) $pedido['id'];
        $pedido['id_cliente'] = (int) $pedido['id_cliente'];
        $pedido['total'] = (float) $pedido['total'];
        Http::json(200, ['pedido' => $pedido]);
    }

    public static function internoMarcarPagado(): never
    {
        Guard::service(['pagos-service']);
        $uuid = (string) (Http::body()['pedido_uuid'] ?? '');
        $db = Db::get();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT id, estado FROM pedidos WHERE uuid = ? FOR UPDATE');
            $stmt->execute([self::validarUuid($uuid)]);
            $pedido = $stmt->fetch() ?: throw new HttpError(404, 'Pedido no encontrado');
            if ($pedido['estado'] === 'pagado' || $pedido['estado'] === 'preparando'
                || $pedido['estado'] === 'en_camino' || $pedido['estado'] === 'entregado') {
                $db->commit();
                Http::json(200, ['ok' => true, 'estado' => $pedido['estado'], 'idempotente' => true]);
            }
            if ($pedido['estado'] !== 'pendiente') {
                throw new HttpError(409, 'El pedido no puede marcarse como pagado', ['estado' => $pedido['estado']]);
            }
            $db->prepare('UPDATE pedidos SET estado = \'pagado\' WHERE id = ?')->execute([$pedido['id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        AuditClient::log('PEDIDO_ESTADO_CAMBIADO', 'EXITO', [
            'rol' => 'anonimo',
            'pedido_id' => (int) $pedido['id'],
            'dato_anterior' => ['uuid' => $uuid, 'estado' => 'pendiente'],
            'dato_nuevo' => ['uuid' => $uuid, 'estado' => 'pagado', 'motivo' => 'pago_confirmado'],
        ]);
        Http::json(200, ['ok' => true, 'estado' => 'pagado']);
    }

    /** Exportación de clientes en CSV (queda registrada como EXPORTACION_CLIENTES). */
    public static function adminExportarClientes(): never
    {
        $admin = Guard::requirePermiso('clientes.exportar');
        $filas = self::consultarClientes();

        AuditClient::log('EXPORTACION_CLIENTES', 'EXITO', Guard::actor($admin) + [
            'dato_nuevo' => ['formato' => 'csv', 'registros' => count($filas), 'columnas' => ['id_cliente', 'nombre', 'email', 'pedidos', 'total_comprado', 'ultima_compra']],
        ]);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="clientes_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-store');
        $salida = fopen('php://output', 'w');
        fwrite($salida, "\xEF\xBB\xBF");
        fputcsv($salida, ['id_cliente', 'nombre', 'email', 'pedidos', 'total_comprado', 'ultima_compra']);
        foreach ($filas as $fila) {
            fputcsv($salida, array_map([self::class, 'celdaCsvSegura'], [
                $fila['id_cliente'], $fila['nombre'], $fila['email'], $fila['pedidos'], $fila['total_comprado'], $fila['ultima_compra'],
            ]));
        }
        fclose($salida);
        exit;
    }

    private static function consultarClientes(): array
    {
        $filas = Db::get()->query(
            'SELECT c.id_cliente, c.nombre, c.email, COUNT(p.id) AS pedidos, COALESCE(SUM(p.total), 0) AS total_comprado,
                    MAX(p.fecha) AS ultima_compra
             FROM clientes c LEFT JOIN pedidos p ON p.id_cliente = c.id_cliente AND p.estado <> \'cancelado\'
             GROUP BY c.id_cliente, c.nombre, c.email ORDER BY total_comprado DESC'
        )->fetchAll();
        return array_map(static function (array $fila): array {
            return [
                'id_cliente' => (int) $fila['id_cliente'],
                'nombre' => $fila['nombre'],
                'email' => $fila['email'],
                'pedidos' => (int) $fila['pedidos'],
                'total_comprado' => (float) $fila['total_comprado'],
                'ultima_compra' => $fila['ultima_compra'],
            ];
        }, $filas);
    }

    // ---------------------------------------------------------------- Utilidades

    public static function adminCapital(): never
    {
        Guard::requirePermiso('pagos.ver');
        $mes = trim((string) ($_GET['mes'] ?? ''));
        if ($mes === '') {
            $mes = date('Y-m');
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            throw new HttpError(422, 'El mes debe tener formato AAAA-MM');
        }
        $inicio = $mes . '-01 00:00:00';
        $fin = date('Y-m-d H:i:s', strtotime($inicio . ' +1 month'));
        $stmt = Db::get()->prepare(
            'SELECT p.uuid, p.estado, d.producto_id, d.nombre_producto, d.cantidad, d.precio_unitario,
                    d.costo_unitario, d.subtotal, d.factor
             FROM pedidos p JOIN detalle_pedidos d ON d.pedido_id = p.id
             WHERE p.estado <> \'cancelado\' AND p.fecha >= ? AND p.fecha < ?
             ORDER BY p.fecha DESC, d.id'
        );
        $stmt->execute([$inicio, $fin]);
        $lineas = array_map(static function (array $fila): array {
            return [
                'uuid' => $fila['uuid'],
                'estado' => $fila['estado'],
                'producto_id' => (int) $fila['producto_id'],
                'nombre' => $fila['nombre_producto'],
                'cantidad' => (int) $fila['cantidad'],
                'precio_unitario' => round((float) $fila['precio_unitario'], 2),
                'costo_unitario' => $fila['costo_unitario'] === null ? null : round((float) $fila['costo_unitario'], 2),
                'subtotal' => round((float) $fila['subtotal'], 2),
                'factor' => (int) ($fila['factor'] ?? 1),
            ];
        }, $stmt->fetchAll());
        Http::json(200, ['mes' => $mes, 'lineas' => $lineas]);
    }

    public static function adminReporte(): never
    {
        $claims = Guard::user();
        $rol = (string) ($claims['rol'] ?? '');
        if (!Roles::puede($rol, 'pagos.ver') && !Roles::puede($rol, 'pedidos.gestionar')) {
            throw new HttpError(403, 'No tienes permiso para la reportería');
        }
        $mes = trim((string) ($_GET['mes'] ?? ''));
        if ($mes === '') {
            $mes = date('Y-m');
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            throw new HttpError(422, 'El mes debe tener formato AAAA-MM');
        }
        $inicio = $mes . '-01 00:00:00';
        $fin = date('Y-m-d H:i:s', strtotime($inicio . ' +1 month'));
        $db = Db::get();
        $estados = $db->prepare(
            'SELECT estado, COUNT(*) AS pedidos, COALESCE(SUM(total), 0) AS total
             FROM pedidos WHERE fecha >= ? AND fecha < ? GROUP BY estado ORDER BY estado'
        );
        $estados->execute([$inicio, $fin]);
        $productos = $db->prepare(
            'SELECT d.nombre_producto AS nombre, SUM(d.cantidad) AS unidades, COALESCE(SUM(d.subtotal), 0) AS venta
             FROM detalle_pedidos d JOIN pedidos p ON p.id = d.pedido_id
             WHERE p.estado <> \'cancelado\' AND p.fecha >= ? AND p.fecha < ?
             GROUP BY d.nombre_producto ORDER BY venta DESC, nombre'
        );
        $productos->execute([$inicio, $fin]);
        Http::json(200, [
            'mes' => $mes,
            'estados' => array_map(static function (array $fila): array {
                return [
                    'estado' => $fila['estado'],
                    'pedidos' => (int) $fila['pedidos'],
                    'total' => round((float) $fila['total'], 2),
                ];
            }, $estados->fetchAll()),
            'productos' => array_map(static function (array $fila): array {
                return [
                    'nombre' => $fila['nombre'],
                    'unidades' => (int) $fila['unidades'],
                    'venta' => round((float) $fila['venta'], 2),
                ];
            }, $productos->fetchAll()),
        ]);
    }

    private static function carritoVisible(int $clienteId): array
    {
        $carrito = self::carritoDetallado($clienteId);
        foreach ($carrito['items'] as &$item) {
            unset($item['costo_unitario']);
        }
        unset($item);
        return $carrito;
    }

    private static function carritoDetallado(int $clienteId): array
    {
        $guardado = RedisClient::get()->hGetAll(self::claveCarrito($clienteId)) ?: [];
        if (!$guardado) {
            return ['items' => [], 'total' => 0, 'saldo_envases' => self::saldoEnvases($clienteId)];
        }

        $ids = [];
        foreach (array_keys($guardado) as $claveItem) {
            [$productoId] = self::parsearClaveCarrito((string) $claveItem);
            $ids[] = $productoId;
        }
        $productos = self::consultarProductos($ids);
        $unidades = [];
        $lineas = [];
        foreach ($guardado as $claveItem => $cantidad) {
            [$productoId, $presentacionId] = self::parsearClaveCarrito((string) $claveItem);
            $producto = $productos[$productoId] ?? null;
            if (!$producto) {
                continue;
            }
            $oferta = self::resolverOferta($producto, $presentacionId) ?? ['factor' => 1, 'precio' => $producto['precio'], 'nombre' => null, 'valida' => false];
            $cantidad = (int) $cantidad;
            $lineas[] = [$producto, $oferta, $cantidad, $presentacionId];
            $unidades[$productoId] = ($unidades[$productoId] ?? 0) + ($cantidad * (int) $oferta['factor']);
        }

        $items = [];
        $total = 0.0;
        foreach ($lineas as [$producto, $oferta, $cantidad, $presentacionId]) {
            $subtotal = round($oferta['precio'] * $cantidad, 2);
            $valida = ($oferta['valida'] ?? true) && $producto['activo'];
            $disponible = $valida && $producto['stock'] >= ($unidades[$producto['id']] ?? 0);
            $items[] = [
                'producto_id' => $producto['id'],
                'presentacion_id' => $presentacionId,
                'presentacion' => $oferta['nombre'],
                'factor' => (int) $oferta['factor'],
                'nombre' => $producto['nombre'],
                'icono' => $producto['icono'],
                'imagen_url' => $producto['imagen_url'],
                'unidad' => $producto['unidad'],
                'precio' => (float) $oferta['precio'],
                'costo_unitario' => round((float) ($producto['precio_compra'] ?? 0) * (int) $oferta['factor'], 2),
                'cantidad' => $cantidad,
                'subtotal' => $subtotal,
                'stock' => $producto['stock'],
                'retornable' => (bool) ($producto['retornable'] ?? false),
                'deposito_envase' => (float) ($producto['deposito_envase'] ?? 0),
                'disponible' => $disponible,
            ];
            if ($disponible) {
                $total += $subtotal;
            }
        }
        return ['items' => $items, 'total' => round($total, 2), 'saldo_envases' => self::saldoEnvases($clienteId)];
    }

    /** Precios y stock reales desde catalogo-service (el cliente nunca los define). */
    private static function consultarProductos(array $ids): array
    {
        [$status, $respuesta] = ServiceClient::call('POST', self::urlCatalogo('/interno/productos/consulta'), ['ids' => $ids]);
        if ($status !== 200) {
            throw new HttpError(503, 'El catálogo no está disponible en este momento');
        }
        return array_column($respuesta['productos'] ?? [], null, 'id');
    }

    private static function buscarPedido(string $uuid, ?int $clienteId): array
    {
        $sql = 'SELECT p.id, p.uuid, p.id_cliente, p.total, p.estado, p.direccion_entrega, p.zona, p.municipio,
                       p.telefono, p.referencias, p.notas, p.fecha, p.updated_at, p.descuento_envases,
                       p.repartidor_nombre, p.lat, p.lng, p.destino_lat, p.destino_lng, p.calificacion, p.propina, p.comentario, p.pase_envases,
                       c.nombre AS cliente, c.email
                FROM pedidos p JOIN clientes c ON c.id_cliente = p.id_cliente
                WHERE p.uuid = ?';
        $params = [self::validarUuid($uuid)];
        if ($clienteId !== null) {
            $sql .= ' AND p.id_cliente = ?';
            $params[] = $clienteId;
        }
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($params);
        // Mismo 404 si no existe o si es de otro cliente: no se revela que el pedido existe.
        $pedido = $stmt->fetch() ?: throw new HttpError(404, 'Pedido no encontrado');

        $detalle = Db::get()->prepare(
            'SELECT producto_id, nombre_producto, precio_unitario, cantidad, subtotal FROM detalle_pedidos WHERE pedido_id = ?'
        );
        $detalle->execute([$pedido['id']]);
        $pedido['detalle'] = array_map(static fn (array $d): array => [
            'producto_id' => (int) $d['producto_id'],
            'nombre' => $d['nombre_producto'],
            'precio_unitario' => (float) $d['precio_unitario'],
            'cantidad' => (int) $d['cantidad'],
            'subtotal' => (float) $d['subtotal'],
        ], $detalle->fetchAll());
        $pedido['total'] = (float) $pedido['total'];
        $pedido['descuento_envases'] = (float) ($pedido['descuento_envases'] ?? 0);
        $pedido['lat'] = $pedido['lat'] !== null ? (float) $pedido['lat'] : null;
        $pedido['lng'] = $pedido['lng'] !== null ? (float) $pedido['lng'] : null;
        $pedido['destino_lat'] = $pedido['destino_lat'] !== null ? (float) $pedido['destino_lat'] : null;
        $pedido['destino_lng'] = $pedido['destino_lng'] !== null ? (float) $pedido['destino_lng'] : null;
        $pedido['calificacion'] = $pedido['calificacion'] !== null ? (int) $pedido['calificacion'] : null;
        $pedido['propina'] = $pedido['propina'] !== null ? (float) $pedido['propina'] : null;
        if ($pedido['estado'] !== 'entregado') {
            $pedido['pase_envases'] = null;
        }
        $mensajes = Db::get()->prepare(
            'SELECT autor, texto, created_at FROM mensajes_pedido WHERE pedido_id = ? ORDER BY id ASC LIMIT 40'
        );
        $mensajes->execute([(int) $pedido['id']]);
        $pedido['mensajes'] = $mensajes->fetchAll();
        $pedido['transiciones_permitidas'] = self::TRANSICIONES[$pedido['estado']];
        unset($pedido['id'], $pedido['id_cliente']);
        return $pedido;
    }

    public static function miBilletera(): never
    {
        $cliente = Guard::user();
        $id = (int) $cliente['sub'];
        $stmt = Db::get()->prepare(
            'SELECT tipo, envases, monto, pedido_uuid, nota, created_at
             FROM envases_movimientos WHERE id_cliente = ? ORDER BY id DESC LIMIT 20'
        );
        $stmt->execute([$id]);
        $movimientos = array_map(static function (array $fila): array {
            $fila['envases'] = (int) $fila['envases'];
            $fila['monto'] = (float) $fila['monto'];
            return $fila;
        }, $stmt->fetchAll());
        Http::json(200, ['saldo' => self::saldoEnvases($id), 'movimientos' => $movimientos]);
    }

    public static function acreditarEnvases(): never
    {
        $cliente = Guard::user();
        $pase = strtolower(trim((string) (Http::body()['pase'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}$/', $pase)) {
            throw new HttpError(422, 'El pase de envases tiene 8 caracteres');
        }

        $db = Db::get();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT id, uuid, estado FROM pedidos WHERE pase_envases = ? AND id_cliente = ? FOR UPDATE'
            );
            $stmt->execute([$pase, (int) $cliente['sub']]);
            $pedido = $stmt->fetch() ?: throw new HttpError(404, 'Pase no encontrado');
            if ($pedido['estado'] !== 'entregado') {
                throw new HttpError(409, 'El pase se acredita cuando el pedido ya fue entregado');
            }
            $ya = $db->prepare("SELECT id FROM envases_movimientos WHERE pedido_uuid = ? AND tipo = 'acreditacion'");
            $ya->execute([$pedido['uuid']]);
            if ($ya->fetch()) {
                throw new HttpError(409, 'Esos envases ya fueron acreditados');
            }
            $detalle = $db->prepare('SELECT producto_id, cantidad FROM detalle_pedidos WHERE pedido_id = ?');
            $detalle->execute([(int) $pedido['id']]);
            $lineas = $detalle->fetchAll();
            $productos = self::consultarProductos(array_map(static fn (array $l): int => (int) $l['producto_id'], $lineas));
            $envases = 0;
            $monto = 0.0;
            foreach ($lineas as $linea) {
                $producto = $productos[(int) $linea['producto_id']] ?? null;
                if (!$producto || empty($producto['retornable'])) {
                    continue;
                }
                $envases += (int) $linea['cantidad'];
                $monto += (int) $linea['cantidad'] * (float) $producto['deposito_envase'];
            }
            $monto = round($monto, 2);
            if ($envases < 1 || $monto <= 0) {
                throw new HttpError(422, 'Este pedido no tiene envases retornables');
            }
            $db->prepare(
                'INSERT INTO envases_movimientos (id_cliente, tipo, envases, monto, pedido_uuid, nota)
                 VALUES (?, \'acreditacion\', ?, ?, ?, ?)'
            )->execute([(int) $cliente['sub'], $envases, $monto, $pedido['uuid'], 'Devolución de envases con pase']);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        AuditClient::log('ENVASE_ACREDITADO', 'EXITO', Guard::actor($cliente) + [
            'pedido_id' => (int) $pedido['id'],
            'dato_nuevo' => ['uuid' => $pedido['uuid'], 'envases' => $envases, 'monto' => $monto],
        ]);
        Http::json(200, ['saldo' => self::saldoEnvases((int) $cliente['sub']), 'acreditado' => $monto, 'envases' => $envases]);
    }

    public static function calificar(string $uuid): never
    {
        $cliente = Guard::user();
        $cuerpo = Http::body();
        $estrellas = filter_var($cuerpo['estrellas'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $propina = filter_var($cuerpo['propina'] ?? 0, FILTER_VALIDATE_FLOAT);
        $comentario = trim(strip_tags((string) ($cuerpo['comentario'] ?? '')));
        if ($estrellas === false || $propina === false || $propina < 0 || $propina > 200) {
            throw new HttpError(422, 'Calificación de 1 a 5 y propina entre 0 y 200');
        }
        if (mb_strlen($comentario) > 300) {
            throw new HttpError(422, 'El comentario no puede superar 300 caracteres');
        }
        $propina = round($propina, 2);

        $db = Db::get();
        $stmt = $db->prepare('SELECT id, estado, id_cliente, calificacion FROM pedidos WHERE uuid = ?');
        $stmt->execute([self::validarUuid($uuid)]);
        $pedido = $stmt->fetch() ?: throw new HttpError(404, 'Pedido no encontrado');
        if ((int) $pedido['id_cliente'] !== (int) $cliente['sub']) {
            throw new HttpError(404, 'Pedido no encontrado');
        }
        if ($pedido['estado'] !== 'entregado') {
            throw new HttpError(409, 'Solo puedes calificar un pedido entregado');
        }
        if ($pedido['calificacion'] !== null) {
            throw new HttpError(409, 'Este pedido ya fue calificado');
        }
        $db->prepare('UPDATE pedidos SET calificacion = ?, propina = ?, comentario = ? WHERE id = ?')
            ->execute([$estrellas, $propina, $comentario !== '' ? $comentario : null, $pedido['id']]);

        AuditClient::log('CALIFICACION_PEDIDO', 'EXITO', Guard::actor($cliente) + [
            'pedido_id' => (int) $pedido['id'],
            'dato_nuevo' => ['uuid' => $uuid, 'estrellas' => $estrellas, 'propina' => $propina],
        ]);
        Http::json(200, ['pedido' => self::buscarPedido($uuid, (int) $cliente['sub'])]);
    }

    public static function listarMensajes(string $uuid): never
    {
        $usuario = Guard::user();
        $esStaff = Roles::puede((string) ($usuario['rol'] ?? ''), 'pedidos.gestionar');
        Http::json(200, ['pedido' => self::buscarPedido($uuid, $esStaff ? null : (int) $usuario['sub'])]);
    }

    public static function enviarMensaje(string $uuid): never
    {
        $usuario = Guard::user();
        $esStaff = Roles::puede((string) ($usuario['rol'] ?? ''), 'pedidos.gestionar');
        $texto = trim(strip_tags((string) (Http::body()['texto'] ?? '')));
        if ($texto === '' || mb_strlen($texto) > 300) {
            throw new HttpError(422, 'El mensaje debe tener entre 1 y 300 caracteres');
        }
        $db = Db::get();
        $stmt = $db->prepare('SELECT id, id_cliente, estado FROM pedidos WHERE uuid = ?');
        $stmt->execute([self::validarUuid($uuid)]);
        $pedido = $stmt->fetch() ?: throw new HttpError(404, 'Pedido no encontrado');
        if (!$esStaff && (int) $pedido['id_cliente'] !== (int) $usuario['sub']) {
            throw new HttpError(404, 'Pedido no encontrado');
        }
        if (in_array($pedido['estado'], ['cancelado'], true)) {
            throw new HttpError(409, 'No se puede escribir en un pedido cancelado');
        }
        $db->prepare('INSERT INTO mensajes_pedido (pedido_id, autor, texto) VALUES (?, ?, ?)')
            ->execute([(int) $pedido['id'], $esStaff ? 'equipo' : 'cliente', $texto]);
        Http::json(201, ['pedido' => self::buscarPedido($uuid, $esStaff ? null : (int) $usuario['sub'])]);
    }

    public static function adminActualizarRuta(string $uuid): never
    {
        $admin = Guard::requirePermiso('pedidos.gestionar');
        $cuerpo = Http::body();
        $nombre = trim(strip_tags((string) ($cuerpo['repartidor_nombre'] ?? '')));
        if ($nombre !== '' && !preg_match('/^[\p{L}\p{N} .\'-]{2,80}$/u', $nombre)) {
            throw new HttpError(422, 'Nombre de repartidor inválido');
        }
        $db = Db::get();
        $stmt = $db->prepare('SELECT id, estado FROM pedidos WHERE uuid = ?');
        $stmt->execute([self::validarUuid($uuid)]);
        $pedido = $stmt->fetch() ?: throw new HttpError(404, 'Pedido no encontrado');
        $nombreGuardado = $nombre !== '' ? $nombre : null;
        if (($cuerpo['lat'] ?? '') !== '' && $cuerpo['lat'] !== null) {
            $punto = self::coordenadas($cuerpo['lat'] ?? null, $cuerpo['lng'] ?? null);
            $db->prepare('UPDATE pedidos SET repartidor_nombre = ?, lat = ?, lng = ? WHERE id = ?')
                ->execute([$nombreGuardado, $punto['lat'], $punto['lng'], $pedido['id']]);
            $lat = $punto['lat'];
            $lng = $punto['lng'];
        } else {
            $db->prepare('UPDATE pedidos SET repartidor_nombre = ? WHERE id = ?')
                ->execute([$nombreGuardado, $pedido['id']]);
            $lat = null;
            $lng = null;
        }
        AuditClient::log('RUTA_ACTUALIZADA', 'EXITO', Guard::actor($admin) + [
            'pedido_id' => (int) $pedido['id'],
            'dato_nuevo' => ['uuid' => $uuid, 'repartidor' => $nombre !== '' ? $nombre : null, 'lat' => $lat, 'lng' => $lng],
        ]);
        Http::json(200, ['pedido' => self::buscarPedido($uuid, null)]);
    }

    private static function validarEntrega(array $cuerpo): array
    {
        $telefono = preg_replace('/[\s-]/', '', (string) ($cuerpo['telefono'] ?? ''));
        if (!preg_match('/^(\+502)?\d{8}$/', $telefono)) {
            throw new HttpError(422, 'Teléfono inválido (8 dígitos, opcional +502)');
        }

        $punto = self::coordenadas($cuerpo['destino_lat'] ?? null, $cuerpo['destino_lng'] ?? null);
        return [
            'direccion' => self::texto($cuerpo, 'direccion', 5, 200),
            'zona' => self::texto($cuerpo, 'zona', 0, 30) ?: null,
            'municipio' => self::texto($cuerpo, 'municipio', 2, 60),
            'telefono' => $telefono,
            'referencias' => self::texto($cuerpo, 'referencias', 0, 200) ?: null,
            'notas' => self::texto($cuerpo, 'notas', 0, 300) ?: null,
            'lat' => $punto['lat'],
            'lng' => $punto['lng'],
        ];
    }

    private static function coordenadas(mixed $latitud, mixed $longitud): array
    {
        $latVacio = $latitud === null || $latitud === '';
        $lngVacio = $longitud === null || $longitud === '';
        if ($latVacio && $lngVacio) {
            return ['lat' => null, 'lng' => null];
        }
        $lat = filter_var($latitud, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($longitud, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new HttpError(422, 'No se pudo leer la ubicación. Vuelve a usar el GPS.');
        }
        return ['lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6)];
    }

    private static function upsertDireccion(\PDO $db, int $clienteId, array $entrega): void
    {
        $db->prepare(
            'INSERT INTO direcciones_entrega (id_cliente, alias, direccion, zona, municipio, telefono, referencias, lat, lng)
             VALUES (?, \'Principal\', ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE direccion = VALUES(direccion), zona = VALUES(zona), municipio = VALUES(municipio),
                                     telefono = VALUES(telefono), referencias = VALUES(referencias),
                                     lat = VALUES(lat), lng = VALUES(lng)'
        )->execute([
            $clienteId, $entrega['direccion'], $entrega['zona'], $entrega['municipio'],
            $entrega['telefono'], $entrega['referencias'], $entrega['lat'], $entrega['lng'],
        ]);
    }

    private static function normalizarDireccion(?array $fila): ?array
    {
        if (!$fila) {
            return null;
        }
        $fila['lat'] = $fila['lat'] !== null ? (float) $fila['lat'] : null;
        $fila['lng'] = $fila['lng'] !== null ? (float) $fila['lng'] : null;
        return $fila;
    }

    private static function texto(array $cuerpo, string $campo, int $min, int $max): string
    {
        $valor = trim(strip_tags((string) ($cuerpo[$campo] ?? '')));
        $largo = mb_strlen($valor);
        if ($largo < $min || $largo > $max) {
            throw new HttpError(422, "El campo $campo debe tener entre $min y $max caracteres");
        }
        return $valor;
    }

    private static function normalizarResumen(array $fila): array
    {
        $fila['total'] = (float) $fila['total'];
        $fila['productos'] = (int) $fila['productos'];
        if (isset($fila['id'])) {
            $fila['id'] = (int) $fila['id'];
        }
        return $fila;
    }

    /** Evita inyección de fórmulas al abrir el CSV en Excel (=, +, -, @). */
    private static function celdaCsvSegura(mixed $valor): string
    {
        $valor = (string) $valor;
        return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
    }

    private static function validarUuid(string $uuid): string
    {
        if (!preg_match(self::PATRON_UUID, $uuid)) {
            throw new HttpError(404, 'Pedido no encontrado');
        }
        return $uuid;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function claveCarrito(int $clienteId): string
    {
        return "carrito:$clienteId";
    }

    /** @return array{0: int, 1: int} */
    private static function parsearClaveCarrito(string $clave): array
    {
        if (str_contains($clave, ':')) {
            [$productoId, $presentacionId] = explode(':', $clave, 2);
            return [(int) $productoId, (int) $presentacionId];
        }
        return [(int) $clave, 0];
    }

    /** @return array{factor: int, precio: float, nombre: ?string, valida: bool}|null */
    private static function resolverOferta(array $producto, int $presentacionId): ?array
    {
        if ($presentacionId === 0) {
            return ['factor' => 1, 'precio' => (float) $producto['precio'], 'nombre' => null, 'valida' => true];
        }
        foreach ($producto['presentaciones'] ?? [] as $presentacion) {
            if ((int) $presentacion['id'] === $presentacionId) {
                return [
                    'factor' => (int) $presentacion['factor'],
                    'precio' => (float) $presentacion['precio'],
                    'nombre' => $presentacion['nombre'],
                    'valida' => true,
                ];
            }
        }
        return null;
    }

    private static function unidadesOtrasLineas(array $guardado, array $producto, string $excepto): int
    {
        $total = 0;
        foreach ($guardado as $clave => $cantidad) {
            if ((string) $clave === $excepto) {
                continue;
            }
            [$productoId, $presentacionId] = self::parsearClaveCarrito((string) $clave);
            if ($productoId !== (int) $producto['id']) {
                continue;
            }
            $oferta = self::resolverOferta($producto, $presentacionId);
            $total += (int) $cantidad * (int) ($oferta['factor'] ?? 1);
        }
        return $total;
    }

    /** @param list<array<string, mixed>> $items */
    private static function agruparUnidades(array $items): array
    {
        $agrupado = [];
        foreach ($items as $item) {
            $id = (int) $item['producto_id'];
            $agrupado[$id] = ($agrupado[$id] ?? 0) + ((int) $item['cantidad'] * max(1, (int) $item['factor']));
        }
        $lista = [];
        foreach ($agrupado as $id => $cantidad) {
            $lista[] = ['producto_id' => $id, 'cantidad' => $cantidad];
        }
        return $lista;
    }

    private static function lineasStock(PDO $db, int $pedidoId): array
    {
        $stmt = $db->prepare('SELECT producto_id, cantidad, factor FROM detalle_pedidos WHERE pedido_id = ?');
        $stmt->execute([$pedidoId]);
        $agrupado = [];
        foreach ($stmt->fetchAll() as $fila) {
            $id = (int) $fila['producto_id'];
            $agrupado[$id] = ($agrupado[$id] ?? 0) + ((int) $fila['cantidad'] * max(1, (int) ($fila['factor'] ?? 1)));
        }
        $lista = [];
        foreach ($agrupado as $id => $cantidad) {
            $lista[] = ['producto_id' => $id, 'cantidad' => $cantidad];
        }
        return $lista;
    }

    private static function saldoEnvases(int $clienteId, ?PDO $db = null): float
    {
        $stmt = ($db ?? Db::get())->prepare(
            "SELECT COALESCE(SUM(CASE tipo
                WHEN 'acreditacion' THEN monto
                WHEN 'reverso' THEN monto
                WHEN 'uso' THEN -monto
                ELSE 0 END), 0)
             FROM envases_movimientos WHERE id_cliente = ?"
        );
        $stmt->execute([$clienteId]);
        return round(max(0, (float) $stmt->fetchColumn()), 2);
    }

    private static function revertirSaldoEnvases(PDO $db, int $clienteId, string $uuid, float $descuento): void
    {
        if ($descuento <= 0) {
            return;
        }
        $db->prepare(
            'INSERT INTO envases_movimientos (id_cliente, tipo, envases, monto, pedido_uuid, nota)
             VALUES (?, \'reverso\', 0, ?, ?, ?)'
        )->execute([$clienteId, $descuento, $uuid, 'Reverso por cancelación del pedido']);
    }

    private static function urlCatalogo(string $ruta): string
    {
        return rtrim(Config::get('CATALOGO_SERVICE_URL'), '/') . $ruta;
    }

    private static function liberarEfectivo(string $uuid): void
    {
        $base = Config::get('PAGOS_SERVICE_URL', '');
        if ($base === '') {
            return;
        }
        try {
            ServiceClient::call('POST', rtrim($base, '/') . '/interno/liberar-efectivo', ['pedido_uuid' => $uuid]);
        } catch (Throwable $e) {
            error_log('[pedidos] no se pudo liberar el efectivo de ' . $uuid . ': ' . $e->getMessage());
        }
    }
}
