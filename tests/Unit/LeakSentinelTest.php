<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Unit;

use Getpeppr\Exception\GetpepprException;
use Getpeppr\Sender;
use Getpeppr\Tests\Support\FakeHttpClient;
use Getpeppr\Tests\Support\FakeNetworkException;
use Getpeppr\Tests\Support\LeakProbe;
use Getpeppr\Tests\Support\Responses;
use Getpeppr\Tests\Support\TestClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * No exception of this package carries the API key, the document (raw or
 * base64), the sender, the invoice number, the idempotency key or the raw
 * response body: not in its message, its string form, a chained exception,
 * its captured stack trace arguments, a dump or a serialisation.
 *
 * phpunit.xml.dist sets zend.exception_ignore_args=0, the setting under which
 * PHP records argument values in traces.
 */
final class LeakSentinelTest extends TestCase
{
    private const INVOICE_NUMBER = 'SENTINELINV4242';
    private const SENDER = 'SENTINELSENDER77';
    private const IDEMPOTENCY_KEY = 'SENTINELIDEM99';
    private const BODY_MARKER = 'SENTINELBODY55';

    private static function ubl(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" '
            . 'xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cbc:ID>'
            . self::INVOICE_NUMBER . '</cbc:ID><cbc:Note>SENTINELUBLNOTE</cbc:Note></Invoice>';
    }

    /** @return list<string> */
    private static function sentinels(): array
    {
        return [
            'SENTINELKEY',
            self::INVOICE_NUMBER,
            'SENTINELUBLNOTE',
            substr(base64_encode(self::ubl()), 0, 40),
            substr(base64_encode(self::ubl()), 60, 40),
            self::SENDER,
            self::IDEMPOTENCY_KEY,
            self::BODY_MARKER,
        ];
    }

    /** @return iterable<string, array{\Closure(): (ResponseInterface|\Throwable)}> */
    public static function failures(): iterable
    {
        $body = [
            'error' => 'validation_failed',
            'message' => 'Invoice ' . self::INVOICE_NUMBER . ' violates rules ' . self::BODY_MARKER,
            'violations' => [['rule' => 'BR-01', 'message' => self::BODY_MARKER]],
        ];
        yield 'document rejected' => [static fn () => Responses::getpeppr(422, 'invoices_import.validation_failed', 'fix_request', false, $body)];
        yield 'account state' => [static fn () => Responses::getpeppr(422, 'invoices_import.platform_billing_not_active', 'contact_support', false, $body)];
        yield 'duplicate' => [static fn () => Responses::getpeppr(409, 'invoices_import.duplicate_document', 'fix_request', false, [
            'error' => 'duplicate_document',
            'message' => 'Invoice ' . self::INVOICE_NUMBER . ' was already sent 7s ago ' . self::BODY_MARKER,
            'duplicateOf' => 'e8ec77a9-0000-4000-8000-000000000000',
        ])];
        yield 'idempotency key reused' => [static fn () => Responses::getpeppr(422, 'idempotency.key_reused', 'fix_request', false, $body)];
        yield 'standard key with sender' => [static fn () => Responses::getpeppr(403, 'authz.scope_denied', 'fix_request', false, $body)];
        yield 'unknown remediation' => [static fn () => Responses::getpeppr(400, 'x.y', 'something_new', false, $body)];
        yield 'host 413' => [static fn () => Responses::host(413, 'too large ' . self::BODY_MARKER)];
        yield 'host 502' => [static fn () => Responses::host(502, 'bad gateway ' . self::BODY_MARKER)];
        yield 'host 404' => [static fn () => Responses::host(404, 'not found ' . self::BODY_MARKER)];
        yield 'unreadable success' => [static fn () => Responses::host(201, 'garbled ' . self::BODY_MARKER . ' ' . self::INVOICE_NUMBER)];
        yield 'transport failure carrying the request' => [static fn () => new FakeNetworkException(
            new Request('POST', 'https://api.getpeppr.dev/v1/invoices/import', ['Authorization' => 'Bearer ' . TestClient::API_KEY], base64_encode(self::ubl())),
            'cURL error 28 while sending ' . self::INVOICE_NUMBER . ' ' . self::BODY_MARKER,
        )];
    }

    /** @param \Closure(): (ResponseInterface|\Throwable) $answer */
    #[Test]
    #[DataProvider('failures')]
    public function an_import_failure_leaks_nothing(\Closure $answer): void
    {
        $http = new FakeHttpClient($answer());

        try {
            TestClient::with($http)->importInvoice(
                self::ubl(),
                'invoice-' . self::INVOICE_NUMBER . '.xml',
                '9925:BE0314595348',
                sender: Sender::externalSubTenant(self::SENDER),
                idempotencyKey: self::IDEMPOTENCY_KEY,
            );
            self::fail('expected an exception');
        } catch (GetpepprException $e) {
            self::assertNull($e->getPrevious());
            $rendered = LeakProbe::render($e);
            foreach (self::sentinels() as $sentinel) {
                self::assertStringNotContainsString($sentinel, $rendered, 'leaked: ' . $sentinel);
            }
        }
        // The request really carried the sentinels: the test is not vacuous.
        self::assertStringContainsString(base64_encode(self::ubl()), (string) $http->lastRequest()->getBody());
        self::assertStringContainsString(self::SENDER, (string) $http->lastRequest()->getBody());
    }

    #[Test]
    public function a_failed_read_leaks_neither_the_key_nor_the_body(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(404, 'invoices.not_found', 'fix_request', false, ['message' => self::BODY_MARKER]));

        try {
            TestClient::with($http)->getInvoice('b4c5d6e7');
            self::fail('expected an exception');
        } catch (GetpepprException $e) {
            $rendered = LeakProbe::render($e);
            self::assertStringNotContainsString('SENTINELKEY', $rendered);
            self::assertStringNotContainsString(self::BODY_MARKER, $rendered);
        }
    }

    #[Test]
    public function a_failed_legal_entity_creation_leaks_neither_the_payload_nor_the_body(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(409, 'legal_entities.identifier_already_in_use', 'fix_request', false, ['message' => self::BODY_MARKER]));

        try {
            TestClient::with($http)->createLegalEntity(['externalId' => self::SENDER, 'companyName' => self::INVOICE_NUMBER]);
            self::fail('expected an exception');
        } catch (GetpepprException $e) {
            $rendered = LeakProbe::render($e);
            foreach (['SENTINELKEY', self::SENDER, self::INVOICE_NUMBER, self::BODY_MARKER] as $sentinel) {
                self::assertStringNotContainsString($sentinel, $rendered, 'leaked: ' . $sentinel);
            }
        }
    }

    #[Test]
    public function the_package_writes_nothing_to_the_output(): void
    {
        $this->expectOutputString('');

        $http = new FakeHttpClient(Responses::getpeppr(422, 'invoices_import.validation_failed', 'fix_request', false));
        try {
            TestClient::with($http)->importInvoice(self::ubl(), 'i.xml', '9925:BE0314595348');
        } catch (GetpepprException) {
        }
    }
}
