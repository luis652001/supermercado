<?php
declare(strict_types=1);

final class AuthService
{
    private const BCRYPT_COST = 12;
    private const MAX_INTENTOS = 5;
    private const MINUTOS_BLOQUEO = 15;
    // Hash de una cadena aleatoria: iguala el tiempo de respuesta cuando el email no existe.
    private const DUMMY_HASH = '$2y$12$zbnffOM3hfNNeVHMek.mBe7CpKDb5Z1RL8GsDiz74r5Z3vtyKmd.q';

    public static function registro(): never
    {
        $body = Http::body();
        $nombre = trim((string) ($body['nombre'] ?? ''));
        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if (!preg_match('/^[\p{L} .\'-]{2,100}$/u', $nombre)) {
            throw new HttpError(422, 'Nombre inválido (2 a 100 letras)');
        }
        self::validarEmail($email);
        self::validarPassword($password);

        $db = Db::get();
        $existe = $db->prepare('SELECT id FROM usuarios WHERE email = ?');
        $existe->execute([$email]);
        if ($existe->fetch()) {
            AuditClient::log('REGISTRO_CLIENTE', 'FALLO', [
                'usuario_email' => $email,
                'rol' => 'anonimo',
                'dato_nuevo' => ['motivo' => 'email_duplicado'],
            ]);
            throw new HttpError(409, 'No fue posible completar el registro con ese email');
        }

        // El rol nunca se toma del cliente: todo registro público es "cliente".
        $stmt = $db->prepare('INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES (?, ?, ?, \'cliente\')');
        $stmt->execute([$nombre, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])]);
        $id = (int) $db->lastInsertId();

        AuditClient::log('REGISTRO_CLIENTE', 'EXITO', [
            'usuario_id' => $id,
            'usuario_email' => $email,
            'rol' => 'cliente',
            'dato_nuevo' => ['id' => $id, 'nombre' => $nombre, 'email' => $email, 'rol' => 'cliente'],
        ]);

        Http::json(201, ['mensaje' => 'Registro exitoso', 'usuario' => ['id' => $id, 'nombre' => $nombre, 'email' => $email]]);
    }

    public static function login(): never
    {
        $body = Http::body();
        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        if ($email === '' || $password === '' || strlen($password) > 72) {
            throw new HttpError(422, 'Email y contraseña son requeridos');
        }

        $db = Db::get();
        $stmt = $db->prepare(
            'SELECT id, nombre, email, password_hash, rol, activo, intentos_fallidos,
                    (bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS bloqueado,
                    GREATEST(TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta), 0) AS segundos_bloqueo
             FROM usuarios WHERE email = ?'
        );
        $stmt->execute([mb_substr($email, 0, 120)]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            password_verify($password, self::DUMMY_HASH);
            AuditClient::log('LOGIN_CLIENTE_FALLIDO', 'FALLO', [
                'usuario_email' => mb_substr($email, 0, 120),
                'rol' => 'anonimo',
                'dato_nuevo' => ['motivo' => 'usuario_inexistente'],
            ]);
            throw new HttpError(401, 'Credenciales incorrectas');
        }

        $actor = ['usuario_id' => (int) $usuario['id'], 'usuario_email' => $usuario['email'], 'rol' => $usuario['rol']];

        if ((int) $usuario['bloqueado'] === 1) {
            AuditClient::log('LOGIN_CLIENTE_FALLIDO', 'FALLO', $actor + [
                'dato_nuevo' => ['motivo' => 'cuenta_bloqueada', 'segundos_restantes' => (int) $usuario['segundos_bloqueo']],
            ]);
            throw new HttpError(423, 'Cuenta bloqueada temporalmente por intentos fallidos', [
                'reintentar_en_minutos' => (int) ceil($usuario['segundos_bloqueo'] / 60),
            ]);
        }

        if (!(int) $usuario['activo'] || !password_verify($password, $usuario['password_hash'])) {
            $intentos = (int) $usuario['intentos_fallidos'] + 1;
            $bloquear = $intentos >= self::MAX_INTENTOS;

            $update = $db->prepare(
                $bloquear
                    ? 'UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NOW() + INTERVAL ' . self::MINUTOS_BLOQUEO . ' MINUTE WHERE id = ?'
                    : 'UPDATE usuarios SET intentos_fallidos = ? WHERE id = ?'
            );
            $update->execute($bloquear ? [$usuario['id']] : [$intentos, $usuario['id']]);

            AuditClient::log('LOGIN_CLIENTE_FALLIDO', 'FALLO', $actor + [
                'dato_anterior' => ['intentos_fallidos' => (int) $usuario['intentos_fallidos']],
                'dato_nuevo' => [
                    'motivo' => (int) $usuario['activo'] ? 'password_incorrecto' : 'cuenta_inactiva',
                    'intentos_fallidos' => $intentos,
                    'cuenta_bloqueada' => $bloquear,
                ],
            ]);
            throw new HttpError(401, 'Credenciales incorrectas');
        }

        $db->prepare('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_login = NOW() WHERE id = ?')
            ->execute([$usuario['id']]);

        if (password_needs_rehash($usuario['password_hash'], PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])) {
            $db->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]), $usuario['id']]);
        }

        $emitido = Guard::issueUserToken($usuario);
        AuditClient::log('LOGIN_CLIENTE_EXITOSO', 'EXITO', $actor);

        Http::json(200, [
            'token' => $emitido['token'],
            'tipo' => 'Bearer',
            'expira_en' => Guard::USER_TOKEN_TTL,
            'usuario' => [
                'id' => (int) $usuario['id'],
                'nombre' => $usuario['nombre'],
                'email' => $usuario['email'],
                'rol' => $usuario['rol'],
                'permisos' => Roles::permisosDe((string) $usuario['rol']),
            ],
        ]);
    }

    public static function logout(): never
    {
        $claims = Guard::user();
        TokenBlacklist::revoke($claims['jti'], $claims['exp'] - time());

        AuditClient::log('TOKEN_REVOCADO', 'EXITO', [
            'usuario_id' => (int) $claims['sub'],
            'usuario_email' => $claims['email'] ?? null,
            'rol' => $claims['rol'] ?? 'anonimo',
            'dato_nuevo' => ['jti' => $claims['jti'], 'motivo' => 'logout'],
        ]);

        Http::json(200, ['mensaje' => 'Sesión cerrada. El token fue revocado.']);
    }

    public static function perfil(): never
    {
        $claims = Guard::user();
        $stmt = Db::get()->prepare('SELECT id, nombre, email, telefono, rol, ultimo_login, created_at FROM usuarios WHERE id = ? AND activo = 1');
        $stmt->execute([(int) $claims['sub']]);
        $usuario = $stmt->fetch() ?: throw new HttpError(401, 'Usuario no disponible');
        $usuario['id'] = (int) $usuario['id'];

        Http::json(200, ['usuario' => $usuario, 'token_expira' => date('c', $claims['exp'])]);
    }

    public static function actualizarPerfil(): never
    {
        $claims = Guard::user();
        $body = Http::body();
        $id = (int) $claims['sub'];
        $db = Db::get();
        $stmt = $db->prepare('SELECT id, nombre, email, telefono, password_hash, rol FROM usuarios WHERE id = ? AND activo = 1');
        $stmt->execute([$id]);
        $actual = $stmt->fetch() ?: throw new HttpError(401, 'Usuario no disponible');

        $nombre = trim((string) ($body['nombre'] ?? ''));
        if (!preg_match('/^[\p{L} .\'-]{2,100}$/u', $nombre)) {
            throw new HttpError(422, 'Nombre inválido (2 a 100 letras)');
        }
        $telefono = preg_replace('/[\s-]/', '', (string) ($body['telefono'] ?? ''));
        if ($telefono !== '' && !preg_match('/^(\+502)?\d{8}$/', $telefono)) {
            throw new HttpError(422, 'Teléfono inválido (8 dígitos, opcional +502)');
        }

        $passwordNueva = (string) ($body['password_nueva'] ?? '');
        $hash = $actual['password_hash'];
        if ($passwordNueva !== '') {
            $actualClave = (string) ($body['password_actual'] ?? '');
            if (!password_verify($actualClave, $actual['password_hash'])) {
                throw new HttpError(401, 'La contraseña actual no coincide');
            }
            self::validarPassword($passwordNueva);
            $hash = password_hash($passwordNueva, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        }

        $db->prepare('UPDATE usuarios SET nombre = ?, telefono = ?, password_hash = ? WHERE id = ?')
            ->execute([$nombre, $telefono !== '' ? $telefono : null, $hash, $id]);

        TokenBlacklist::revoke($claims['jti'], $claims['exp'] - time());
        $emitido = Guard::issueUserToken([
            'id' => $id,
            'nombre' => $nombre,
            'email' => $actual['email'],
            'rol' => $actual['rol'],
        ]);

        AuditClient::log('PERFIL_ACTUALIZADO', 'EXITO', [
            'usuario_id' => $id,
            'usuario_email' => $actual['email'],
            'rol' => $actual['rol'],
            'dato_anterior' => ['nombre' => $actual['nombre'], 'telefono' => $actual['telefono']],
            'dato_nuevo' => ['nombre' => $nombre, 'telefono' => $telefono !== '' ? $telefono : null, 'cambio_password' => $passwordNueva !== ''],
        ]);

        Http::json(200, [
            'mensaje' => 'Datos actualizados',
            'token' => $emitido['token'],
            'usuario' => [
                'id' => $id,
                'nombre' => $nombre,
                'email' => $actual['email'],
                'telefono' => $telefono !== '' ? $telefono : null,
                'rol' => $actual['rol'],
                'permisos' => Roles::permisosDe((string) $actual['rol']),
            ],
        ]);
    }

    public static function accesoAdmin(): never
    {
        $claims = Guard::user();
        $actor = Guard::actor($claims);
        $rol = (string) ($claims['rol'] ?? '');

        if (!Roles::puede($rol, 'panel.acceso')) {
            AuditClient::log('ACCESO_ADMIN_PANEL', 'FALLO', $actor + ['dato_nuevo' => ['motivo' => 'rol_insuficiente']]);
            throw new HttpError(403, 'Acceso denegado');
        }

        AuditClient::log('ACCESO_ADMIN_PANEL', 'EXITO', $actor);
        Http::json(200, [
            'mensaje' => 'Acceso concedido al panel de administración',
            'rol' => $rol,
            'permisos' => Roles::permisosDe($rol),
        ]);
    }

    public static function adminListarUsuarios(): never
    {
        Guard::requirePermiso('usuarios.gestionar');
        $filas = Db::get()->query(
            'SELECT id, nombre, email, rol, activo, intentos_fallidos, bloqueado_hasta, ultimo_login, created_at
             FROM usuarios ORDER BY id DESC LIMIT 500'
        )->fetchAll();
        Http::json(200, [
            'usuarios' => array_map([self::class, 'normalizarUsuario'], $filas),
            'roles' => Roles::TODOS,
        ]);
    }

    public static function adminCrearUsuario(): never
    {
        $admin = Guard::requirePermiso('usuarios.gestionar');
        $body = Http::body();
        $nombre = trim((string) ($body['nombre'] ?? ''));
        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $rol = (string) ($body['rol'] ?? Roles::CLIENTE);

        if (!preg_match('/^[\p{L} .\'-]{2,100}$/u', $nombre)) {
            throw new HttpError(422, 'Nombre inválido');
        }
        self::validarEmail($email);
        self::validarPassword($password);
        if (!Roles::esValido($rol)) {
            throw new HttpError(422, 'Rol inválido: ' . implode(', ', Roles::TODOS));
        }
        if ($rol === Roles::ADMIN && ($admin['rol'] ?? '') !== Roles::ADMIN) {
            throw new HttpError(403, 'Solo un administrador puede crear otros administradores');
        }

        $db = Db::get();
        $existe = $db->prepare('SELECT id FROM usuarios WHERE email = ?');
        $existe->execute([$email]);
        if ($existe->fetch()) {
            throw new HttpError(409, 'Ya existe un usuario con ese email');
        }

        $db->prepare('INSERT INTO usuarios (nombre, email, password_hash, rol, activo) VALUES (?, ?, ?, ?, 1)')
            ->execute([$nombre, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]), $rol]);
        $id = (int) $db->lastInsertId();

        AuditClient::log('ACCESO_ADMIN_PANEL', 'EXITO', Guard::actor($admin) + [
            'dato_nuevo' => ['accion' => 'usuario_creado', 'id' => $id, 'email' => $email, 'rol' => $rol],
        ]);

        Http::json(201, ['usuario' => self::buscarUsuario($id)]);
    }

    public static function adminActualizarUsuario(string $id): never
    {
        $admin = Guard::requirePermiso('usuarios.gestionar');
        $id = (int) $id;
        $body = Http::body();
        $actual = self::buscarUsuario($id);

        if ($id === (int) $admin['sub'] && isset($body['rol']) && $body['rol'] !== Roles::ADMIN) {
            throw new HttpError(422, 'No puedes quitarte el rol admin a ti mismo');
        }
        if ($id === (int) $admin['sub'] && array_key_exists('activo', $body) && !filter_var($body['activo'], FILTER_VALIDATE_BOOL)) {
            throw new HttpError(422, 'No puedes suspenderte a ti mismo');
        }

        $sets = [];
        $params = [];
        if (array_key_exists('nombre', $body)) {
            $nombre = trim((string) $body['nombre']);
            if (!preg_match('/^[\p{L} .\'-]{2,100}$/u', $nombre)) {
                throw new HttpError(422, 'Nombre inválido');
            }
            $sets[] = 'nombre = ?';
            $params[] = $nombre;
        }
        if (array_key_exists('email', $body)) {
            $email = mb_strtolower(trim((string) $body['email']));
            self::validarEmail($email);
            $dup = Db::get()->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
            $dup->execute([$email, $id]);
            if ($dup->fetch()) {
                throw new HttpError(409, 'Email ya en uso');
            }
            $sets[] = 'email = ?';
            $params[] = $email;
        }
        if (array_key_exists('rol', $body)) {
            $rol = (string) $body['rol'];
            if (!Roles::esValido($rol)) {
                throw new HttpError(422, 'Rol inválido');
            }
            if ($rol === Roles::ADMIN && ($admin['rol'] ?? '') !== Roles::ADMIN) {
                throw new HttpError(403, 'Solo un administrador puede asignar el rol admin');
            }
            $sets[] = 'rol = ?';
            $params[] = $rol;
        }
        if (array_key_exists('password', $body) && $body['password'] !== '' && $body['password'] !== null) {
            self::validarPassword((string) $body['password']);
            $sets[] = 'password_hash = ?';
            $params[] = password_hash((string) $body['password'], PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        }
        if (!$sets) {
            throw new HttpError(422, 'No hay campos para actualizar');
        }

        $params[] = $id;
        Db::get()->prepare('UPDATE usuarios SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        AuditClient::log('ACCESO_ADMIN_PANEL', 'EXITO', Guard::actor($admin) + [
            'dato_anterior' => ['id' => $id, 'email' => $actual['email'], 'rol' => $actual['rol']],
            'dato_nuevo' => ['accion' => 'usuario_actualizado', 'id' => $id, 'campos' => array_keys($body)],
        ]);

        Http::json(200, ['usuario' => self::buscarUsuario($id)]);
    }

    /** Suspender = activo 0; reactivar = activo 1. */
    public static function adminSuspenderUsuario(string $id): never
    {
        $admin = Guard::requirePermiso('usuarios.gestionar');
        $id = (int) $id;
        if ($id === (int) $admin['sub']) {
            throw new HttpError(422, 'No puedes suspenderte a ti mismo');
        }
        $activo = filter_var(Http::body()['activo'] ?? false, FILTER_VALIDATE_BOOL);
        $stmt = Db::get()->prepare('UPDATE usuarios SET activo = ?, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?');
        $stmt->execute([$activo ? 1 : 0, $id]);
        if ($stmt->rowCount() === 0) {
            throw new HttpError(404, 'Usuario no encontrado');
        }

        AuditClient::log('ACCESO_ADMIN_PANEL', 'EXITO', Guard::actor($admin) + [
            'dato_nuevo' => ['accion' => $activo ? 'usuario_reactivado' : 'usuario_suspendido', 'id' => $id],
        ]);
        Http::json(200, ['usuario' => self::buscarUsuario($id)]);
    }

    /** Eliminación lógica: desactiva y marca el email como liberable con sufijo. */
    public static function adminEliminarUsuario(string $id): never
    {
        $admin = Guard::requirePermiso('usuarios.gestionar');
        $id = (int) $id;
        if ($id === (int) $admin['sub']) {
            throw new HttpError(422, 'No puedes eliminarte a ti mismo');
        }
        $actual = self::buscarUsuario($id);
        $emailLibre = 'eliminado+' . $id . '.' . time() . '@invalid.local';
        Db::get()->prepare('UPDATE usuarios SET activo = 0, email = ?, nombre = CONCAT(\'[eliminado] \', nombre) WHERE id = ?')
            ->execute([$emailLibre, $id]);

        AuditClient::log('ACCESO_ADMIN_PANEL', 'EXITO', Guard::actor($admin) + [
            'dato_anterior' => ['id' => $id, 'email' => $actual['email']],
            'dato_nuevo' => ['accion' => 'usuario_eliminado', 'id' => $id],
        ]);
        Http::json(200, ['mensaje' => 'Usuario eliminado (desactivado). No se borra el historial de auditoría.']);
    }

    public static function adminRolesPermisos(): never
    {
        Guard::requirePermiso('roles.ver');
        Http::json(200, Roles::catalogo());
    }

    private static function buscarUsuario(int $id): array
    {
        $stmt = Db::get()->prepare(
            'SELECT id, nombre, email, rol, activo, intentos_fallidos, bloqueado_hasta, ultimo_login, created_at
             FROM usuarios WHERE id = ?'
        );
        $stmt->execute([$id]);
        $fila = $stmt->fetch() ?: throw new HttpError(404, 'Usuario no encontrado');
        return self::normalizarUsuario($fila);
    }

    private static function normalizarUsuario(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'nombre' => $u['nombre'],
            'email' => $u['email'],
            'rol' => $u['rol'],
            'activo' => (bool) $u['activo'],
            'intentos_fallidos' => (int) $u['intentos_fallidos'],
            'bloqueado_hasta' => $u['bloqueado_hasta'],
            'ultimo_login' => $u['ultimo_login'],
            'created_at' => $u['created_at'],
            'permisos' => Roles::permisosDe((string) $u['rol']),
        ];
    }

    private static function validarEmail(string $email): void
    {
        if (strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, 'Email inválido');
        }
    }

    private static function validarPassword(string $password): void
    {
        $valida = strlen($password) >= 8
            && strlen($password) <= 72
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password)
            && preg_match('/\d/', $password);
        if (!$valida) {
            throw new HttpError(422, 'La contraseña debe tener de 8 a 72 caracteres e incluir mayúscula, minúscula y número');
        }
    }
}
