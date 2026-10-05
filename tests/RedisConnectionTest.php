<?php

declare(strict_types=1);

namespace Marko\Cache\Redis\Tests;

use Marko\Cache\Redis\Exceptions\RedisConnectionException;
use Marko\Cache\Redis\RedisConnection;
use Predis\Client;
use Predis\ClientInterface;
use Predis\Connection\ConnectionException;
use Predis\Connection\Parameters;
use Predis\Connection\StreamConnection;

function createMockRedisClient(): ClientInterface
{
    /** @noinspection PhpMissingParentConstructorInspection - Test stub intentionally skips parent */
    return new class () extends Client
    {
        /** @noinspection PhpMissingParentConstructorInspection */
        public function __construct() {}
    };
}

function createTestableRedisConnection(
    ?ClientInterface $mockClient = null,
): RedisConnection {
    $mockClient ??= createMockRedisClient();

    return new class ($mockClient) extends RedisConnection
    {
        public function __construct(
            private readonly ClientInterface $mockClient,
        ) {
            parent::__construct();
        }

        protected function createClient(): ClientInterface
        {
            return $this->mockClient;
        }
    };
}

describe('RedisConnection', function (): void {
    it(
        'throws RedisConnectionException naming host, port and config file when the connection is refused',
        function (): void {
            $connection = new class () extends RedisConnection
            {
                public function __construct()
                {
                    parent::__construct(host: 'redis.internal', port: 6390);
                }

                protected function createClient(): ClientInterface
                {
                    /** @noinspection PhpMissingParentConstructorInspection - Test stub intentionally skips parent */
                    $client = new class () extends Client
                    {
                        /** @noinspection PhpMissingParentConstructorInspection */
                        public function __construct() {}

                        public function connect(): void
                        {
                            throw new ConnectionException(
                                new StreamConnection(new Parameters()),
                                'Connection refused [tcp://redis.internal:6390]',
                            );
                        }
                    };

                    $this->connect($client);

                    return $client;
                }
            };

            try {
                $connection->client();
                $this->fail('Expected RedisConnectionException');
            } catch (RedisConnectionException $e) {
                expect($e->getMessage())->toContain('redis.internal:6390')
                    ->and($e->getSuggestion())->toContain('config/cache-redis.php')
                    ->and($e->getSuggestion())->toContain('REDIS_HOST')
                    ->and($e->getPrevious())->not->toBeNull()
                    ->and($connection->isConnected())->toBeFalse();
            }
        },
    );

    it('creates RedisConnection with default configuration', function (): void {
        $connection = new RedisConnection();

        expect($connection)
            ->toBeInstanceOf(RedisConnection::class)
            ->and($connection->host)->toBe('127.0.0.1')
            ->and($connection->port)->toBe(6379)
            ->and($connection->password)->toBeNull()
            ->and($connection->database)->toBe(0)
            ->and($connection->prefix)->toBe('marko:cache:');
    });

    it('creates RedisConnection with custom host port password and database', function (): void {
        $connection = new RedisConnection(
            host: 'redis.example.com',
            port: 6380,
            password: 'secret',
            database: 2,
            prefix: 'custom:prefix:',
        );

        expect($connection->host)->toBe('redis.example.com')
            ->and($connection->port)->toBe(6380)
            ->and($connection->password)->toBe('secret')
            ->and($connection->database)->toBe(2)
            ->and($connection->prefix)->toBe('custom:prefix:');
    });

    it('lazily connects on first client call', function (): void {
        $connection = createTestableRedisConnection();

        // Before calling client(), should not be connected
        expect($connection->isConnected())->toBeFalse();

        // Call client() - this should trigger lazy connection
        $client = $connection->client();

        expect($connection->isConnected())->toBeTrue()
            ->and($client)->toBeInstanceOf(ClientInterface::class);
    });

    it('returns same client on subsequent calls', function (): void {
        $connection = createTestableRedisConnection();

        $client1 = $connection->client();
        $client2 = $connection->client();

        expect($client1)->toBe($client2);
    });

    it('reports connected status correctly', function (): void {
        $connection = createTestableRedisConnection();

        // Before connecting, should report not connected
        expect($connection->isConnected())->toBeFalse();

        // After client() call (which triggers connection), should report connected
        $connection->client();

        expect($connection->isConnected())->toBeTrue();
    });

    it('disconnects and clears client reference', function (): void {
        $connection = createTestableRedisConnection();

        // Connect by requesting a client
        $connection->client();
        expect($connection->isConnected())->toBeTrue();

        // Disconnect
        $connection->disconnect();

        expect($connection->isConnected())->toBeFalse();
    });

    it('reconnects after disconnect', function (): void {
        $connection = createTestableRedisConnection();

        // Connect
        $connection->client();
        expect($connection->isConnected())->toBeTrue();

        // Disconnect
        $connection->disconnect();
        expect($connection->isConnected())->toBeFalse();

        // Reconnect
        $connection->client();
        expect($connection->isConnected())->toBeTrue();
    });
});
