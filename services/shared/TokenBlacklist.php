<?php
declare(strict_types=1);

/**
 * Lista negra de JWT en Redis. Si Redis no responde se rechaza el token
 * (fail-closed): no se puede garantizar que no haya sido revocado.
 */
final class TokenBlacklist
{
    private const PREFIX = 'jwt:revocado:';

    public static function revoke(string $jti, int $ttlSeconds): void
    {
        RedisClient::get()->setex(self::PREFIX . $jti, max(1, $ttlSeconds), '1');
    }

    public static function isRevoked(string $jti): bool
    {
        try {
            return (bool) RedisClient::get()->exists(self::PREFIX . $jti);
        } catch (RedisException $e) {
            error_log('[TokenBlacklist] Redis no disponible: ' . $e->getMessage());
            throw new HttpError(503, 'Validación de tokens no disponible temporalmente');
        }
    }
}
