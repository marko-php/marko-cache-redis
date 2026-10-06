<?php

declare(strict_types=1);

namespace Marko\Cache\Redis\Exceptions;

use Marko\Cache\Exceptions\CacheException;
use Throwable;

class RedisConnectionException extends CacheException
{
    public static function connectionFailed(
        string $host,
        int $port,
        Throwable $previous,
    ): self {
        return new self(
            message: "Could not connect to Redis at $host:$port: {$previous->getMessage()}",
            context: 'Opening the Redis connection for marko/cache-redis.',
            suggestion: 'Check the connection settings in config/cache-redis.php, or set the REDIS_HOST, REDIS_PORT, REDIS_PASSWORD and REDIS_CACHE_DATABASE environment variables, and make sure the Redis server is reachable.',
            previous: $previous,
        );
    }

    /**
     * @param list<string> $allowed
     */
    public static function invalidScheme(
        string $scheme,
        array $allowed,
    ): self {
        $allowedList = implode(', ', $allowed);

        return new self(
            message: "Invalid Redis connection scheme '$scheme' for marko/cache-redis",
            context: 'Creating the Redis connection for marko/cache-redis.',
            suggestion: "Set 'scheme' in config/cache-redis.php (or REDIS_SCHEME) to one of: $allowedList. Use tls for any Redis reached over a network you do not control.",
        );
    }
}
