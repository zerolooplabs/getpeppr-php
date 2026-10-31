<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Unit;

use Getpeppr\Client;
use Getpeppr\Exception\DuplicateDocumentException;
use Getpeppr\Exception\GetpepprException;
use Getpeppr\Exception\TransportException;
use Getpeppr\Exception\UnreadableResponseException;
use Getpeppr\Sender;
use Getpeppr\Tests\Support\FakeHttpClient;
use Getpeppr\Tests\Support\LeakProbe;
use Getpeppr\Tests\Support\Responses;
use Getpeppr\Tests\Support\TestClient;
use GuzzleHttp\Psr7\HttpFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

/** Regressions for the findings of the first code review of v0.1. */
final class ReviewRegressionTest extends TestCase
{
    private const UBL = '<?xml version="1.0" encoding="UTF-8"?><Invoice><ID>SENTINELLATIN</ID></Invoice>';

    // F1 — a payload json_encode cannot encode must not leave it in a trace.

    #[Test]
    public function a_non_utf8_filename_is_refused_without_leaking_the_payload(): void
    {
        $http = new FakeHttpClient();
        try {
            TestClient::with($http)->importInvoice(self::UBL, "SENTINELFILE-fact\xE9.xml", '9925:BE0314595348');
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $rendered = LeakProbe::render($e);
            self::assertStringNotContainsString(substr(base64_encode(self::UBL), 0, 40), $rendered);
            self::assertStringNotContainsString('SENTINELFILE', $rendered);
            self::assertStringNotContainsString('SENTINELKEY', $rendered);
            self::assertNull($e->getPrevious());
        }
        self::assertSame([], $http->requests);
    }

    #[Test]
    public function a_non_utf8_legal_entity_is_refused_without_leaking_it(): void
    {
        $http = new FakeHttpClient();
        try {
            TestClient::with($http)->createLegalEntity(['externalId' => 'SENTINELEXT', 'companyName' => "Soci\xE9t\xE9 SENTINELNAME"]);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $rendered = LeakProbe::render($e);
            self::assertStringNotContainsString('SENTINELEXT', $rendered);
            self::assertStringNotContainsString('SENTINELNAME', $rendered);
        }
        self::assertSame([], $http->requests);
    }

    // F2 — a trailing newline must not pass header-value validation.

    /** @return iterable<string, array{string}> */
    public static function trailingNewlineKeys(): iterable
    {
        yield 'LF' => ["sk_sandbox_SENTINELNL\n"];
        yield 'CRLF' => ["sk_sandbox_SENTINELNL\r\n"];
    }

    #[Test]
    #[DataProvider('trailingNewlineKeys')]
    public function an_api_key_with_a_trailing_newline_is_refused(string $key): void
    {
        foreach ([new Psr17Factory(), new HttpFactory()] as $factory) {
            try {
                new Client($key, httpClient: new FakeHttpClient(), requestFactory: $factory, streamFactory: $factory);
                self::fail('expected a refusal with ' . $factory::class);
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('SENTINELNL', LeakProbe::render($e));
            }
        }
    }

    #[Test]
    public function an_idempotency_key_with_a_trailing_newline_is_refused_before_sending(): void
    {
        $http = new FakeHttpClient();
        try {
            TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348', idempotencyKey: "SENTINELIDEM\n");
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('SENTINELIDEM', LeakProbe::render($e));
        }
        self::assertSame([], $http->requests);
    }

