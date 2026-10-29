<?php
declare(strict_types=1);

/**
 * Pagos con Stripe (modo test gratuito). Nunca se recibe ni se guarda
 * número de tarjeta, CVV ni fecha de vencimiento: solo IDs de Stripe.
 */
final class PagosService
{
    public static function iniciarCheckout(): never
    {
        $cliente = Guard::user(['cliente', 'admin']);
        $cuerpo = Http::body();
        $uuid = trim((string) ($cuerpo['pedido_uuid'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            throw new HttpError(422, 'pedido_uuid inválido');
        }

        $pedido = self::consultarPedido($uuid);
        if ((int) $pedido['id_cliente'] !== (int) $cliente['sub'] && ($cliente['rol'] ?? '') !== 'admin') {
            throw new HttpError(404, 'Pedido no encontrado');
        }
        if ($pedido['estado'] !== 'pendiente') {
            throw new HttpError(409, 'Solo se pueden pagar pedidos en estado pendiente', ['estado' => $pedido['estado']]);
        }
        self::rechazarSiEfectivo($uuid);

        $monto = round((float) $pedido['total'], 2);
        if ($monto <= 0) {
            throw new HttpError(422, 'El monto del pedido es inválido');
        }

        if (!self::stripeConfigurado()) {
            Http::json(200, [
                'modo' => 'demo',
                'mensaje' => 'El cobro de prueba confirma el pedido. Si elegiste una tarjeta de regalo, el saldo se descuenta en ese paso.',
                'pedido_uuid' => $uuid,
                'monto' => $monto,
            ]);
        }

        $cobro = self::prepararCobro((int) $cliente['sub'], (int) $pedido['id'], $uuid, (int) $pedido['id_cliente'], $monto, $cuerpo);
        $pagoId = $cobro['pago_id'];

        AuditClient::log('PAGO_INICIADO', 'EXITO', Guard::actor($cliente) + [
            'pedido_id' => (int) $pedido['id'],
            'pago_id' => $pagoId,
            'dato_nuevo' => ['pedido_uuid' => $uuid, 'monto' => $cobro['monto'], 'descuento_regalo' => $cobro['descuento'], 'moneda' => 'GTQ'],
        ]);

        if ($cobro['monto'] <= 0) {
            self::completarPago($pagoId, (int) $pedido['id'], $uuid, 'regalo_' . bin2hex(random_bytes(6)), null, Guard::actor($cliente), 'regalo');
            Http::json(200, [
                'modo' => 'regalo',
                'mensaje' => 'La tarjeta de regalo cubrió el pedido.',
                'pedido_uuid' => $uuid,
                'monto' => 0,
                'descuento_regalo' => $cobro['descuento'],
            ]);
        }

        $baseUrl = rtrim(Config::get('PUBLIC_BASE_URL', 'http://localhost'), '/');
        try {
            $session = self::stripeRequest('POST', 'checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $baseUrl . '/#pedidos?pago=ok&uuid=' . urlencode($uuid),
            'cancel_url' => $baseUrl . '/#pedidos?pago=cancelado&uuid=' . urlencode($uuid),
            'client_reference_id' => $uuid,
            'customer_email' => $cliente['email'] ?? $pedido['email'] ?? null,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'gtq',
                    'unit_amount' => (int) round($cobro['monto'] * 100),
                    'product_data' => [
                        'name' => 'Pedido SuperAhorro GT',
                        'description' => 'Pedido ' . $uuid,
                    ],
                ],
            ]],
            'metadata' => [
                'pedido_uuid' => $uuid,
                'pedido_id' => (string) $pedido['id'],
                'pago_id' => (string) $pagoId,
            ],
            ]);
        } catch (Throwable $e) {
            if ($cobro['dedujo']) {
                self::revertirRegalo($pagoId, $cobro['regalo_id'], $cobro['descuento'], $monto);
            }
            throw $e;
        }

        Db::get()->prepare(
            'UPDATE pagos SET checkout_session_id = ?, payment_intent_id = ? WHERE id = ?'
        )->execute([
            $session['id'] ?? null,
            is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : ($session['payment_intent']['id'] ?? null),
            $pagoId,
        ]);

        Http::json(200, [
            'modo' => 'stripe',
            'checkout_url' => $session['url'] ?? null,
            'session_id' => $session['id'] ?? null,
            'pedido_uuid' => $uuid,
            'monto' => $cobro['monto'],
            'descuento_regalo' => $cobro['descuento'],
        ]);
    }

    /** Simulación gratuita cuando aún no hay claves Stripe (solo pedidos pendientes del dueño). */
    public static function demoConfirmar(): never
    {
        $cliente = Guard::user(['cliente', 'admin']);
        if (self::stripeConfigurado()) {
            throw new HttpError(400, 'Stripe está configurado: use el checkout real, no el modo demo');
        }
        $cuerpo = Http::body();
        $uuid = trim((string) ($cuerpo['pedido_uuid'] ?? ''));
        $pedido = self::consultarPedido($uuid);
        if ((int) $pedido['id_cliente'] !== (int) $cliente['sub'] && ($cliente['rol'] ?? '') !== 'admin') {
            throw new HttpError(404, 'Pedido no encontrado');
        }
        if ($pedido['estado'] !== 'pendiente') {
            throw new HttpError(409, 'El pedido ya no está pendiente');
        }
        self::rechazarSiEfectivo($uuid);

        $total = round((float) $pedido['total'], 2);
        $cobro = self::prepararCobro((int) $cliente['sub'], (int) $pedido['id'], $uuid, (int) $pedido['id_cliente'], $total, $cuerpo);
        if ($cobro['monto'] <= 0) {
            self::completarPago($cobro['pago_id'], (int) $pedido['id'], $uuid, 'regalo_' . bin2hex(random_bytes(6)), null, Guard::actor($cliente), 'regalo');
            Http::json(200, [
                'modo' => 'regalo',
                'mensaje' => 'La tarjeta de regalo cubrió el pedido.',
                'pedido_uuid' => $uuid,
                'descuento_regalo' => $cobro['descuento'],
            ]);
        }
        $fakeIntent = 'pi_demo_' . bin2hex(random_bytes(8));
        self::completarPago($cobro['pago_id'], (int) $pedido['id'], $uuid, $fakeIntent, null, Guard::actor($cliente));
        $mensaje = $cobro['descuento'] > 0
            ? 'Se descontó la tarjeta de regalo y el resto quedó como pago de prueba.'
            : 'Pago de prueba confirmado. El número de tarjeta no se guarda.';
        Http::json(200, [
            'modo' => 'demo',
            'mensaje' => $mensaje,
            'pedido_uuid' => $uuid,
            'payment_intent_id' => $fakeIntent,
            'monto' => $cobro['monto'],
            'descuento_regalo' => $cobro['descuento'],
        ]);
    }

    /** El pedido sigue pendiente: se prepara y el efectivo se cobra en la puerta. */
    public static function contraEntrega(): never
    {
        $cliente = Guard::user(['cliente', 'admin']);
        $cuerpo = Http::body();
        $uuid = self::uuidPedido($cuerpo);
        $pedido = self::consultarPedido($uuid);
        if ((int) $pedido['id_cliente'] !== (int) $cliente['sub'] && ($cliente['rol'] ?? '') !== 'admin') {
            throw new HttpError(404, 'Pedido no encontrado');
        }
        if ($pedido['estado'] !== 'pendiente') {
            throw new HttpError(409, 'Solo un pedido pendiente puede pasar a pago contra entrega');
        }
        $previo = self::pagoDelPedido($uuid);
        if ($previo && $previo['estado'] === 'completado') {
            throw new HttpError(409, 'Este pedido ya fue pagado');
        }
        if ($previo && $previo['proveedor'] === 'efectivo' && $previo['estado'] === 'iniciado') {
            Http::json(200, [
                'modo' => 'efectivo',
                'mensaje' => 'Este pedido ya quedó para cobrarse en efectivo al entregar.',
                'pedido_uuid' => $uuid,
                'monto' => (float) $previo['monto'],
            ]);
        }

        $total = round((float) $pedido['total'], 2);
        $cobro = self::prepararCobro((int) $cliente['sub'], (int) $pedido['id'], $uuid, (int) $pedido['id_cliente'], $total, $cuerpo);
        if ($cobro['monto'] <= 0) {
            self::completarPago($cobro['pago_id'], (int) $pedido['id'], $uuid, 'regalo_' . bin2hex(random_bytes(6)), null, Guard::actor($cliente), 'regalo');
            Http::json(200, [
                'modo' => 'regalo',
                'mensaje' => 'La tarjeta de regalo cubrió el pedido. No queda efectivo por cobrar.',
                'pedido_uuid' => $uuid,
                'descuento_regalo' => $cobro['descuento'],
            ]);
        }

        $etiqueta = $cobro['etiqueta'] ? $cobro['etiqueta'] . ' + Efectivo al entregar' : 'Efectivo al entregar';
        Db::get()->prepare('UPDATE pagos SET proveedor = \'efectivo\', metodo_etiqueta = ?, regalo_id = ? WHERE id = ?')
            ->execute([$etiqueta, $cobro['regalo_id'] > 0 ? $cobro['regalo_id'] : null, $cobro['pago_id']]);

        AuditClient::log('PAGO_INICIADO', 'EXITO', Guard::actor($cliente) + [
            'pedido_id' => (int) $pedido['id'],
            'pago_id' => $cobro['pago_id'],
            'dato_nuevo' => ['pedido_uuid' => $uuid, 'monto' => $cobro['monto'], 'proveedor' => 'efectivo'],
        ]);

        Http::json(200, [
            'modo' => 'efectivo',
            'mensaje' => 'Pagarás Q' . number_format($cobro['monto'], 2, '.', '') . ' en efectivo cuando llegue el pedido.',
            'pedido_uuid' => $uuid,
            'monto' => $cobro['monto'],
            'descuento_regalo' => $cobro['descuento'],
        ]);
    }

    public static function anularContraEntrega(): never
    {
        $cliente = Guard::user(['cliente', 'admin']);
        $uuid = self::uuidPedido(Http::body());
        $pedido = self::consultarPedido($uuid);
        if ((int) $pedido['id_cliente'] !== (int) $cliente['sub'] && ($cliente['rol'] ?? '') !== 'admin') {
            throw new HttpError(404, 'Pedido no encontrado');
        }
        if ($pedido['estado'] !== 'pendiente') {
            throw new HttpError(409, 'Solo puedes cambiar el pago mientras el pedido sigue pendiente');
        }
        $pago = self::pagoDelPedido($uuid);
        if (!$pago || $pago['proveedor'] !== 'efectivo' || !in_array($pago['estado'], ['iniciado', 'cancelado'], true)) {
            throw new HttpError(404, 'Este pedido no está en pago contra entrega');
        }
        if ($pago['estado'] === 'cancelado') {
            Http::json(200, ['mensaje' => 'El pago contra entrega ya estaba anulado']);
        }

        $db = Db::get();
        $db->beginTransaction();
        try {
            if ((int) $pago['regalo_id'] > 0 && (float) $pago['descuento_regalo'] > 0) {
                $db->prepare('UPDATE metodos_pago SET saldo = ROUND(saldo + ?, 2) WHERE id = ? AND id_cliente = ?')
                    ->execute([(float) $pago['descuento_regalo'], (int) $pago['regalo_id'], (int) $pedido['id_cliente']]);
            }
            $db->prepare('UPDATE pagos SET estado = \'cancelado\', descuento_regalo = 0, regalo_id = NULL WHERE id = ?')
                ->execute([(int) $pago['id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        Http::json(200, ['mensaje' => 'Listo. Ahora puedes pagar en línea o volver a elegir efectivo.']);
    }

    public static function cobrarEfectivo(): never
    {
        $usuario = Guard::user();
        $rol = (string) ($usuario['rol'] ?? '');
        if (!Roles::puede($rol, 'pedidos.gestionar') && !Roles::puede($rol, 'pagos.ver')) {
            throw new HttpError(403, 'No puedes registrar el efectivo');
        }
        $uuid = self::uuidPedido(Http::body());
        $pedido = self::consultarPedido($uuid);
        $pago = self::pagoDelPedido($uuid);
        if (!$pago || $pago['proveedor'] !== 'efectivo' || $pago['estado'] !== 'iniciado') {
            throw new HttpError(409, 'Este pedido no tiene un cobro en efectivo pendiente');
        }
        if (!in_array($pedido['estado'], ['en_camino', 'entregado'], true)) {
            throw new HttpError(409, 'El efectivo se registra cuando el pedido va en ruta o ya llegó');
        }
        Db::get()->prepare('UPDATE pagos SET estado = \'completado\' WHERE id = ?')->execute([(int) $pago['id']]);
        AuditClient::log('PAGO_CONFIRMADO', 'EXITO', Guard::actor($usuario) + [
            'pedido_id' => (int) $pedido['id'],
            'pago_id' => (int) $pago['id'],
            'dato_nuevo' => ['pedido_uuid' => $uuid, 'monto' => (float) $pago['monto'], 'proveedor' => 'efectivo'],
        ]);
        Http::json(200, ['mensaje' => 'Efectivo registrado', 'monto' => (float) $pago['monto']]);
    }

    /** Lo llama pedidos-service al cancelar, para devolver el saldo de regalo. */
    public static function internoLiberarEfectivo(): never
    {
        Guard::service(['pedidos-service']);
        $uuid = self::uuidPedido(Http::body());
        $pago = self::pagoDelPedido($uuid);
        if (!$pago || $pago['proveedor'] !== 'efectivo' || $pago['estado'] !== 'iniciado') {
            Http::json(200, ['ok' => true]);
        }
        $db = Db::get();
        $db->beginTransaction();
        try {
            if ((int) $pago['regalo_id'] > 0 && (float) $pago['descuento_regalo'] > 0) {
                $db->prepare('UPDATE metodos_pago SET saldo = ROUND(saldo + ?, 2) WHERE id = ? AND id_cliente = ?')
                    ->execute([(float) $pago['descuento_regalo'], (int) $pago['regalo_id'], (int) $pago['id_cliente']]);
            }
            $db->prepare('UPDATE pagos SET estado = \'cancelado\', descuento_regalo = 0, regalo_id = NULL WHERE id = ?')
                ->execute([(int) $pago['id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        Http::json(200, ['ok' => true]);
    }

    /** Lo consulta catalogo-service antes de dejar retirar efectivo. */
    public static function internoCobrado(): never
    {
        Guard::service(['catalogo-service']);
        $mes = trim((string) (Http::body()['mes'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            throw new HttpError(422, 'El mes debe tener formato AAAA-MM');
        }
        $inicio = $mes . '-01 00:00:00';
        $fin = date('Y-m-d H:i:s', strtotime($inicio . ' +1 month'));
        $suma = Db::get()->prepare(
            'SELECT COALESCE(SUM(monto), 0) FROM pagos
             WHERE estado = \'completado\' AND updated_at >= ? AND updated_at < ?'
        );
        $suma->execute([$inicio, $fin]);
        Http::json(200, ['mes' => $mes, 'cobrado' => round((float) $suma->fetchColumn(), 2)]);
    }

    public static function webhookStripe(): never
    {
        $payload = Http::rawBody(131072);
        $firma = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $secreto = Config::get('STRIPE_WEBHOOK_SECRET', '');

        if ($secreto === '' || str_starts_with($secreto, 'whsec_xxx')) {
            AuditClient::log('WEBHOOK_STRIPE_RECIBIDO', 'FALLO', [
                'rol' => 'anonimo',
                'dato_nuevo' => ['motivo' => 'webhook_secret_no_configurado'],
            ]);
            throw new HttpError(503, 'Webhook de Stripe no configurado');
        }

        try {
            $evento = self::verificarFirmaStripe($payload, $firma, $secreto);
        } catch (RuntimeException $e) {
            AuditClient::log('WEBHOOK_STRIPE_RECIBIDO', 'FRAUDE', [
                'rol' => 'anonimo',
                'dato_nuevo' => ['motivo' => 'firma_invalida', 'detalle' => $e->getMessage()],
            ]);
            throw new HttpError(400, 'Firma de webhook inválida');
        }

        AuditClient::log('WEBHOOK_STRIPE_RECIBIDO', 'EXITO', [
            'rol' => 'anonimo',
            'dato_nuevo' => ['tipo' => $evento['type'] ?? null, 'id' => $evento['id'] ?? null],
        ]);

        $tipo = $evento['type'] ?? '';
        $objeto = $evento['data']['object'] ?? [];

        if ($tipo === 'checkout.session.completed' && ($objeto['payment_status'] ?? '') === 'paid') {
            $uuid = $objeto['metadata']['pedido_uuid'] ?? $objeto['client_reference_id'] ?? '';
            $pagoId = (int) ($objeto['metadata']['pago_id'] ?? 0);
            $pedidoId = (int) ($objeto['metadata']['pedido_id'] ?? 0);
            $intent = is_string($objeto['payment_intent'] ?? null) ? $objeto['payment_intent'] : null;
            if ($uuid && $pagoId) {
                self::completarPago($pagoId, $pedidoId, $uuid, $intent, $objeto['id'] ?? null, ['rol' => 'anonimo']);
            }
        }

        if ($tipo === 'payment_intent.payment_failed') {
            $uuid = $objeto['metadata']['pedido_uuid'] ?? '';
            AuditClient::log('PAGO_FALLIDO', 'FALLO', [
                'rol' => 'anonimo',
                'pedido_id' => isset($objeto['metadata']['pedido_id']) ? (int) $objeto['metadata']['pedido_id'] : null,
                'dato_nuevo' => ['pedido_uuid' => $uuid, 'payment_intent_id' => $objeto['id'] ?? null],
            ]);
            if ($uuid) {
                Db::get()->prepare('UPDATE pagos SET estado = \'fallido\' WHERE pedido_uuid = ? AND estado = \'iniciado\'')
                    ->execute([$uuid]);
            }
        }

        Http::json(200, ['recibido' => true]);
    }

    public static function estadoPago(string $uuid): never
    {
        $usuario = Guard::user();
        $stmt = Db::get()->prepare('SELECT pedido_uuid, monto, descuento_regalo, metodo_etiqueta, estado, proveedor, payment_intent_id, created_at FROM pagos WHERE pedido_uuid = ?');
        $stmt->execute([$uuid]);
        $pago = $stmt->fetch();
        if (!$pago) {
            throw new HttpError(404, 'No hay pago registrado para este pedido');
        }
        $pedido = self::consultarPedido($uuid);
        $rol = (string) ($usuario['rol'] ?? '');
        $esStaff = Roles::puede($rol, 'pagos.ver') || Roles::puede($rol, 'pedidos.gestionar');
        if ((int) $pedido['id_cliente'] !== (int) $usuario['sub'] && !$esStaff) {
            throw new HttpError(404, 'No hay pago registrado para este pedido');
        }
        Http::json(200, ['pago' => $pago]);
    }

    public static function adminListar(): never
    {
        Guard::requirePermiso('pagos.ver');
        $estado = $_GET['estado'] ?? null;
        $sql = 'SELECT id, pedido_id, pedido_uuid, id_cliente, monto, descuento_regalo, moneda, estado, proveedor,
                       metodo_etiqueta, payment_intent_id, checkout_session_id, created_at, updated_at
                FROM pagos';
        $params = [];
        if (in_array($estado, ['iniciado', 'completado', 'fallido', 'cancelado'], true)) {
            $sql .= ' WHERE estado = ?';
            $params[] = $estado;
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($params);
        $pagos = array_map(static function (array $p): array {
            $p['id'] = (int) $p['id'];
            $p['pedido_id'] = (int) $p['pedido_id'];
            $p['id_cliente'] = (int) $p['id_cliente'];
            $p['monto'] = (float) $p['monto'];
            $p['descuento_regalo'] = (float) ($p['descuento_regalo'] ?? 0);
            return $p;
        }, $stmt->fetchAll());
        Http::json(200, ['pagos' => $pagos, 'total' => count($pagos)]);
    }

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
        $db = Db::get();
        $suma = $db->prepare(
            'SELECT COALESCE(SUM(monto), 0) AS cobrado FROM pagos
             WHERE estado = \'completado\' AND updated_at >= ? AND updated_at < ?'
        );
        $suma->execute([$inicio, $fin]);
        $uuids = $db->query('SELECT pedido_uuid FROM pagos WHERE estado = \'completado\'')->fetchAll();
        Http::json(200, [
            'mes' => $mes,
            'cobrado' => round((float) $suma->fetchColumn(), 2),
            'uuids' => array_values(array_unique(array_column($uuids, 'pedido_uuid'))),
        ]);
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
        $stmt = Db::get()->prepare(
            'SELECT proveedor, estado, COUNT(*) AS pagos, COALESCE(SUM(monto), 0) AS monto
             FROM pagos WHERE created_at >= ? AND created_at < ?
             GROUP BY proveedor, estado ORDER BY proveedor, estado'
        );
        $stmt->execute([$inicio, $fin]);
        Http::json(200, [
            'mes' => $mes,
            'medios' => array_map(static function (array $fila): array {
                return [
                    'proveedor' => $fila['proveedor'],
                    'estado' => $fila['estado'],
                    'pagos' => (int) $fila['pagos'],
                    'monto' => round((float) $fila['monto'], 2),
                ];
            }, $stmt->fetchAll()),
        ]);
    }

    public static function misMetodos(): never
    {
        $cliente = Guard::user();
        $stmt = Db::get()->prepare(
            'SELECT id, tipo, alias, marca, ultimos4, saldo, codigo
             FROM metodos_pago WHERE id_cliente = ? AND activa = 1 ORDER BY id DESC'
        );
        $stmt->execute([(int) $cliente['sub']]);
        $metodos = array_map(static function (array $fila): array {
            $fila['id'] = (int) $fila['id'];
            $fila['saldo'] = (float) $fila['saldo'];
            return $fila;
        }, $stmt->fetchAll());
        Http::json(200, ['metodos' => $metodos]);
    }

    public static function guardarTarjeta(): never
    {
        $cliente = Guard::user();
        $body = Http::body();
        $alias = trim(strip_tags((string) ($body['alias'] ?? '')));
        $marca = (string) ($body['marca'] ?? '');
        $ultimos4 = (string) ($body['ultimos4'] ?? '');
        if (!preg_match('/^[\p{L}\p{N} .\'-]{2,40}$/u', $alias)) {
            throw new HttpError(422, 'Ponle un nombre corto a la tarjeta');
        }
        if (!in_array($marca, ['visa', 'mastercard', 'otra'], true) || !preg_match('/^\d{4}$/', $ultimos4)) {
            throw new HttpError(422, 'Indica la marca y solo los últimos 4 dígitos');
        }
        $db = Db::get();
        $cuenta = $db->prepare('SELECT COUNT(*) FROM metodos_pago WHERE id_cliente = ? AND tipo = \'normal\' AND activa = 1');
        $cuenta->execute([(int) $cliente['sub']]);
        if ((int) $cuenta->fetchColumn() >= 6) {
            throw new HttpError(422, 'Ya tienes 6 tarjetas guardadas');
        }
        $db->prepare(
            'INSERT INTO metodos_pago (id_cliente, tipo, alias, marca, ultimos4, saldo) VALUES (?, \'normal\', ?, ?, ?, 0)'
        )->execute([(int) $cliente['sub'], $alias, $marca, $ultimos4]);
        Http::json(201, ['mensaje' => 'Tarjeta guardada. El número completo no se almacenó.']);
    }

    public static function canjearRegalo(): never
    {
        $cliente = Guard::user();
        $codigo = strtolower(trim((string) (Http::body()['codigo'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}$/', $codigo)) {
            throw new HttpError(422, 'El código de regalo tiene 8 caracteres');
        }
        $db = Db::get();
        $propia = $db->prepare('SELECT id FROM metodos_pago WHERE codigo = ? AND id_cliente = ? AND tipo = \'regalo\'');
        $propia->execute([$codigo, (int) $cliente['sub']]);
        if ($propia->fetch()) {
            Http::json(200, ['mensaje' => 'Esa tarjeta de regalo ya está en tu cuenta']);
        }
        $update = $db->prepare(
            'UPDATE metodos_pago SET id_cliente = ? WHERE codigo = ? AND tipo = \'regalo\' AND id_cliente IS NULL AND activa = 1'
        );
        $update->execute([(int) $cliente['sub'], $codigo]);
        if ($update->rowCount() === 0) {
            throw new HttpError(404, 'Ese código no está disponible');
        }
        Http::json(200, ['mensaje' => 'Tarjeta de regalo lista para tu próxima compra']);
    }

    public static function quitarMetodo(string $id): never
    {
        $cliente = Guard::user();
        $db = Db::get();
        $stmt = $db->prepare('SELECT id, tipo, saldo FROM metodos_pago WHERE id = ? AND id_cliente = ? AND activa = 1');
        $stmt->execute([(int) $id, (int) $cliente['sub']]);
        $fila = $stmt->fetch() ?: throw new HttpError(404, 'Tarjeta no encontrada');
        if ($fila['tipo'] === 'regalo' && (float) $fila['saldo'] > 0) {
            throw new HttpError(409, 'Esa tarjeta de regalo todavía tiene saldo');
        }
        $db->prepare('UPDATE metodos_pago SET activa = 0 WHERE id = ?')->execute([(int) $fila['id']]);
        Http::json(200, ['mensaje' => 'Tarjeta quitada']);
    }

    public static function emitirRegalo(): never
    {
        $admin = Guard::requirePermiso('pagos.ver');
        $body = Http::body();
        $alias = trim(strip_tags((string) ($body['alias'] ?? '')));
        $saldo = filter_var($body['saldo'] ?? null, FILTER_VALIDATE_FLOAT);
        if (!preg_match('/^[\p{L}\p{N} .\'-]{2,40}$/u', $alias)) {
            throw new HttpError(422, 'Indica para quién es la tarjeta');
        }
        if ($saldo === false || $saldo < 1 || $saldo > 2000) {
            throw new HttpError(422, 'El saldo debe estar entre Q1.00 y Q2000.00');
        }
        $saldo = round((float) $saldo, 2);
        $codigo = bin2hex(random_bytes(4));
        Db::get()->prepare(
            'INSERT INTO metodos_pago (id_cliente, tipo, alias, saldo, codigo) VALUES (NULL, \'regalo\', ?, ?, ?)'
        )->execute([$alias, $saldo, $codigo]);
        AuditClient::log('TARJETA_REGALO_EMITIDA', 'EXITO', Guard::actor($admin) + [
            'dato_nuevo' => ['alias' => $alias, 'saldo' => $saldo, 'codigo' => $codigo],
        ]);
        Http::json(201, ['codigo' => $codigo, 'alias' => $alias, 'saldo' => $saldo]);
    }

    // ---------------------------------------------------------------- Interno

    private static function completarPago(int $pagoId, int $pedidoId, string $uuid, ?string $intent, ?string $sessionId, array $actor, ?string $proveedor = null): void
    {
        $db = Db::get();
        $db->prepare(
            'UPDATE pagos SET estado = \'completado\', proveedor = COALESCE(?, proveedor),
             payment_intent_id = COALESCE(?, payment_intent_id),
             checkout_session_id = COALESCE(?, checkout_session_id) WHERE id = ?'
        )->execute([$proveedor, $intent, $sessionId, $pagoId]);

        [$status] = ServiceClient::call('POST', self::urlPedidos('/interno/pedidos/marcar-pagado'), [
            'pedido_uuid' => $uuid,
        ]);
        if ($status !== 200) {
            AuditClient::log('PAGO_CONFIRMADO', 'FALLO', $actor + [
                'pedido_id' => $pedidoId,
                'pago_id' => $pagoId,
                'dato_nuevo' => ['motivo' => 'no_se_pudo_marcar_pedido', 'pedido_uuid' => $uuid],
            ]);
            throw new HttpError(503, 'Pago registrado pero el pedido no se pudo actualizar');
        }

        AuditClient::log('PAGO_CONFIRMADO', 'EXITO', $actor + [
            'pedido_id' => $pedidoId,
            'pago_id' => $pagoId,
            'dato_nuevo' => [
                'pedido_uuid' => $uuid,
                'payment_intent_id' => $intent,
                'checkout_session_id' => $sessionId,
            ],
        ]);

        ServiceClient::call('POST', self::urlNotificaciones('/interno/enviar'), [
            'plantilla' => 'pago_confirmado',
            'pedido_uuid' => $uuid,
            'pedido_id' => $pedidoId,
        ]);
    }

    private static function uuidPedido(array $cuerpo): string
    {
        $uuid = trim((string) ($cuerpo['pedido_uuid'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            throw new HttpError(422, 'pedido_uuid inválido');
        }
        return $uuid;
    }

    private static function pagoDelPedido(string $uuid): ?array
    {
        $stmt = Db::get()->prepare(
            'SELECT id, id_cliente, estado, proveedor, monto, descuento_regalo, regalo_id, metodo_etiqueta FROM pagos WHERE pedido_uuid = ?'
        );
        $stmt->execute([$uuid]);
        $fila = $stmt->fetch();
        return $fila ?: null;
    }

    private static function rechazarSiEfectivo(string $uuid): void
    {
        $pago = self::pagoDelPedido($uuid);
        if ($pago && $pago['proveedor'] === 'efectivo' && $pago['estado'] === 'iniciado') {
            throw new HttpError(409, 'Este pedido se cobra en efectivo al entregar. Si quieres pagar en línea, cambia esa opción primero.');
        }
    }

    private static function upsertPagoIniciado(int $pedidoId, string $uuid, int $clienteId, float $monto, float $descuento = 0, ?string $etiqueta = null, int $regaloId = 0): int
    {
        $db = Db::get();
        $existente = $db->prepare('SELECT id, estado FROM pagos WHERE pedido_uuid = ?');
        $existente->execute([$uuid]);
        $fila = $existente->fetch();
        if ($fila && $fila['estado'] === 'completado') {
            throw new HttpError(409, 'Este pedido ya fue pagado');
        }
        $regalo = $regaloId > 0 ? $regaloId : null;
        if ($fila) {
            $db->prepare(
                'UPDATE pagos SET monto = ?, estado = \'iniciado\', descuento_regalo = ?, metodo_etiqueta = ?, regalo_id = ?, proveedor = \'stripe\' WHERE id = ?'
            )->execute([$monto, $descuento, $etiqueta, $regalo, $fila['id']]);
            return (int) $fila['id'];
        }
        $db->prepare(
            'INSERT INTO pagos (pedido_id, pedido_uuid, id_cliente, monto, descuento_regalo, metodo_etiqueta, regalo_id, estado, proveedor)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'iniciado\', \'stripe\')'
        )->execute([$pedidoId, $uuid, $clienteId, $monto, $descuento, $etiqueta, $regalo]);
        return (int) $db->lastInsertId();
    }

    /** @param array<string, mixed> $cuerpo @return array{pago_id:int,monto:float,descuento:float,etiqueta:?string,dedujo:bool,regalo_id:int} */
    private static function prepararCobro(int $actorId, int $pedidoId, string $uuid, int $duenoPedido, float $total, array $cuerpo): array
    {
        $pideTarjeta = (int) ($cuerpo['metodo_id'] ?? 0) > 0 || (int) ($cuerpo['tarjeta_regalo_id'] ?? 0) > 0;
        if ($pideTarjeta && $actorId !== $duenoPedido) {
            throw new HttpError(403, 'Las tarjetas guardadas son solo del cliente del pedido');
        }
        $db = Db::get();
        $existente = $db->prepare('SELECT id, estado, descuento_regalo, monto, metodo_etiqueta, regalo_id FROM pagos WHERE pedido_uuid = ?');
        $existente->execute([$uuid]);
        $fila = $existente->fetch();
        if ($fila && $fila['estado'] === 'completado') {
            throw new HttpError(409, 'Este pedido ya fue pagado');
        }
        if ($fila && (float) $fila['descuento_regalo'] > 0) {
            return [
                'pago_id' => (int) $fila['id'],
                'monto' => (float) $fila['monto'],
                'descuento' => (float) $fila['descuento_regalo'],
                'etiqueta' => $fila['metodo_etiqueta'],
                'dedujo' => false,
                'regalo_id' => (int) ($fila['regalo_id'] ?? 0),
            ];
        }

        $etiqueta = self::etiquetaNormal($actorId, $cuerpo['metodo_id'] ?? null);
        $regaloId = (int) ($cuerpo['tarjeta_regalo_id'] ?? 0);
        $descuento = 0.0;
        $db->beginTransaction();
        try {
            if ($regaloId > 0) {
                $stmt = $db->prepare(
                    'SELECT id, saldo, codigo FROM metodos_pago
                     WHERE id = ? AND id_cliente = ? AND tipo = \'regalo\' AND activa = 1 FOR UPDATE'
                );
                $stmt->execute([$regaloId, $actorId]);
                $regalo = $stmt->fetch() ?: throw new HttpError(404, 'Tarjeta de regalo no encontrada');
                $descuento = round(min((float) $regalo['saldo'], $total), 2);
                if ($descuento <= 0) {
                    throw new HttpError(422, 'Esa tarjeta de regalo no tiene saldo');
                }
                $db->prepare('UPDATE metodos_pago SET saldo = ROUND(saldo - ?, 2) WHERE id = ?')->execute([$descuento, $regaloId]);
                $textoRegalo = 'Regalo ' . $regalo['codigo'];
                $etiqueta = $etiqueta ? ($etiqueta . ' + ' . $textoRegalo) : $textoRegalo;
            }
            $monto = round($total - $descuento, 2);
            $pagoId = self::upsertPagoIniciado($pedidoId, $uuid, $duenoPedido, $monto, $descuento, $etiqueta, $regaloId);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'pago_id' => $pagoId,
            'monto' => round($total - $descuento, 2),
            'descuento' => $descuento,
            'etiqueta' => $etiqueta,
            'dedujo' => $descuento > 0,
            'regalo_id' => $regaloId,
        ];
    }

    private static function etiquetaNormal(int $clienteId, mixed $id): ?string
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $stmt = Db::get()->prepare(
            'SELECT marca, ultimos4 FROM metodos_pago WHERE id = ? AND id_cliente = ? AND tipo = \'normal\' AND activa = 1'
        );
        $stmt->execute([$id, $clienteId]);
        $fila = $stmt->fetch() ?: throw new HttpError(404, 'Tarjeta no encontrada');
        $marca = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'otra' => 'Tarjeta'][$fila['marca']] ?? 'Tarjeta';
        return $marca . ' •••• ' . $fila['ultimos4'];
    }

    private static function revertirRegalo(int $pagoId, int $regaloId, float $descuento, float $total): void
    {
        if ($regaloId <= 0 || $descuento <= 0) {
            return;
        }
        $db = Db::get();
        $db->prepare('UPDATE metodos_pago SET saldo = ROUND(saldo + ?, 2) WHERE id = ?')->execute([$descuento, $regaloId]);
        $db->prepare('UPDATE pagos SET monto = ?, descuento_regalo = 0, metodo_etiqueta = NULL WHERE id = ? AND estado = \'iniciado\'')
            ->execute([$total, $pagoId]);
    }

    private static function consultarPedido(string $uuid): array
    {
        [$status, $respuesta] = ServiceClient::call('POST', self::urlPedidos('/interno/pedidos/consulta'), [
            'pedido_uuid' => $uuid,
        ]);
        if ($status === 404) {
            throw new HttpError(404, 'Pedido no encontrado');
        }
        if ($status !== 200 || empty($respuesta['pedido'])) {
            throw new HttpError(503, 'No se pudo consultar el pedido');
        }
        return $respuesta['pedido'];
    }

    private static function stripeConfigurado(): bool
    {
        $key = Config::get('STRIPE_SECRET_KEY', '');
        return $key !== '' && str_starts_with($key, 'sk_') && !str_contains($key, 'xxx');
    }

    private static function stripeRequest(string $method, string $path, array $params): array
    {
        $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => Config::get('STRIPE_SECRET_KEY') . ':',
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => http_build_query(self::aplanar($params)),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            throw new HttpError(503, 'No se pudo contactar a Stripe: ' . curl_error($ch));
        }
        $data = json_decode($raw, true) ?: [];
        if ($code >= 400) {
            throw new HttpError(502, 'Stripe rechazó la solicitud', [
                'detalle' => $data['error']['message'] ?? 'error',
            ]);
        }
        return $data;
    }

    /** Stripe espera notación de corchetes en formularios anidados. */
    private static function aplanar(array $data, string $prefijo = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $clave = $prefijo === '' ? (string) $k : $prefijo . '[' . $k . ']';
            if (is_array($v)) {
                $out += self::aplanar($v, $clave);
            } elseif ($v !== null) {
                $out[$clave] = $v;
            }
        }
        return $out;
    }

    private static function verificarFirmaStripe(string $payload, string $header, string $secreto): array
    {
        $partes = [];
        foreach (explode(',', $header) as $item) {
            [$k, $v] = array_pad(explode('=', trim($item), 2), 2, null);
            if ($k && $v) {
                $partes[$k][] = $v;
            }
        }
        $timestamp = $partes['t'][0] ?? '';
        $firmas = $partes['v1'] ?? [];
        if ($timestamp === '' || !$firmas) {
            throw new RuntimeException('Cabecera incompleta');
        }
        if (abs(time() - (int) $timestamp) > 300) {
            throw new RuntimeException('Timestamp fuera de tolerancia');
        }
        $esperado = hash_hmac('sha256', $timestamp . '.' . $payload, $secreto);
        foreach ($firmas as $firma) {
            if (hash_equals($esperado, $firma)) {
                $evento = json_decode($payload, true);
                if (!is_array($evento)) {
                    throw new RuntimeException('JSON inválido');
                }
                return $evento;
            }
        }
        throw new RuntimeException('Ninguna firma coincidió');
    }

    private static function urlPedidos(string $ruta): string
    {
        return rtrim(Config::get('PEDIDOS_SERVICE_URL'), '/') . $ruta;
    }

    private static function urlNotificaciones(string $ruta): string
    {
        return rtrim(Config::get('NOTIFICACIONES_SERVICE_URL'), '/') . $ruta;
    }
}
