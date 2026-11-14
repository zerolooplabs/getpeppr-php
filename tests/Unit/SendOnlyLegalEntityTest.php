<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Unit;

use Getpeppr\Exception\ContactSupportException;
use Getpeppr\Exception\RetryableException;
use Getpeppr\LegalEntity\Authorisation;
use Getpeppr\LegalEntity\LegalEntity;
use Getpeppr\LegalEntity\Receiving;
use Getpeppr\LegalEntity\Suspension;
use Getpeppr\Sender;
use Getpeppr\Tests\Support\FakeHttpClient;
use Getpeppr\Tests\Support\Responses;
use Getpeppr\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Send-only Legal Entities (GPR-1460): the fields `/v1/legal-entities` returns
 * since 2026-11-13, shaped like the gateway's projection
 * (buildSubTenantProjection / buildReceiving / buildSuspension).
 */
final class SendOnlyLegalEntityTest extends TestCase
{
    private const LIVE_KEY = 'sk_live_SENTINELKEY0123456789';

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function sendOnlyEntity(array $overrides = []): array
    {
        return array_merge([
            'id' => '5b2e7c10-9a4d-4e3f-8c21-6d7e8f9a0b1c',
            'externalId' => 'be-customer-7',
            'companyName' => 'Example BV',
            'country' => 'BE',
            'identifier' => ['scheme' => '0208', 'value' => '0000500044'],
            'networkRole' => 'send_only',
            'customerReference' => 'CTR-2026-0412',
            'status' => 'awaiting_authz',
            'networkDiscovery' => ['state' => 'pending', 'attempts' => 0],
            'receiving' => ['handledBy' => 'unknown', 'smpHost' => null, 'lastVerifiedAt' => null, 'lifecycleResponses' => false],
            'authorisation' => ['method' => null, 'reason' => null],
            'environment' => 'production',
            'createdAt' => '2026-11-14T09:00:00Z',
            'identifiers' => [],
        ], $overrides);
    }

    /** @param array<string, mixed> $body */
    private static function read(array $body): LegalEntity
    {
        $http = new FakeHttpClient(Responses::getpeppr(200, 'legal_entities.read', 'none', false, $body));

        return TestClient::with($http)->getLegalEntity('5b2e7c10-9a4d-4e3f-8c21-6d7e8f9a0b1c');
    }

    #[Test]
    public function it_creates_a_production_send_only_entity_and_sends_both_fields_verbatim(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(202, 'legal_entities.sub_tenant_created', 'none', false, self::sendOnlyEntity()));
        $payload = [
            'externalId' => 'be-customer-7',
            'companyName' => 'Example BV',
            'country' => 'BE',
            'address' => ['line1' => 'Rue de la Loi 1', 'city' => 'Bruxelles', 'zip' => '1000'],
            'identifiers' => [['scheme' => '0208', 'value' => '0000500044']],
            'networkRole' => 'send_only',
            'customerReference' => 'CTR-2026-0412',
        ];

        $entity = TestClient::with($http, self::LIVE_KEY)->createLegalEntity($payload);

