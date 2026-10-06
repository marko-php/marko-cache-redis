<?php

declare(strict_types=1);

use Marko\Cache\Config\CacheConfig;
use Marko\Cache\Exceptions\TamperedCacheValueException;
use Marko\Cache\Redis\Driver\RedisCacheDriver;
use Marko\Cache\Redis\RedisConnection;
use Marko\Cache\Signer\CacheValueSigner;
use Marko\Clock\SystemClock;
use Marko\Core\Support\ErrorCapture;
use Marko\Encryption\Config\EncryptionConfig;
use Marko\Testing\Fake\FakeConfigRepository;
use Predis\Client;

/*
 * Runs RedisCacheDriver against a real Redis server. Point it at one with
 * MARKO_TEST_REDIS_HOST / MARKO_TEST_REDIS_PORT (default 127.0.0.1:6379); the
 * tests skip with a clear reason when no server answers. CI provides one.
 */

function redisDriverIntegrationHost(): string
{
    return getenv('MARKO_TEST_REDIS_HOST') ?: '127.0.0.1';
}

function redisDriverIntegrationPort(): int
{
    return (int) (getenv('MARKO_TEST_REDIS_PORT') ?: 6379);
}

function redisDriverIntegrationSkipReason(): string
{
    return sprintf(
        'Redis is not reachable at %s:%d: %s. Start one (e.g. `docker run -p 6379:6379 redis:7-alpine`) or set MARKO_TEST_REDIS_HOST / MARKO_TEST_REDIS_PORT.',
        redisDriverIntegrationHost(),
        redisDriverIntegrationPort(),
        redisDriverIntegrationUnreachableReason() ?? 'no reason given',
    );
}

/**
 * Pings Redis once and returns why it is unreachable, or null when it answers.
 * Predis suppresses its own connect warning with @, which PHPUnit still records,
 * so the ping runs inside ErrorCapture to keep the skip probe warning-free.
 */
function redisDriverIntegrationUnreachableReason(): ?string
{
    static $probed = false;
    static $reason = null;

    if (!$probed) {
        $probed = true;

        try {
            ErrorCapture::run($warning, fn (): mixed => new Client([
                'host' => redisDriverIntegrationHost(),
                'port' => redisDriverIntegrationPort(),
                'timeout' => 0.5,
            ])->ping());
        } catch (Throwable $e) {
            $reason = $e->getMessage();
        }
    }

    return $reason;
}

function redisDriverIntegrationUnavailable(): bool
{
    return redisDriverIntegrationUnreachableReason() !== null;
}

function createIntegrationRedisConnection(): RedisConnection
{
    return new RedisConnection(
        host: redisDriverIntegrationHost(),
        port: redisDriverIntegrationPort(),
        database: 15,
        prefix: 'marko:test:' . bin2hex(random_bytes(6)) . ':',
    );
}

function createIntegrationRedisDriver(
    RedisConnection $connection,
): RedisCacheDriver {
    return new RedisCacheDriver(
        $connection,
        new CacheConfig(new FakeConfigRepository([
            'cache.path' => '/tmp/cache',
            'cache.default_ttl' => 3600,
            'cache.driver' => 'redis',
        ])),
        new CacheValueSigner(new EncryptionConfig(new FakeConfigRepository([
            'encryption.key' => 'integration-signing-key',
        ]))),
        new SystemClock(),
    );
}

