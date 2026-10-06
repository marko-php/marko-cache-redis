<?php

declare(strict_types=1);

namespace Marko\Cache\Redis\Tests;

use Marko\Cache\Redis\RedisConnection;
use Marko\Config\ConfigRepositoryInterface;
use Marko\Core\Container\Container;
use Marko\Testing\Fake\FakeConfigRepository;

/**
 * @param array<string, mixed> $config
 */
function createCacheRedisContainer(
    array $config,
): Container {
    $container = new Container();
    $container->instance(ConfigRepositoryInterface::class, new FakeConfigRepository($config));

    $module = require dirname(__DIR__) . '/module.php';

    foreach ($module['bindings'] as $id => $implementation) {
        $container->bind($id, $implementation);
    }

    foreach ($module['singletons'] ?? [] as $id) {
        $container->singleton($id);
    }

    return $container;
}

/**
 * @return array<string, mixed>
 */
function cacheRedisConfig(
    ?string $password = 'secret',
): array {
    return [
        'cache-redis.host' => 'redis.internal',
        'cache-redis.port' => 6380,
        'cache-redis.password' => $password,
        'cache-redis.database' => 3,
        'cache-redis.prefix' => 'app:cache:',
        'cache-redis.scheme' => 'tls',
    ];
}

describe('cache-redis module bindings', function (): void {
    it('ships a cache-redis config file with connection defaults', function (): void {
        $config = require dirname(__DIR__) . '/config/cache-redis.php';

        expect($config)->toHaveKeys(['host', 'port', 'password', 'database', 'prefix', 'scheme'])
            ->and($config['port'])->toBeInt()
            ->and($config['database'])->toBeInt()
            ->and($config['scheme'])->toBe('tcp');
    });

    it('passes the cache-redis scheme to RedisConnection', function (): void {
        $connection = createCacheRedisContainer(cacheRedisConfig())->get(RedisConnection::class);

        expect($connection->scheme)->toBe('tls');
    });

    it('resolves RedisConnection with values from cache-redis config', function (): void {
        $connection = createCacheRedisContainer(cacheRedisConfig())->get(RedisConnection::class);

        expect($connection)->toBeInstanceOf(RedisConnection::class)
            ->and($connection->host)->toBe('redis.internal')
            ->and($connection->port)->toBe(6380)
            ->and($connection->password)->toBe('secret')
            ->and($connection->database)->toBe(3)
            ->and($connection->prefix)->toBe('app:cache:');
    });

    it('treats an empty cache-redis password as no password', function (): void {
        $fromNull = createCacheRedisContainer(cacheRedisConfig(password: null))->get(RedisConnection::class);
        $fromEmpty = createCacheRedisContainer(cacheRedisConfig(password: ''))->get(RedisConnection::class);

        expect($fromNull->password)->toBeNull()
            ->and($fromEmpty->password)->toBeNull();
    });

    it('treats a cache-redis password removed by a null app override as no password', function (): void {
        $config = cacheRedisConfig();
        unset($config['cache-redis.password']);

        $connection = createCacheRedisContainer($config)->get(RedisConnection::class);

        expect($connection->password)->toBeNull()
            ->and($connection->host)->toBe('redis.internal');
    });

    it('resolves the same RedisConnection instance twice', function (): void {
        $container = createCacheRedisContainer(cacheRedisConfig());

        expect($container->get(RedisConnection::class))->toBe($container->get(RedisConnection::class));
    });
});
