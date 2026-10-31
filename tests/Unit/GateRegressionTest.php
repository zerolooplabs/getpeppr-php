<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Unit;

use Getpeppr\Client;
use Getpeppr\Exception\GetpepprException;
use Getpeppr\Sender;
use Getpeppr\Tests\Support\FakeHttpClient;
use Getpeppr\Tests\Support\LeakProbe;
use Getpeppr\Tests\Support\Responses;
use Getpeppr\Tests\Support\TestClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/** Regressions for the findings of the independent delivery gate. */
final class GateRegressionTest extends TestCase
{
    private const UBL = '<?xml version="1.0" encoding="UTF-8"?><Invoice><ID>SENTINELGATEINV</ID></Invoice>';

    // Finding 1 — a PSR-17 factory that throws while the request is built.

    #[Test]
    public function a_stream_factory_failure_leaks_nothing_and_sends_nothing(): void
    {
        $nyholm = new Psr17Factory();
        $streams = new class implements StreamFactoryInterface {
            public function createStream(string $content = ''): StreamInterface
            {
                throw new \RuntimeException('stream factory down');
            }

            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                throw new \RuntimeException('unused');
            }

            public function createStreamFromResource($resource): StreamInterface
            {
                throw new \RuntimeException('unused');
            }
        };
        $http = new FakeHttpClient();
        $client = new Client(TestClient::API_KEY, httpClient: $http, requestFactory: $nyholm, streamFactory: $streams);

        try {
            $client->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348', sender: Sender::externalSubTenant('SENTINELGATESENDER'), idempotencyKey: 'SENTINELGATEIDEM');
            self::fail('expected a GetpepprException');
        } catch (GetpepprException $e) {
            self::assertNull($e->getPrevious());
            self::assertNull($e->status);
            self::assertStringContainsString('Nothing was sent', $e->getMessage());
            $rendered = LeakProbe::render($e);
            foreach (['SENTINELGATEINV', substr(base64_encode(self::UBL), 0, 40), 'SENTINELGATESENDER', 'SENTINELGATEIDEM', 'SENTINELKEY'] as $sentinel) {
                self::assertStringNotContainsString($sentinel, $rendered, 'leaked: ' . $sentinel);
            }
        }
        self::assertSame([], $http->requests);
    }

    #[Test]
    public function a_request_factory_failure_leaks_nothing_and_sends_nothing(): void
    {
        $nyholm = new Psr17Factory();
        $requests = new class implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                throw new \InvalidArgumentException('bad uri');
            }
        };
        $http = new FakeHttpClient();
        $client = new Client(TestClient::API_KEY, httpClient: $http, requestFactory: $requests, streamFactory: $nyholm);

        try {
            $client->getLegalEntity('SENTINELGATECUSTOMER');
            self::fail('expected a GetpepprException');
        } catch (GetpepprException $e) {
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('SENTINELGATECUSTOMER', LeakProbe::render($e));
            self::assertStringNotContainsString('SENTINELKEY', LeakProbe::render($e));
        }
        self::assertSame([], $http->requests);
    }

    #[Test]
    public function a_header_refused_by_the_message_implementation_leaks_nothing(): void
    {
        // A request whose withHeader() refuses the value and names it, as Guzzle's does.
        $requests = new class implements RequestFactoryInterface {
            public function createRequest(string $method, $uri): RequestInterface
            {
                return new class ($method, (string) $uri) extends \GuzzleHttp\Psr7\Request {
                    public function withHeader($header, $value): \Psr\Http\Message\MessageInterface
                    {
                        throw new \InvalidArgumentException('"' . (is_string($value) ? $value : '') . '" is not valid header value');
                    }
                };
            }
        };
        $nyholm = new Psr17Factory();
        $client = new Client(TestClient::API_KEY, httpClient: new FakeHttpClient(), requestFactory: $requests, streamFactory: $nyholm);

        try {
            $client->getInvoice('x');
            self::fail('expected a GetpepprException');
        } catch (GetpepprException $e) {
            self::assertStringNotContainsString('SENTINELKEY', LeakProbe::render($e));
        }
    }

    // Finding 3 — every result exposes toArray().

    #[Test]
    public function a_legal_entity_page_exposes_the_decoded_response(): void
    {
        $page = [
            'data' => [['id' => 'a', 'externalId' => 'x']],
            'pagination' => ['total_count' => 1, 'offset' => 0, 'limit' => 50, 'has_more' => false],
            'futureField' => ['kept' => true],
        ];
        $http = new FakeHttpClient(Responses::getpeppr(200, 'legal_entities.listed', 'none', false, $page));

        $list = TestClient::with($http)->listLegalEntities();

        self::assertSame($page, $list->toArray());
    }
}
