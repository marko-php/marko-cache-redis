<?php

declare(strict_types=1);

namespace Marko\Cache\Redis\Driver;

use DateTimeImmutable;
use Marko\Cache\CacheItem;
use Marko\Cache\Config\CacheConfig;
use Marko\Cache\Contracts\CacheInterface;
use Marko\Cache\Contracts\CacheItemInterface;
use Marko\Cache\Exceptions\InvalidKeyException;
use Marko\Cache\Redis\Exceptions\TamperedCacheValueException;
use Marko\Cache\Redis\RedisConnection;
use Marko\Cache\Redis\Signer\CacheValueSigner;

readonly class RedisCacheDriver implements CacheInterface
{
    /**
     * Increment a counter and apply its TTL in one atomic step.
     *
     * The TTL is set when the counter is created, and also whenever the key has
     * no TTL at all (-1), so a counter left without an expiry can never limit a
     * client forever. An existing TTL is never reset, keeping the window fixed.
     */
    private const string INCREMENT_SCRIPT = <<<'LUA'
        local value = redis.call('INCR', KEYS[1])
        local ttl = tonumber(ARGV[1])
        if ttl > 0 and (value == 1 or redis.call('TTL', KEYS[1]) == -1) then
            redis.call('EXPIRE', KEYS[1], ttl)
        end
        return value
        LUA;

    private const string INTEGER_PATTERN = '/\A-?\d+\z/';

    public function __construct(
        private RedisConnection $connection,
        private CacheConfig $config,
        private CacheValueSigner $cacheValueSigner,
    ) {}

    /**
     * @throws InvalidKeyException|TamperedCacheValueException
     */
    public function get(
        string $key,
        mixed $default = null,
    ): mixed {
        $this->validateKey($key);

        $data = $this->connection->client()->get($this->prefixKey($key));

        if ($data === null) {
            return $default;
        }

        return $this->decode($data);
    }

    /**
     * @throws InvalidKeyException|TamperedCacheValueException
     */
    public function set(
        string $key,
        mixed $value,
        ?int $ttl = null,
    ): bool {
        $this->validateKey($key);

        $ttl ??= $this->config->defaultTtl();
        $prefixedKey = $this->prefixKey($key);
        $envelope = $this->cacheValueSigner->wrap(serialize($value));

        if ($ttl > 0) {
            $this->connection->client()->setex($prefixedKey, $ttl, $envelope);
        } else {
            $this->connection->client()->set($prefixedKey, $envelope);
        }

        return true;
    }

    /**
     * @throws InvalidKeyException
     */
    public function has(
        string $key,
    ): bool {
        $this->validateKey($key);

        return $this->connection->client()->exists($this->prefixKey($key)) > 0;
    }

    /**
     * @throws InvalidKeyException
     */
    public function delete(
        string $key,
    ): bool {
        $this->validateKey($key);

        $this->connection->client()->del($this->prefixKey($key));

        return true;
    }

    public function clear(): bool
    {
        $client = $this->connection->client();
        $keys = $client->keys($this->connection->prefix . '*');

        if ($keys !== []) {
            $client->del($keys);
        }

        return true;
    }

    /**
     * @throws InvalidKeyException|TamperedCacheValueException
     */
    public function getItem(
        string $key,
    ): CacheItemInterface {
        $this->validateKey($key);

        $prefixedKey = $this->prefixKey($key);
        $client = $this->connection->client();
        $data = $client->get($prefixedKey);

        if ($data === null) {
            return CacheItem::miss($key);
        }

        $ttl = $client->ttl($prefixedKey);
        $expiresAt = $ttl > 0
            ? (new DateTimeImmutable())->setTimestamp(time() + $ttl)
            : null;

        return CacheItem::hit($key, $this->decode($data), $expiresAt);
    }

    /**
     * @throws InvalidKeyException|TamperedCacheValueException
     */
    public function getMultiple(
        array $keys,
        mixed $default = null,
    ): iterable {
        foreach ($keys as $key) {
            $this->validateKey($key);
        }

        $prefixedKeys = array_map(fn ($k) => $this->prefixKey($k), $keys);
        $raw = $this->connection->client()->mget(...$prefixedKeys);

        $result = [];

        foreach ($keys as $i => $key) {
            $value = $raw[$i] ?? null;

            if ($value === null) {
                $result[$key] = $default;
            } else {
                $result[$key] = $this->decode($value);
            }
        }

        return $result;
    }

    /**
     * @throws InvalidKeyException|TamperedCacheValueException
     */
    public function setMultiple(
        array $values,
        ?int $ttl = null,
    ): bool {
        foreach ($values as $key => $value) {
            $this->validateKey($key);
        }

        $ttl ??= $this->config->defaultTtl();
        $client = $this->connection->client();

        $client->pipeline(function ($pipe) use ($values, $ttl): void {
            foreach ($values as $key => $value) {
                $prefixedKey = $this->prefixKey($key);
                $envelope = $this->cacheValueSigner->wrap(serialize($value));

                if ($ttl > 0) {
                    $pipe->setex($prefixedKey, $ttl, $envelope);
                } else {
                    $pipe->set($prefixedKey, $envelope);
                }
            }
        });

        return true;
    }

    /**
     * @throws InvalidKeyException
     */
    public function deleteMultiple(
        array $keys,
    ): bool {
        foreach ($keys as $key) {
            $this->validateKey($key);
        }

        $prefixedKeys = array_map(fn ($k) => $this->prefixKey($k), $keys);

        $this->connection->client()->del(...$prefixedKeys);

        return true;
    }

    /**
     * @throws InvalidKeyException
     */
    public function increment(
        string $key,
        int $ttl,
    ): int {
        $this->validateKey($key);

        return (int) $this->connection->client()->eval(
            self::INCREMENT_SCRIPT,
            1,
            $this->prefixKey($key),
            $ttl,
        );
    }

    /**
     * Decode a raw Redis value.
     *
     * Counters written by increment() are bare integers (Redis INCR cannot write
     * an HMAC envelope), so they are returned as ints. Everything else must be a
     * signed envelope; it is verified before unserialize() ever sees it. A signed
     * envelope always starts with 64 hex characters followed by '.', so it can
     * never be mistaken for an integer.
     *
     * @throws TamperedCacheValueException
     */
    private function decode(
        string $data,
    ): mixed {
        if (preg_match(self::INTEGER_PATTERN, $data) === 1) {
            return (int) $data;
        }

        return unserialize($this->cacheValueSigner->verifyAndUnwrap($data));
    }

    /**
     * @throws InvalidKeyException
     */
    private function validateKey(
        string $key,
    ): void {
        if ($key === '') {
            throw InvalidKeyException::emptyKey();
        }

        if (!InvalidKeyException::isValidKey($key)) {
            throw InvalidKeyException::forKey($key);
        }
    }

    private function prefixKey(
        string $key,
    ): string {
        return $this->connection->prefix . $key;
    }
}
