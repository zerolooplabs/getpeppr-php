<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Unit;

use Getpeppr\Client;
use Getpeppr\Exception\DuplicateDocumentException;
use Getpeppr\Exception\RetryableException;
use Getpeppr\Exception\TransportException;
use Getpeppr\Sender;
use Getpeppr\Tests\Support\FakeHttpClient;
use Getpeppr\Tests\Support\FakeNetworkException;
use Getpeppr\Tests\Support\Responses;
use Getpeppr\Tests\Support\TestClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImportInvoiceTest extends TestCase
{
    private const UBL = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Invoice xmlns=\"urn:oasis:names:specification:ubl:schema:xsd:Invoice-2\"><cbc:ID>TEST-1</cbc:ID></Invoice>\n";

    /** @param array<string, mixed> $body */
    private static function created(array $body = []): \Psr\Http\Message\ResponseInterface
    {
        return Responses::getpeppr(201, 'invoices_import.created', 'none', false, array_merge([
            'id' => 'b4c5d6e7-0000-4000-8000-000000000001',
            'providerDocumentId' => 'b4c5d6e7-0000-4000-8000-000000000001',
            'submissionId' => '0a1b2c3d-0000-4000-8000-000000000002',
            'status' => 'submitted',
            'transmission' => ['mode' => 'enveloped', 'bytePreservation' => 'not_guaranteed'],
        ], $body));
    }

    #[Test]
    public function it_posts_the_ubl_bytes_base64_encoded_and_unaltered(): void
    {
        $http = new FakeHttpClient(self::created());

        TestClient::with($http)->importInvoice(self::UBL, 'invoice-1.xml', '9925:BE0314595348');

        $request = $http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.getpeppr.dev/v1/invoices/import', (string) $request->getUri());
        self::assertSame('Bearer ' . TestClient::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('getpeppr-php/' . Client::VERSION, $request->getHeaderLine('User-Agent'));
        self::assertFalse($request->hasHeader('Idempotency-Key'));
        self::assertFalse($request->hasHeader('x-skip-validation'));

        $body = self::sentBody($http);
        self::assertSame(['file', 'filename', 'to'], array_keys($body));
        self::assertIsString($body['file']);
        self::assertSame(self::UBL, base64_decode($body['file'], true));
        self::assertSame('invoice-1.xml', $body['filename']);
        self::assertSame(['peppolId' => '9925:BE0314595348'], $body['to']);
    }

    #[Test]
    public function it_sends_for_a_sub_tenant_by_legal_entity_id(): void
    {
        $http = new FakeHttpClient(self::created());

        TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348', sender: Sender::legalEntity('cf3a8f4b-e688-4bee-b1d6-92221e9c8896'));

        $body = self::sentBody($http);
        self::assertSame(['legalEntityId' => 'cf3a8f4b-e688-4bee-b1d6-92221e9c8896'], $body['sender']);
    }

    #[Test]
    public function it_sends_for_a_sub_tenant_by_external_id(): void
    {
        $http = new FakeHttpClient(self::created());

        TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348', sender: Sender::externalSubTenant('customer_8412'));

        $body = self::sentBody($http);
        self::assertSame(['externalSubTenantId' => 'customer_8412'], $body['sender']);
    }

    #[Test]
    public function a_sender_selector_cannot_be_blank(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Sender::externalSubTenant('');
    }

    #[Test]
    public function it_forwards_the_idempotency_key_and_the_validation_opt_out(): void
    {
        $http = new FakeHttpClient(self::created());

        TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348', idempotencyKey: 'op_6f1c2e7a', skipValidation: true);

        $request = $http->lastRequest();
        self::assertSame('op_6f1c2e7a', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame('true', $request->getHeaderLine('x-skip-validation'));
    }

    #[Test]
    public function an_idempotency_key_that_is_not_a_header_value_is_refused_before_sending(): void
    {
        $http = new FakeHttpClient();

        try {
            TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348', idempotencyKey: "SENTINEL\r\nX-Injected: 1");
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('SENTINEL', $e->getMessage());
        }
        self::assertSame([], $http->requests);
    }

    #[Test]
    public function it_returns_the_send_result(): void
    {
        $http = new FakeHttpClient(self::created([
            'rulebook' => ['name' => 'peppol-bis-3', 'version' => '2026.5'],
            'createdAt' => '2026-10-31T09:00:00Z',
        ]));

        $result = TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');

        self::assertSame('b4c5d6e7-0000-4000-8000-000000000001', $result->providerDocumentId);
        self::assertSame('0a1b2c3d-0000-4000-8000-000000000002', $result->submissionId);
        self::assertSame('submitted', $result->status);
        self::assertNull($result->duplicateOf);
        self::assertSame([], $result->warnings);
        self::assertSame(['name' => 'peppol-bis-3', 'version' => '2026.5'], $result->rulebook);
        self::assertSame(['mode' => 'enveloped', 'bytePreservation' => 'not_guaranteed'], $result->transmission);
        self::assertSame('2026-10-31T09:00:00Z', $result->createdAt);
        self::assertSame(201, $result->httpStatus);
        self::assertSame('invoices_import.created', $result->resultCode);
        self::assertSame('req_0123456789abcdef0123456789abcdef', $result->requestId);
    }

    #[Test]
    public function it_exposes_duplicate_of_on_a_late_resend(): void
    {
        $http = new FakeHttpClient(self::created(['duplicateOf' => 'e8ec77a9-0000-4000-8000-000000000000']));

        $result = TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');

        self::assertSame('e8ec77a9-0000-4000-8000-000000000000', $result->duplicateOf);
    }

    #[Test]
    public function it_keeps_non_blocking_warnings_without_throwing(): void
    {
        $warning = ['ruleId' => 'recipient_endpoint_mismatch', 'message' => 'to.peppolId and the document disagree.'];
        $http = new FakeHttpClient(self::created(['warnings' => [$warning]]));

        $result = TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');

        self::assertSame([$warning], $result->warnings);
    }

    #[Test]
    public function the_provider_id_falls_back_to_the_deprecated_id(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(201, 'invoices_import.created', 'none', false, ['id' => 'abc-1', 'status' => 'submitted']));

        $result = TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');

        self::assertSame('abc-1', $result->providerDocumentId);
        self::assertNull($result->submissionId);
    }

    #[Test]
    public function it_never_retries_a_retryable_refusal_on_its_own(): void
    {
        $http = new FakeHttpClient(
            Responses::getpeppr(503, 'provider.retry_later', 'retry', true),
            self::created(),
        );

        try {
            TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348', idempotencyKey: 'op_1');
            self::fail('expected a RetryableException');
        } catch (RetryableException) {
        }
        self::assertCount(1, $http->requests);
    }

    #[Test]
    public function it_never_retries_a_transport_failure_on_its_own(): void
    {
        $request = new Request('POST', 'https://api.getpeppr.dev/v1/invoices/import');
        $http = new FakeHttpClient(new FakeNetworkException($request, 'timeout'), self::created());

        try {
            TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertNull($e->status);
        }
        self::assertCount(1, $http->requests);
    }

    #[Test]
    public function a_duplicate_refusal_surfaces_as_its_own_exception(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(409, 'invoices_import.duplicate_document', 'fix_request', false, [
            'error' => 'duplicate_document',
            'duplicateOf' => 'e8ec77a9-0000-4000-8000-000000000000',
        ]));

        try {
            TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');
            self::fail('expected a DuplicateDocumentException');
        } catch (DuplicateDocumentException $e) {
            self::assertSame('e8ec77a9-0000-4000-8000-000000000000', $e->duplicateOf);
            self::assertSame(409, $e->status);
        }
    }

    #[Test]
    public function a_success_whose_body_cannot_be_read_is_not_reported_as_a_failure_to_resend(): void
    {
        $http = new FakeHttpClient(Responses::host(201, 'not json'));

        try {
            TestClient::with($http)->importInvoice(self::UBL, 'i.xml', '9925:BE0314595348');
            self::fail('expected the root exception');
        } catch (\Getpeppr\Exception\GetpepprException $e) {
            self::assertSame(\Getpeppr\Exception\GetpepprException::class, $e::class);
            self::assertSame(201, $e->status);
            self::assertStringContainsString('may have been sent', $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private static function sentBody(FakeHttpClient $http): array
    {
        $body = json_decode((string) $http->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        return $body;
    }
}
