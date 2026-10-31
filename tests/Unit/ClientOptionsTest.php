<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Unit;

use Getpeppr\Client;
use Getpeppr\Tests\Support\FakeHttpClient;
use Getpeppr\Tests\Support\Responses;
use Getpeppr\Tests\Support\TestClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientOptionsTest extends TestCase
{
    #[Test]
    public function the_base_url_can_be_changed_for_tests(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(200, 'invoices.read', 'none', false, ['id' => 'x', 'status' => 'submitted']));
        $factory = new Psr17Factory();

        $client = new Client(TestClient::API_KEY, baseUrl: 'http://localhost:8080/v1/', httpClient: $http, requestFactory: $factory, streamFactory: $factory);
        $client->getInvoice('x');

        self::assertSame('http://localhost:8080/v1/invoices/x', (string) $http->lastRequest()->getUri());
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeBaseUrls(): iterable
    {
        yield 'plain http to a remote host' => ['http://api.getpeppr.dev/v1'];
        yield 'other scheme' => ['ftp://api.getpeppr.dev/v1'];
        yield 'no host' => ['https:///v1'];
        yield 'credentials' => ['https://user:pass@api.getpeppr.dev/v1'];
        yield 'query' => ['https://api.getpeppr.dev/v1?x=1'];
    }

    #[Test]
    #[DataProvider('unsafeBaseUrls')]
    public function an_unsafe_base_url_is_refused(string $baseUrl): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $factory = new Psr17Factory();
        new Client(TestClient::API_KEY, baseUrl: $baseUrl, httpClient: new FakeHttpClient(), requestFactory: $factory, streamFactory: $factory);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'header injection' => ["sk_sandbox_SENTINEL\r\nX-Evil: 1"];
        yield 'space' => ['sk_sandbox_SENTINEL abc'];
    }

    #[Test]
    #[DataProvider('unusableKeys')]
    public function an_unusable_api_key_is_refused_without_being_echoed(string $key): void
    {
        $factory = new Psr17Factory();
        try {
            new Client($key, httpClient: new FakeHttpClient(), requestFactory: $factory, streamFactory: $factory);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('SENTINEL', $e->getMessage());
            self::assertStringNotContainsString('SENTINEL', (string) $e);
        }
    }

    #[Test]
    public function dumping_the_client_does_not_show_the_api_key(): void
    {
        $client = TestClient::with(new FakeHttpClient());

        self::assertStringNotContainsString('SENTINELKEY', print_r($client, true));
        ob_start();
        var_dump($client);
        self::assertStringNotContainsString('SENTINELKEY', (string) ob_get_clean());
    }

    #[Test]
    public function the_client_cannot_be_serialised(): void
    {
        $this->expectException(\LogicException::class);

        serialize(TestClient::with(new FakeHttpClient()));
    }

    #[Test]
    public function it_discovers_a_psr18_client_when_none_is_given(): void
    {
        // guzzlehttp/guzzle and nyholm/psr7 are installed for development: discovery finds them.
        $client = new Client(TestClient::API_KEY);

        self::assertInstanceOf(Client::class, $client);
    }
}
