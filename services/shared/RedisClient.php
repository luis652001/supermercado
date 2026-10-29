<?php
declare(strict_types=1);

final class RedisClient
{
    private static ?Redis $redis = null;

    public static function get(): Redis
    {
        if (self::$redis === null) {
            $redis = new Redis();
            $redis->connect(Config::get('REDIS_HOST', 'redis'), (int) Config::get('REDIS_PORT', '6379'), 1.5);
            $redis->auth(Config::get('REDIS_PASSWORD'));
            self::$redis = $redis;
        }
        return self::$redis;
    }
}
