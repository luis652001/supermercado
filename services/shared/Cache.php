<?php
declare(strict_types=1);

/**
 * Caché en Redis. Si Redis falla, se consulta directo a la BD (degradación
 * controlada: el catálogo sigue funcionando, solo más lento).
 */
final class Cache
{
    /** @return array{0: mixed, 1: bool} [valor, vino_de_cache] */
    public static function remember(string $clave, int $ttl, callable $cargar): array
    {
        try {
            $guardado = RedisClient::get()->get($clave);
            if ($guardado !== false) {
                return [json_decode($guardado, true), true];
            }
        } catch (RedisException $e) {
            error_log('[Cache] Redis no disponible: ' . $e->getMessage());
            return [$cargar(), false];
        }

        $valor = $cargar();
        try {
            RedisClient::get()->setex($clave, $ttl, json_encode($valor, JSON_UNESCAPED_UNICODE));
        } catch (RedisException) {
        }
        return [$valor, false];
    }

    public static function version(string $clave): int
    {
        try {
            return (int) (RedisClient::get()->get($clave) ?: 0);
        } catch (RedisException) {
            return 0;
        }
    }

    public static function invalidar(string $claveVersion): void
    {
        try {
            RedisClient::get()->incr($claveVersion);
        } catch (RedisException $e) {
            error_log('[Cache] No se pudo invalidar: ' . $e->getMessage());
        }
    }
}
