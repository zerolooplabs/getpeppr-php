<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Support;

use Getpeppr\Client;
use Nyholm\Psr7\Factory\Psr17Factory;

final class TestClient
{
    public const API_KEY = 'sk_sandbox_SENTINELKEY0123456789';

    public static function with(FakeHttpClient $http, string $apiKey = self::API_KEY): Client
    {
        $factory = new Psr17Factory();

        return new Client(
            apiKey: $apiKey,
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }
}
