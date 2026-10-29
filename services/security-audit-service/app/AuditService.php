<?php
declare(strict_types=1);

final class AuditService
{
    public const ACCIONES = [
        'LOGIN_CLIENTE_EXITOSO',
        'LOGIN_CLIENTE_FALLIDO',
        'REGISTRO_CLIENTE',
        'PEDIDO_CREADO',
        'PAGO_INICIADO',
        'PAGO_CONFIRMADO',
        'PAGO_FALLIDO',
        'PEDIDO_ESTADO_CAMBIADO',
        'PRECIO_MODIFICADO',
        'PRODUCTO_AGOTADO',
        'EXPORTACION_CLIENTES',
        'ACCESO_ADMIN_PANEL',
        'WEBHOOK_STRIPE_RECIBIDO',
        'TOKEN_REVOCADO',
        'LOTE_MERMA',
        'ENVASE_ACREDITADO',
        'RUTA_ACTUALIZADA',
        'CALIFICACION_PEDIDO',
        'CAPITAL_INYECTADO',
        'CAPITAL_RETIRADO',
    ];
    private const RESULTADOS = ['EXITO', 'FALLO', 'FRAUDE'];
    private const ROLES = ['admin', 'cliente', 'anonimo'];
    private const SERVICIOS_EMISORES = [
        'auth-service',
        'catalogo-service',
        'pedidos-service',
        'pagos-service',
        'notificaciones-service',
        'admin-panel',
    ];

    public static function registrar(): never
    {
        $servicio = Guard::service(self::SERVICIOS_EMISORES)['sub'];
        $evento = Http::body();

        $accion = (string) ($evento['accion'] ?? '');
        $resultado = (string) ($evento['resultado'] ?? '');
        if (!in_array($accion, self::ACCIONES, true)) {
            throw new HttpError(422, 'Código de acción no reconocido');
        }
        if (!in_array($resultado, self::RESULTADOS, true)) {
            throw new HttpError(422, 'Resultado inválido');
        }
        $rol = in_array($evento['rol'] ?? null, self::ROLES, true) ? $evento['rol'] : 'anonimo';
        $ip = filter_var($evento['ip_origen'] ?? '', FILTER_VALIDATE_IP) ?: null;

        $stmt = Db::get()->prepare(
            'INSERT INTO security_audit_logs
                (usuario_id, usuario_email, rol, accion, servicio, pedido_id, pago_id,
                 dato_anterior, dato_nuevo, ip_origen, user_agent, resultado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            self::entero($evento['usuario_id'] ?? null),
            self::texto($evento['usuario_email'] ?? null, 120),
            $rol,
            $accion,
            $servicio,
            self::entero($evento['pedido_id'] ?? null),
            self::entero($evento['pago_id'] ?? null),
            self::json($evento['dato_anterior'] ?? null),
            self::json($evento['dato_nuevo'] ?? null),
            $ip,
            self::texto($evento['user_agent'] ?? null, 255),
            $resultado,
        ]);

        Http::json(201, ['id' => (int) Db::get()->lastInsertId()]);
    }

    public static function listar(): never
    {
        Guard::requirePermiso('bitacora.ver');

        $where = [];
        $params = [];
        if (in_array($_GET['accion'] ?? null, self::ACCIONES, true)) {
            $where[] = 'accion = ?';
            $params[] = $_GET['accion'];
        }
        if (in_array($_GET['resultado'] ?? null, self::RESULTADOS, true)) {
            $where[] = 'resultado = ?';
            $params[] = $_GET['resultado'];
        }
        if (in_array($_GET['servicio'] ?? null, self::SERVICIOS_EMISORES, true)) {
            $where[] = 'servicio = ?';
            $params[] = $_GET['servicio'];
        }
        if (($usuarioId = self::entero($_GET['usuario_id'] ?? null)) !== null) {
            $where[] = 'usuario_id = ?';
            $params[] = $usuarioId;
        }
        if (($pedidoId = self::entero($_GET['pedido_id'] ?? null)) !== null) {
            $where[] = 'pedido_id = ?';
            $params[] = $pedidoId;
        }
        foreach (['desde' => '>=', 'hasta' => '<='] as $campo => $operador) {
            $fecha = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($_GET[$campo] ?? ''));
            if ($fecha !== false) {
                $where[] = "`timestamp` $operador ?";
                $params[] = $fecha->format('Y-m-d') . ($campo === 'desde' ? ' 00:00:00' : ' 23:59:59.999');
            }
        }

        $limite = min(max((int) ($_GET['limite'] ?? 100), 1), 500);
        $offset = max((int) ($_GET['offset'] ?? 0), 0);

        $sql = 'SELECT id, `timestamp`, usuario_id, usuario_email, rol, accion, servicio, pedido_id, pago_id,
                       dato_anterior, dato_nuevo, ip_origen, user_agent, resultado
                FROM security_audit_logs'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY id DESC LIMIT ? OFFSET ?';

        $stmt = Db::get()->prepare($sql);
        $posicion = 1;
        foreach ($params as $valor) {
            $stmt->bindValue($posicion++, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue($posicion++, $limite, PDO::PARAM_INT);
        $stmt->bindValue($posicion, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $eventos = array_map(static function (array $fila): array {
            $fila['dato_anterior'] = $fila['dato_anterior'] !== null ? json_decode($fila['dato_anterior'], true) : null;
            $fila['dato_nuevo'] = $fila['dato_nuevo'] !== null ? json_decode($fila['dato_nuevo'], true) : null;
            return $fila;
        }, $stmt->fetchAll());

        Http::json(200, ['eventos' => $eventos, 'limite' => $limite, 'offset' => $offset]);
    }

    public static function resumen(): never
    {
        Guard::requirePermiso('bitacora.ver');
        $db = Db::get();

        $porAccion = $db->query(
            'SELECT accion, resultado, COUNT(*) AS total
             FROM security_audit_logs GROUP BY accion, resultado ORDER BY accion, resultado'
        )->fetchAll();
        $totales = $db->query(
            'SELECT COUNT(*) AS total_registros, COUNT(DISTINCT accion) AS tipos_de_evento FROM security_audit_logs'
        )->fetch();

        Http::json(200, [
            'total_registros' => (int) $totales['total_registros'],
            'tipos_de_evento' => (int) $totales['tipos_de_evento'],
            'tipos_requeridos' => count(self::ACCIONES),
            'por_accion' => $porAccion,
        ]);
    }

    private static function entero(mixed $valor): ?int
    {
        $entero = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $entero === false ? null : $entero;
    }

    private static function texto(mixed $valor, int $max): ?string
    {
        if (!is_scalar($valor) || trim((string) $valor) === '') {
            return null;
        }
        return mb_substr(trim((string) $valor), 0, $max);
    }

    private static function json(mixed $valor): ?string
    {
        return is_array($valor) ? json_encode($valor, JSON_UNESCAPED_UNICODE) : null;
    }
}
