<?php

declare(strict_types=1);

use Marko\Cache\Contracts\CacheInterface;
use Marko\Cache\Redis\Driver\RedisCacheDriver;
use Marko\Cache\Redis\RedisConnection;
use Marko\Config\ConfigRepositoryInterface;
use Marko\Core\Container\ContainerInterface;

return [
    'bindings' => [
        CacheInterface::class => RedisCacheDriver::class,
        RedisConnection::class => static function (ContainerInterface $container): RedisConnection {
            $config = $container->get(ConfigRepositoryInterface::class);
            // An app config that sets a key to null removes it (ConfigMerger
            // unsets null overrides), so a missing key also means "no password".
            $password = $config->has(key: 'cache-redis.password') ? $config->get(key: 'cache-redis.password') : null;

            return new RedisConnection(
                host: $config->getString(key: 'cache-redis.host'),
                port: $config->getInt(key: 'cache-redis.port'),
                password: $password === null || $password === '' ? null : (string) $password,
                database: $config->getInt(key: 'cache-redis.database'),
                prefix: $config->getString(key: 'cache-redis.prefix'),
                scheme: $config->getString(key: 'cache-redis.scheme'),
            );
        },
    ],
    'singletons' => [
        RedisConnection::class,
    ],
];