    #[Test]
    public function a_guzzle_factory_sends_the_same_request_as_nyholm(): void
    {
        $factory = new HttpFactory();
        $http = new FakeHttpClient(Responses::getpeppr(201, 'invoices_import.created', 'none', false, ['id' => 'x', 'status' => 'submitted']));
        $client = new Client(TestClient::API_KEY, httpClient: $http, requestFactory: $factory, streamFactory: $factory);

        $client->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348', idempotencyKey: 'op_1');

        self::assertSame('op_1', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertSame('Bearer ' . TestClient::API_KEY, $http->lastRequest()->getHeaderLine('Authorization'));
    }

    // F3 / F11 — a body that cannot be read.

    private static function failingStream(): StreamInterface
    {
        return new class implements StreamInterface {
            public function __toString(): string { throw new \RuntimeException('Unable to read stream contents SENTINELSTREAM'); }
            public function close(): void {}
            public function detach() { return null; }
            public function getSize(): ?int { return null; }
            public function tell(): int { return 0; }
            public function eof(): bool { return false; }
            public function isSeekable(): bool { return false; }
            public function seek(int $offset, int $whence = SEEK_SET): void { throw new \RuntimeException('not seekable'); }
            public function rewind(): void { throw new \RuntimeException('not seekable'); }
            public function isWritable(): bool { return false; }
            public function write(string $string): int { throw new \RuntimeException('read only'); }
            public function isReadable(): bool { return true; }
            public function read(int $length): string { throw new \RuntimeException('Unable to read SENTINELSTREAM'); }
            public function getContents(): string { throw new \RuntimeException('Unable to read SENTINELSTREAM'); }
            public function getMetadata(?string $key = null) { return null; }
        };
    }

    #[Test]
    public function a_success_whose_body_cannot_be_read_is_reported_as_possibly_done(): void
    {
        $response = Responses::getpeppr(201, 'invoices_import.created', 'none', false)->withBody(self::failingStream());
        try {
            TestClient::with(new FakeHttpClient($response))->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');
            self::fail('expected an UnreadableResponseException');
        } catch (UnreadableResponseException $e) {
            self::assertInstanceOf(GetpepprException::class, $e);
            self::assertSame(201, $e->status);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('SENTINELSTREAM', LeakProbe::render($e));
        }
    }

    #[Test]
    public function a_duplicate_whose_body_cannot_be_read_is_still_a_duplicate(): void
    {
        $response = Responses::getpeppr(409, 'invoices_import.duplicate_document', 'fix_request', false)->withBody(self::failingStream());

        try {
            TestClient::with(new FakeHttpClient($response))->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');
            self::fail('expected a DuplicateDocumentException');
        } catch (DuplicateDocumentException $e) {
            self::assertNull($e->duplicateOf);
        }
    }

    #[Test]
    public function duplicate_of_is_read_across_partial_chunks(): void
    {
        $body = json_encode(['message' => str_repeat('x', 100), 'duplicateOf' => 'e8ec77a9-0000-4000-8000-000000000000'], JSON_THROW_ON_ERROR);
        $chunked = new class ($body) implements StreamInterface {
            private int $pos = 0;
            public function __construct(private readonly string $data) {}
            public function __toString(): string { return $this->data; }
            public function close(): void {}
            public function detach() { return null; }
            public function getSize(): ?int { return null; }
            public function tell(): int { return $this->pos; }
            public function eof(): bool { return $this->pos >= strlen($this->data); }
            public function isSeekable(): bool { return false; }
            public function seek(int $offset, int $whence = SEEK_SET): void { throw new \RuntimeException('not seekable'); }
            public function rewind(): void { throw new \RuntimeException('not seekable'); }
            public function isWritable(): bool { return false; }
            public function write(string $string): int { throw new \RuntimeException('read only'); }
            public function isReadable(): bool { return true; }
            public function read(int $length): string { $chunk = substr($this->data, $this->pos, min($length, 16)); $this->pos += strlen($chunk); return $chunk; }
            public function getContents(): string { $rest = substr($this->data, $this->pos); $this->pos = strlen($this->data); return $rest; }
            public function getMetadata(?string $key = null) { return null; }
        };
        $response = Responses::getpeppr(409, 'invoices_import.duplicate_document', 'fix_request', false)->withBody($chunked);

        try {
            TestClient::with(new FakeHttpClient($response))->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');
            self::fail('expected a DuplicateDocumentException');
        } catch (DuplicateDocumentException $e) {
            self::assertSame('e8ec77a9-0000-4000-8000-000000000000', $e->duplicateOf);
        }
    }

    // F8 — a 2xx that identifies nothing is not a success.

    /** @return iterable<string, array{string}> */
    public static function emptySuccessBodies(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'empty array' => ['[]'];
        yield 'captive portal json' => ['{"status":"ok"}'];
    }

    #[Test]
    #[DataProvider('emptySuccessBodies')]
    public function an_import_success_that_names_no_document_is_unreadable(string $body): void
    {
        $this->expectException(UnreadableResponseException::class);

        TestClient::with(new FakeHttpClient(new Response(201, [], $body)))->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');
    }

    #[Test]
    public function reads_that_identify_nothing_are_unreadable(): void
    {
        foreach ([
            static fn (Client $c) => $c->getInvoice('x'),
            static fn (Client $c) => $c->getLegalEntity('x'),
            static fn (Client $c) => $c->createLegalEntity(['externalId' => 'x']),
            static fn (Client $c) => $c->listLegalEntities(),
        ] as $i => $call) {
            try {
                $call(TestClient::with(new FakeHttpClient(new Response(200, [], '{"status":"ok"}'))));
                self::fail('expected an UnreadableResponseException for call #' . $i);
            } catch (UnreadableResponseException $e) {
                self::assertSame(200, $e->status);
            }
        }
    }

    // F7 — anything the HTTP client throws is a transport failure, unchained.

    #[Test]
    public function a_non_psr_failure_of_the_http_client_is_a_transport_failure(): void
    {
        $http = new FakeHttpClient(new \TypeError('cURL handle broken SENTINELTYPE'));

        try {
            TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertNull($e->status);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('SENTINELTYPE', LeakProbe::render($e));
        }
    }

    // F4 — customer identifiers stay out of traces.

    #[Test]
    public function customer_identifiers_stay_out_of_the_trace(): void
    {
        $notFound = static fn () => Responses::getpeppr(404, 'legal_entities.not_found', 'fix_request', false);
        $calls = [
            static fn (Client $c) => $c->getLegalEntity('SENTINELCUSTOMER'),
            static fn (Client $c) => $c->listLegalEntities(externalId: 'SENTINELCUSTOMER'),
            static fn (Client $c) => $c->getInvoice('SENTINELCUSTOMER'),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call(TestClient::with(new FakeHttpClient($notFound())));
                self::fail('expected an exception for call #' . $i);
            } catch (GetpepprException $e) {
                self::assertStringNotContainsString('SENTINELCUSTOMER', LeakProbe::render($e), 'call #' . $i);
            }
        }
    }

