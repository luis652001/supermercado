<?php
declare(strict_types=1);

/**
 * Db::get()  → MariaDB Master (todas las escrituras).
 * Db::read() → MariaDB Slave (lecturas de catálogo y reportes). Si el Slave
 *              no responde, se lee del Master para no detener las ventas.
 */
final class Db
{
    private static ?PDO $master = null;
    private static ?PDO $replica = null;
    private static string $fuenteLectura = 'master';

    public static function get(): PDO
    {
        return self::$master ??= self::conectar(Config::get('DB_HOST'));
    }

    public static function read(): PDO
    {
        $hostReplica = Config::get('DB_READ_HOST', '');
        if ($hostReplica === '') {
            self::$fuenteLectura = 'master';
            return self::get();
        }
        try {
            self::$replica ??= self::conectar($hostReplica);
            self::$fuenteLectura = 'slave';
            return self::$replica;
        } catch (PDOException $e) {
            error_log('[Db] Slave no disponible, leyendo del Master: ' . $e->getMessage());
            self::$fuenteLectura = 'master (failover)';
            return self::get();
        }
    }

    public static function fuenteLectura(): string
    {
        return self::$fuenteLectura;
    }

    private static function conectar(string $host): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            Config::get('DB_PORT', '3306'),
            Config::get('DB_NAME')
        );
        return new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]);
    }
}
