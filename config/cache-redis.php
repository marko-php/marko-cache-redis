<?php

declare(strict_types=1);

return [
    'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
    'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
    'password' => $_ENV['REDIS_PASSWORD'] ?? null,
    'database' => (int) ($_ENV['REDIS_CACHE_DATABASE'] ?? 0),
    'prefix' => $_ENV['CACHE_PREFIX'] ?? 'marko:cache:',
];
