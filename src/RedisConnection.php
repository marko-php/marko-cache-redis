<?php

declare(strict_types=1);

namespace Marko\Cache\Redis;

use Marko\Cache\Redis\Exceptions\RedisConnectionException;
use Predis\Client;
use Predis\ClientInterface;
use Predis\CommunicationException;

class RedisConnection
{
    private ?ClientInterface $client = null;

    public function __construct(
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 6379,
        public readonly ?string $password = null,
        public readonly int $database = 0,
        public readonly string $prefix = 'marko:cache:',
    ) {}

    /**
     * @throws RedisConnectionException
     */
    public function client(): ClientInterface
    {
        if ($this->client === null) {
            $this->client = $this->createClient();
        }

        return $this->client;
    }

    public function disconnect(): void
    {
        $this->client = null;
    }

    public function isConnected(): bool
    {
        return $this->client !== null;
    }

    /**
     * Create and connect the Predis client.
     *
     * @throws RedisConnectionException
     */
    protected function createClient(): ClientInterface
    {
        $parameters = [
            'scheme' => 'tcp',
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
        ];

        if ($this->password !== null) {
            $parameters['password'] = $this->password;
        }

        $client = new Client($parameters);
        $this->connect($client);

        return $client;
    }

    /**
     * Open the connection eagerly so a refused connection fails here, with
     * the configured host and port, instead of on the first cache command.
     *
     * @throws RedisConnectionException
     */
    protected function connect(
        ClientInterface $client,
    ): void {
        try {
            $client->connect();
        } catch (CommunicationException $e) {
            throw RedisConnectionException::connectionFailed($this->host, $this->port, $e);
        }
    }
}
