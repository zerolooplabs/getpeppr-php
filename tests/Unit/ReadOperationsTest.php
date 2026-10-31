<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Unit;

use Getpeppr\Exception\ContactSupportException;
use Getpeppr\Exception\InvalidRequestException;
use Getpeppr\Tests\Support\FakeHttpClient;
use Getpeppr\Tests\Support\Responses;
use Getpeppr\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReadOperationsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function legalEntity(string $id = '7c9a1b34-2d5e-4f60-8a1b-9c2d3e4f5a6b'): array
    {
        return [
            'id' => $id,
            'externalId' => 'customer_8412',
            'companyName' => 'Test Probe GmbH',
            'country' => 'DE',
            'identifier' => ['scheme' => '9915', 'value' => 'TESTPROBE1'],
            'status' => 'no_registry',
            'networkDiscovery' => ['state' => 'pending', 'attempts' => 0],
            'environment' => 'sandbox',
            'createdAt' => '2026-10-31T09:00:00Z',
            'identifiers' => [],
        ];
    }

    #[Test]
    public function it_reads_a_document_by_id(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(200, 'invoices.read', 'none', false, [
            'id' => '0a1b2c3d-0000-4000-8000-000000000002',
            'submissionId' => '0a1b2c3d-0000-4000-8000-000000000002',
            'providerDocumentId' => 'b4c5d6e7-0000-4000-8000-000000000001',
            'number' => 'TEST-1',
            'status' => 'delivered',
            'createdAt' => '2026-10-31T09:00:00Z',
            'updatedAt' => '2026-10-31T09:00:30Z',
        ]));

        $invoice = TestClient::with($http)->getInvoice('b4c5d6e7-0000-4000-8000-000000000001');

        self::assertSame('GET', $http->lastRequest()->getMethod());
        self::assertSame('https://api.getpeppr.dev/v1/invoices/b4c5d6e7-0000-4000-8000-000000000001', (string) $http->lastRequest()->getUri());
        self::assertSame('', (string) $http->lastRequest()->getBody());
        self::assertSame('delivered', $invoice->status);
        self::assertSame('0a1b2c3d-0000-4000-8000-000000000002', $invoice->submissionId);
        self::assertSame('b4c5d6e7-0000-4000-8000-000000000001', $invoice->providerDocumentId);
        self::assertSame('TEST-1', $invoice->number);
        self::assertSame('2026-10-31T09:00:30Z', $invoice->updatedAt);
        self::assertSame('invoices.read', $invoice->resultCode);
        self::assertSame('delivered', $invoice->toArray()['status']);
    }

    #[Test]
    public function a_document_id_cannot_escape_its_path_segment(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(404, 'invoices.not_found', 'fix_request', false));

        try {
            TestClient::with($http)->getInvoice('../legal-entities?x=1#y');
        } catch (InvalidRequestException) {
        }

        self::assertSame('/v1/invoices/..%2Flegal-entities%3Fx%3D1%23y', $http->lastRequest()->getUri()->getPath());
    }

    #[Test]
    public function a_blank_document_id_is_refused_before_sending(): void
    {
        $http = new FakeHttpClient();

        $this->expectException(\InvalidArgumentException::class);
        TestClient::with($http)->getInvoice('');
    }

    #[Test]
    public function it_creates_a_legal_entity(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(202, 'legal_entities.sub_tenant_created', 'none', false, self::legalEntity()));
        $payload = [
            'externalId' => 'customer_8412',
            'companyName' => 'Test Probe GmbH',
            'country' => 'DE',
            'address' => ['line1' => '1 Test Street', 'city' => 'Berlin', 'zip' => '10115'],
            'identifier' => ['scheme' => '9915', 'value' => 'TESTPROBE1'],
        ];

        $entity = TestClient::with($http)->createLegalEntity($payload);

        $request = $http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.getpeppr.dev/v1/legal-entities', (string) $request->getUri());
        self::assertSame($payload, json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('7c9a1b34-2d5e-4f60-8a1b-9c2d3e4f5a6b', $entity->id);
        self::assertSame('customer_8412', $entity->externalId);
        self::assertSame('no_registry', $entity->status);
        self::assertSame('sandbox', $entity->environment);
        self::assertTrue($entity->created);
    }

    #[Test]
    public function replaying_an_existing_legal_entity_is_not_a_creation(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(200, 'legal_entities.sub_tenant_exists', 'none', false, self::legalEntity()));

        $entity = TestClient::with($http)->createLegalEntity(['externalId' => 'customer_8412']);

        self::assertFalse($entity->created);
    }

    #[Test]
    public function it_lists_legal_entities_with_filters(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(200, 'legal_entities.listed', 'none', false, [
            'data' => [self::legalEntity('a'), self::legalEntity('b')],
            'pagination' => ['total_count' => 12, 'offset' => 10, 'limit' => 2, 'has_more' => false],
        ]));

        $list = TestClient::with($http)->listLegalEntities(externalId: 'customer 8412&x', limit: 2, offset: 10);

        self::assertSame('externalId=customer%208412%26x&limit=2&offset=10', $http->lastRequest()->getUri()->getQuery());
        self::assertCount(2, $list->data);
        self::assertSame('b', $list->data[1]->id);
        self::assertNull($list->data[1]->created);
        self::assertSame(12, $list->totalCount);
        self::assertSame(10, $list->offset);
        self::assertSame(2, $list->limit);
        self::assertFalse($list->hasMore);
    }

    #[Test]
    public function it_lists_without_filters(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(200, 'legal_entities.listed', 'none', false, [
            'data' => [],
            'pagination' => ['total_count' => 0, 'offset' => 0, 'limit' => 50, 'has_more' => false],
        ]));

        TestClient::with($http)->listLegalEntities();

        self::assertSame('https://api.getpeppr.dev/v1/legal-entities', (string) $http->lastRequest()->getUri());
    }

    #[Test]
    public function it_reads_one_legal_entity(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(200, 'legal_entities.read', 'none', false, self::legalEntity()));

        $entity = TestClient::with($http)->getLegalEntity('7c9a1b34-2d5e-4f60-8a1b-9c2d3e4f5a6b');

        self::assertSame('https://api.getpeppr.dev/v1/legal-entities/7c9a1b34-2d5e-4f60-8a1b-9c2d3e4f5a6b', (string) $http->lastRequest()->getUri());
        self::assertSame('Test Probe GmbH', $entity->companyName);
        self::assertSame('DE', $entity->country);
    }

    #[Test]
    public function a_standard_key_on_a_platform_route_is_an_account_state_refusal(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(403, 'authz.master_key_required', 'contact_support', false));

        $this->expectException(ContactSupportException::class);
        TestClient::with($http)->listLegalEntities();
    }
}