describe('RedisCacheDriver against a real Redis server', function (): void {
    beforeEach(function (): void {
        if (redisDriverIntegrationUnavailable()) {
            return;
        }

        $this->connection = createIntegrationRedisConnection();
        $this->driver = createIntegrationRedisDriver($this->connection);
    });

    afterEach(function (): void {
        if (isset($this->driver)) {
            $this->driver->clear();
        }
    });

    it('reads an incremented counter back as an int from redis', function (): void {
        $this->driver->increment('counter', 60);
        $this->driver->increment('counter', 60);

        $item = $this->driver->getItem('counter');

        expect($this->driver->get('counter'))->toBe(2)
            ->and($item->get())->toBe(2)
            ->and($item->expiresAt())->not->toBeNull()
            ->and($this->driver->getMultiple(['counter']))->toBe(['counter' => 2]);
    })->skip(fn (): bool => redisDriverIntegrationUnavailable(), redisDriverIntegrationSkipReason());

    it('always leaves a ttl on the key after the first increment', function (): void {
        $client = $this->connection->client();
        $prefixedKey = $this->connection->prefix . 'counter';

        expect($this->driver->increment('counter', 60))->toBe(1)
            ->and($client->ttl($prefixedKey))->toBeGreaterThan(0)
            ->toBeLessThanOrEqual(60);
    })->skip(fn (): bool => redisDriverIntegrationUnavailable(), redisDriverIntegrationSkipReason());

    it('does not reset the ttl on a subsequent increment against redis', function (): void {
        $client = $this->connection->client();
        $prefixedKey = $this->connection->prefix . 'counter';

        $this->driver->increment('counter', 60);
        $client->expire($prefixedKey, 500);
        $this->driver->increment('counter', 60);

        expect($client->ttl($prefixedKey))->toBeGreaterThan(60);
    })->skip(fn (): bool => redisDriverIntegrationUnavailable(), redisDriverIntegrationSkipReason());

    it('restores the ttl of a counter left without one against redis', function (): void {
        $client = $this->connection->client();
        $prefixedKey = $this->connection->prefix . 'counter';
        $client->set($prefixedKey, '7');

        expect($this->driver->increment('counter', 60))->toBe(8)
            ->and($client->ttl($prefixedKey))->toBeGreaterThan(0);
    })->skip(fn (): bool => redisDriverIntegrationUnavailable(), redisDriverIntegrationSkipReason());

    it('still rejects a tampered non-integer value from redis', function (): void {
        $this->connection->client()->set($this->connection->prefix . 'key', serialize('unsigned'));

        expect(fn (): mixed => $this->driver->get('key'))
            ->toThrow(TamperedCacheValueException::class);
    })->skip(fn (): bool => redisDriverIntegrationUnavailable(), redisDriverIntegrationSkipReason());

    it('round-trips a signed value through redis', function (): void {
        $this->driver->set('key', ['a' => 1]);

        expect($this->driver->get('key'))->toBe(['a' => 1]);
    })->skip(fn (): bool => redisDriverIntegrationUnavailable(), redisDriverIntegrationSkipReason());

    it('rejects a signed value copied to another key in redis', function (): void {
        $client = $this->connection->client();
        $this->driver->set('role-alice', 'admin');
        $client->set($this->connection->prefix . 'role-bob', $client->get($this->connection->prefix . 'role-alice'));

        expect(fn (): mixed => $this->driver->get('role-bob'))
            ->toThrow(TamperedCacheValueException::class)
            ->and($this->driver->get('role-alice'))->toBe('admin');
    })->skip(fn (): bool => redisDriverIntegrationUnavailable(), redisDriverIntegrationSkipReason());

    it('clears every prefixed key with SCAN and leaves other keys alone', function (): void {
        $client = $this->connection->client();
        $outsideKey = 'marko:test:outside:' . bin2hex(random_bytes(6));
        $client->set($outsideKey, 'keep');

        $values = [];

        foreach (range(1, 2500) as $i) {
            $values["key$i"] = $i;
        }

        $this->driver->setMultiple($values);
        $this->driver->clear();

        $remaining = $client->scan('0', ['MATCH' => $this->connection->prefix . '*', 'COUNT' => 10000]);
        $outsideValue = $client->get($outsideKey);
        $client->del($outsideKey);

        expect($this->driver->has('key1'))->toBeFalse()
            ->and($this->driver->has('key2500'))->toBeFalse()
            ->and($remaining[1])->toBe([])
            ->and($outsideValue)->toBe('keep');
    })->skip(fn (): bool => redisDriverIntegrationUnavailable(), redisDriverIntegrationSkipReason());
});