        $request = $http->lastRequest();
        self::assertSame('Bearer ' . self::LIVE_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame($payload, json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR));
        self::assertTrue($entity->created);
        self::assertSame('send_only', $entity->networkRole);
        self::assertSame('CTR-2026-0412', $entity->customerReference);
        self::assertSame('awaiting_authz', $entity->status);
        self::assertInstanceOf(Receiving::class, $entity->receiving);
        self::assertSame('unknown', $entity->receiving->handledBy);
        self::assertNull($entity->receiving->smpHost);
        self::assertNull($entity->receiving->lastVerifiedAt);
        self::assertFalse($entity->receiving->lifecycleResponses);
        self::assertInstanceOf(Authorisation::class, $entity->authorisation);
        self::assertNull($entity->authorisation->method);
        self::assertNull($entity->authorisation->reason);
        self::assertNull($entity->suspension);
    }

    #[Test]
    public function an_account_without_send_only_is_an_account_state_refusal(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(403, 'legal_entities.send_only_not_enabled', 'contact_support', false));

        try {
            TestClient::with($http, self::LIVE_KEY)->createLegalEntity(['externalId' => 'be-customer-7', 'networkRole' => 'send_only']);
            self::fail('expected a refusal');
        } catch (ContactSupportException $e) {
            self::assertSame(403, $e->status);
            self::assertSame('legal_entities.send_only_not_enabled', $e->resultCode);
        }
    }

    #[Test]
    public function a_send_and_receive_entity_reads_getpeppr_as_its_receiver(): void
    {
        $entity = self::read(self::sendOnlyEntity([
            'networkRole' => 'send_and_receive',
            'customerReference' => null,
            'status' => 'active',
            'receiving' => ['handledBy' => 'getpeppr', 'smpHost' => null, 'lastVerifiedAt' => null, 'lifecycleResponses' => true],
            'authorisation' => null,
        ]));

        self::assertSame('send_and_receive', $entity->networkRole);
        self::assertNull($entity->customerReference);
        self::assertArrayHasKey('customerReference', $entity->toArray());
        self::assertSame('getpeppr', $entity->receiving?->handledBy);
        self::assertTrue($entity->receiving->lifecycleResponses);
        self::assertNull($entity->authorisation);
        self::assertNull($entity->suspension);
    }

    #[Test]
    public function a_suspended_entity_carries_every_reason_and_the_other_access_point(): void
    {
        $entity = self::read(self::sendOnlyEntity([
            'status' => 'suspended',
            'receiving' => ['handledBy' => 'elsewhere', 'smpHost' => 'smp.example-ap.test', 'lastVerifiedAt' => '2026-11-14T06:00:00.000Z', 'lifecycleResponses' => false],
            'suspension' => ['reasons' => ['receiving_registration_lost', 'blocked_by_getpeppr'], 'since' => '2026-11-12T06:00:00.000Z'],
            'authorisation' => ['method' => 'qualified_signature', 'reason' => null],
        ]));

        self::assertSame('suspended', $entity->status);
        self::assertInstanceOf(Suspension::class, $entity->suspension);
        self::assertSame(['receiving_registration_lost', 'blocked_by_getpeppr'], $entity->suspension->reasons);
        self::assertSame('2026-11-12T06:00:00.000Z', $entity->suspension->since);
        self::assertSame('elsewhere', $entity->receiving?->handledBy);
        self::assertSame('smp.example-ap.test', $entity->receiving->smpHost);
        self::assertSame('2026-11-14T06:00:00.000Z', $entity->receiving->lastVerifiedAt);
        self::assertSame('qualified_signature', $entity->authorisation?->method);
    }

    #[Test]
    public function an_unknown_value_of_an_open_vocabulary_reaches_the_caller(): void
    {
        $entity = self::read(self::sendOnlyEntity([
            'networkRole' => 'receive_only_someday',
            'status' => 'suspended',
            'receiving' => ['handledBy' => 'partner_ap', 'smpHost' => null, 'lastVerifiedAt' => null, 'lifecycleResponses' => false],
            'suspension' => ['reasons' => ['a_reason_added_later'], 'since' => '2026-11-12T06:00:00.000Z'],
            'authorisation' => ['method' => 'a_method_added_later', 'reason' => 'a_reason_added_later'],
        ]));

        self::assertSame('receive_only_someday', $entity->networkRole);
        self::assertSame('partner_ap', $entity->receiving?->handledBy);
        self::assertSame(['a_reason_added_later'], $entity->suspension?->reasons);
        self::assertSame('a_method_added_later', $entity->authorisation?->method);
        self::assertSame('a_reason_added_later', $entity->authorisation->reason);
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function absentOrMalformedRoles(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'not a string' => [true];
    }

    #[Test]
    #[DataProvider('absentOrMalformedRoles')]
    public function a_role_the_response_did_not_state_is_unknown_never_send_and_receive(mixed $role): void
    {
        $body = self::sendOnlyEntity(['networkRole' => $role]);
        if ($role === null) {
            unset($body['networkRole']);
        }

        self::assertNull(self::read($body)->networkRole);
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function malformedObjects(): iterable
    {
        $receiving = ['handledBy' => 'elsewhere', 'smpHost' => 'smp.example-ap.test', 'lastVerifiedAt' => '2026-11-14T06:00:00.000Z', 'lifecycleResponses' => false];
        yield 'receiving: not an object' => ['receiving', 'elsewhere'];
        yield 'receiving: handledBy missing' => ['receiving', array_diff_key($receiving, ['handledBy' => 0])];
        yield 'receiving: handledBy empty' => ['receiving', ['handledBy' => ''] + $receiving];
        yield 'receiving: lifecycleResponses not a boolean' => ['receiving', ['lifecycleResponses' => 'false'] + $receiving];
        yield 'receiving: smpHost missing' => ['receiving', array_diff_key($receiving, ['smpHost' => 0])];
        yield 'receiving: smpHost empty' => ['receiving', ['smpHost' => ''] + $receiving];
        yield 'receiving: lastVerifiedAt a number' => ['receiving', ['lastVerifiedAt' => 1731564000] + $receiving];

        $suspension = ['reasons' => ['receiving_registration_lost'], 'since' => '2026-11-12T06:00:00.000Z'];
        yield 'suspension: not an object' => ['suspension', true];
        yield 'suspension: no reasons' => ['suspension', ['reasons' => []] + $suspension];
        yield 'suspension: reasons not a list' => ['suspension', ['reasons' => ['a' => 'receiving_registration_lost']] + $suspension];
        yield 'suspension: one reason malformed among good ones' => ['suspension', ['reasons' => ['receiving_registration_lost', 42]] + $suspension];
        yield 'suspension: one reason empty' => ['suspension', ['reasons' => ['blocked_by_getpeppr', '']] + $suspension];
        yield 'suspension: since missing' => ['suspension', array_diff_key($suspension, ['since' => 0])];

        yield 'authorisation: not an object' => ['authorisation', 'manual_review'];
        yield 'authorisation: method missing' => ['authorisation', ['reason' => null]];
        yield 'authorisation: method empty' => ['authorisation', ['method' => '', 'reason' => null]];
        yield 'authorisation: reason a boolean' => ['authorisation', ['method' => null, 'reason' => false]];
    }

    #[Test]
    #[DataProvider('malformedObjects')]
    public function a_malformed_member_drops_the_whole_object_and_keeps_it_in_toArray(string $field, mixed $value): void
    {
        $entity = self::read(self::sendOnlyEntity(['status' => 'suspended', $field => $value]));

        self::assertNull($entity->{$field});
        self::assertSame($value, $entity->toArray()[$field]);
    }

    #[Test]
    public function the_daily_limit_of_a_send_only_entity_is_retryable_after_the_interval(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(429, 'invoices.send_only_daily_limit', 'retry_after', true, [], ['Retry-After' => '12181']));

        try {
            TestClient::with($http, self::LIVE_KEY)->importInvoice('<Invoice/>', 'invoice.xml', '0208:0000500143', sender: Sender::externalSubTenant('be-customer-7'), idempotencyKey: 'op-2026-11-14-0001');
            self::fail('expected a refusal');
        } catch (RetryableException $e) {
            self::assertSame('invoices.send_only_daily_limit', $e->resultCode);
            self::assertSame(12181, $e->retryAfter);
        }
    }

    #[Test]
    public function a_suspended_sender_is_an_account_state_refusal(): void
    {
        $http = new FakeHttpClient(Responses::getpeppr(422, 'invoices.sender_sending_suspended', 'contact_support', false));

        $this->expectException(ContactSupportException::class);
        TestClient::with($http, self::LIVE_KEY)->importInvoice('<Invoice/>', 'invoice.xml', '0208:0000500143', sender: Sender::externalSubTenant('be-customer-7'), idempotencyKey: 'op-2026-11-14-0002');
    }
}
