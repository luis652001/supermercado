<?php
declare(strict_types=1);

/**
 * Zero Trust: cada servicio valida el JWT en cada petición, venga del
 * cliente (token de usuario) o de otro microservicio (token de servicio).
 * Los tokens de usuario y de servicio se firman con secretos distintos.
 */
final class Guard
{
    public const USER_TOKEN_TTL = 3600;
    private const SERVICE_TOKEN_TTL = 60;

    public static function issueUserToken(array $usuario): array
    {
        $now = time();
        $claims = [
            'iss' => Jwt::ISSUER,
            'sub' => (string) $usuario['id'],
            'email' => $usuario['email'],
            'nombre' => $usuario['nombre'],
            'rol' => $usuario['rol'],
            'typ' => 'user',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + self::USER_TOKEN_TTL,
            'jti' => bin2hex(random_bytes(16)),
        ];
        return ['token' => Jwt::encode($claims, Config::secret('JWT_SECRET')), 'claims' => $claims];
    }

    public static function user(array $rolesPermitidos = []): array
    {
        $token = Http::bearerToken() ?? throw new HttpError(401, 'Token requerido');
        try {
            $claims = Jwt::decode($token, Config::secret('JWT_SECRET'));
        } catch (JwtException) {
            throw new HttpError(401, 'Token inválido o expirado');
        }
        if (($claims['typ'] ?? '') !== 'user' || empty($claims['jti']) || empty($claims['sub'])) {
            throw new HttpError(401, 'Token inválido');
        }
        if (TokenBlacklist::isRevoked($claims['jti'])) {
            throw new HttpError(401, 'Token revocado');
        }
        if ($rolesPermitidos && !in_array($claims['rol'] ?? '', $rolesPermitidos, true)) {
            throw new HttpError(403, 'Acceso denegado');
        }
        return $claims;
    }

    /** Exige un permiso RBAC (según Roles.php). */
    public static function requirePermiso(string $permiso): array
    {
        $claims = self::user();
        if (!Roles::puede((string) ($claims['rol'] ?? ''), $permiso)) {
            throw new HttpError(403, 'No tienes permiso: ' . $permiso);
        }
        return $claims;
    }

    /** Datos del actor para los eventos de auditoría. */
    public static function actor(array $claims): array
    {
        return [
            'usuario_id' => (int) $claims['sub'],
            'usuario_email' => $claims['email'] ?? null,
            'rol' => $claims['rol'] ?? 'anonimo',
        ];
    }

    public static function issueServiceToken(): string
    {
        $now = time();
        return Jwt::encode([
            'iss' => Jwt::ISSUER,
            'sub' => Config::get('SERVICE_NAME'),
            'typ' => 'service',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + self::SERVICE_TOKEN_TTL,
            'jti' => bin2hex(random_bytes(16)),
        ], Config::secret('JWT_SERVICE_SECRET'));
    }

    public static function service(array $serviciosPermitidos = []): array
    {
        $token = Http::bearerToken() ?? throw new HttpError(401, 'Token de servicio requerido');
        try {
            $claims = Jwt::decode($token, Config::secret('JWT_SERVICE_SECRET'));
        } catch (JwtException) {
            throw new HttpError(401, 'Token de servicio inválido');
        }
        if (($claims['typ'] ?? '') !== 'service' || empty($claims['sub'])) {
            throw new HttpError(401, 'Token de servicio inválido');
        }
        if ($serviciosPermitidos && !in_array($claims['sub'], $serviciosPermitidos, true)) {
            throw new HttpError(403, 'Servicio no autorizado');
        }
        return $claims;
    }
}
