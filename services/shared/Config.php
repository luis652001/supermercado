<?php
declare(strict_types=1);

final class Config
{
    public static function get(string $key, ?string $default = null): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            if ($default === null) {
                throw new RuntimeException("Falta la variable de entorno $key");
            }
            return $default;
        }
        return $value;
    }

    public static function secret(string $key): string
    {
        $value = self::get($key);
        if (strlen($value) < 32) {
            throw new RuntimeException("$key debe tener al menos 32 caracteres");
        }
        return $value;
    }
}
