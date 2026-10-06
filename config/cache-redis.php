<?php

declare(strict_types=1);

use Marko\Config\Env;

return [
    'host' => Env::string('REDIS_HOST', '127.0.0.1'),
    'port' => Env::int('REDIS_PORT', 6379, min: 1, max: 65535),
    'password' => Env::nullableString('REDIS_PASSWORD'),
    'database' => Env::int('REDIS_CACHE_DATABASE', 0, min: 0),
    'prefix' => Env::string('CACHE_PREFIX', 'marko:cache:'),
];