    // F5 — dot segments.

    /** @return iterable<string, array{string}> */
    public static function dotSegments(): iterable
    {
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
    }

    #[Test]
    #[DataProvider('dotSegments')]
    public function a_dot_segment_id_is_refused_before_sending(string $id): void
    {
        $http = new FakeHttpClient();
        foreach ([static fn (Client $c) => $c->getLegalEntity($id), static fn (Client $c) => $c->getInvoice($id)] as $call) {
            try {
                $call(TestClient::with($http));
                self::fail('expected a refusal');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $http->requests);
    }

    #[Test]
    public function an_id_is_percent_encoded_as_one_path_segment(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(200, 'legal_entities.read', 'none', false, ['id' => 'x']));

        TestClient::with($http)->getLegalEntity('a b%c/é');

        self::assertSame('/v1/legal-entities/a%20b%25c%2F%C3%A9', $http->lastRequest()->getUri()->getPath());
    }

    // F9 — messages fit every operation.

    #[Test]
    public function messages_do_not_claim_a_document_was_sent_on_a_non_send_operation(): void
    {
        $answers = [
            Responses::getpeppr(409, 'legal_entities.creation_in_progress', 'retry', true),
            Responses::getpeppr(403, 'authz.master_key_required', 'contact_support', false),
            new \Getpeppr\Tests\Support\FakeNetworkException(new \Nyholm\Psr7\Request('GET', 'https://x.test'), 'timeout'),
            Responses::host(502),
        ];
        foreach ($answers as $answer) {
            try {
                TestClient::with(new FakeHttpClient($answer))->getLegalEntity('x');
                self::fail('expected an exception');
            } catch (GetpepprException $e) {
                self::assertStringNotContainsString('document may have been sent', $e->getMessage());
                self::assertStringNotContainsString('document is not at fault', $e->getMessage());
                self::assertStringNotContainsString('Retry later with the same Idempotency-Key', $e->getMessage());
            }
        }
    }

    // F10 — no key in var_export, Sender not serialisable.

    #[Test]
    public function var_export_of_the_client_does_not_show_the_api_key(): void
    {
        self::assertStringNotContainsString('SENTINELKEY', var_export(TestClient::with(new FakeHttpClient()), true));
    }

    #[Test]
    public function a_sender_does_not_show_its_value(): void
    {
        $sender = Sender::externalSubTenant('SENTINELSENDER');

        self::assertStringNotContainsString('SENTINELSENDER', var_export($sender, true));
        self::assertStringNotContainsString('SENTINELSENDER', print_r($sender, true));
        $serialised = '';
        try {
            $serialised = serialize($sender);
        } catch (\Exception) {
        }
        self::assertStringNotContainsString('SENTINELSENDER', $serialised);
    }

    // Mutation survivors.

    #[Test]
    public function a_redirect_reaching_the_client_is_not_a_success(): void
    {
        $http = new FakeHttpClient(Responses::host(308, '', ['Location' => 'https://example.test/']));

        $this->expectException(GetpepprException::class);
        TestClient::with($http)->getInvoice('x');
    }

    #[Test]
    public function a_look_alike_localhost_still_needs_https(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(\InvalidArgumentException::class);
        new Client(TestClient::API_KEY, baseUrl: 'http://localhost.evil.test/v1', httpClient: new FakeHttpClient(), requestFactory: $factory, streamFactory: $factory);
    }

    #[Test]
    public function the_package_writes_nothing_to_the_error_log(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'gp-log');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);
        try {
            try {
                TestClient::with(new FakeHttpClient(Responses::getpeppr(422, 'invoices_import.validation_failed', 'fix_request', false)))
                    ->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');
            } catch (GetpepprException) {
            }
            self::assertSame('', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($log);
        }
    }
}
