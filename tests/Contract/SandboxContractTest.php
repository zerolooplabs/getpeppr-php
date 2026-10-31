<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Contract;

use Getpeppr\Client;
use Getpeppr\Exception\ContactSupportException;
use Getpeppr\Exception\DuplicateDocumentException;
use Getpeppr\Exception\IdempotencyKeyException;
use Getpeppr\Exception\InvalidRequestException;
use Getpeppr\Invoice\ImportResult;
use Getpeppr\LegalEntity\LegalEntity;
use Getpeppr\Sender;
use Getpeppr\Tests\Contract\Support\Evidence;
use Getpeppr\Tests\Contract\Support\PacedHttpClient;
use Getpeppr\Tests\Contract\Support\UblFactory;
use GuzzleHttp\Client as Guzzle;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Real calls against the getpeppr sandbox, read as a PHP developer receives them.
 *
 * Runs only when GETPEPPR_CONTRACT_SEND=1 and two sandbox keys are set:
 * GETPEPPR_SANDBOX_API_KEY (standard) and GETPEPPR_SANDBOX_MASTER_API_KEY
 * (master, platform account). It sends fictitious test documents to the
 * Belgian administration's Peppol TEST participant.
 */
final class SandboxContractTest extends TestCase
{
    private const OWN_SCHEME = '0204';
    private const OWN_ID = '10101010-STO-51';
    private const OWN_NAME = 'getpeppr n8n Sandbox Seller';

    private static ?Client $standard = null;
    private static ?Client $master = null;
    private static string $run = '';

    public static function setUpBeforeClass(): void
    {
        $standardKey = getenv('GETPEPPR_SANDBOX_API_KEY');
        $masterKey = getenv('GETPEPPR_SANDBOX_MASTER_API_KEY');
        if (getenv('GETPEPPR_CONTRACT_SEND') !== '1' || !is_string($standardKey) || !is_string($masterKey) || $standardKey === '' || $masterKey === '') {
            return;
        }
        foreach ([$standardKey, $masterKey] as $key) {
            if (!str_starts_with($key, 'sk_sandbox_')) {
                throw new \RuntimeException('Contract tests only run with sandbox keys.');
            }
        }
        // Precondition read outside the package: the key really is a sandbox one.
        $identity = (new Guzzle(['timeout' => 30]))->get(Client::DEFAULT_BASE_URL . '/identity', ['headers' => ['Authorization' => 'Bearer ' . $standardKey]]);
        $body = json_decode((string) $identity->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body) || ($body['environment'] ?? null) !== 'sandbox') {
            throw new \RuntimeException('GET /identity did not confirm the sandbox environment.');
        }

        self::$standard = new Client($standardKey, httpClient: new PacedHttpClient(new Guzzle(['timeout' => 60, 'http_errors' => false])));
        self::$master = new Client($masterKey, httpClient: new PacedHttpClient(new Guzzle(['timeout' => 60, 'http_errors' => false])));
        self::$run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(2));
        Evidence::record('run.start', ['run' => self::$run, 'identityEnvironment' => 'sandbox']);
    }

    protected function setUp(): void
    {
        if (self::$standard === null) {
            self::markTestSkipped('Set GETPEPPR_CONTRACT_SEND=1, GETPEPPR_SANDBOX_API_KEY and GETPEPPR_SANDBOX_MASTER_API_KEY to run the sandbox contract tests.');
        }
    }

    private static function standard(): Client
    {
        return self::$standard ?? throw new \LogicException('not configured');
    }

    private static function master(): Client
    {
        return self::$master ?? throw new \LogicException('not configured');
    }

    private static function number(string $suffix): string
    {
        return 'GPHP-' . self::$run . '-' . $suffix;
    }

    private static function key(string $suffix): string
    {
        return 'op-' . self::$run . '-' . $suffix . '-' . bin2hex(random_bytes(4));
    }

    /** @return array<string, mixed> */
    private static function ofResult(ImportResult $r): array
    {
        return ['httpStatus' => $r->httpStatus, 'resultCode' => $r->resultCode, 'requestId' => $r->requestId, 'providerDocumentId' => $r->providerDocumentId, 'submissionId' => $r->submissionId, 'status' => $r->status, 'duplicateOf' => $r->duplicateOf, 'warnings' => $r->warnings];
    }

    /** @return array{a: LegalEntity, b: LegalEntity} */
    #[Test]
    public function platform_sub_tenants_are_created_listed_and_read(): array
    {
        $entities = [];
        foreach (['a' => 'GETPEPPRPHPA', 'b' => 'GETPEPPRPHPB'] as $slot => $value) {
            $entity = self::master()->createLegalEntity([
                'externalId' => 'getpeppr-php-contract-' . $slot,
                'companyName' => 'getpeppr-php Contract Test ' . strtoupper($slot),
                'country' => 'BE',
                'address' => ['line1' => '1 Test Street', 'city' => 'Brussels', 'zip' => '1000'],
                'identifier' => ['scheme' => '9915', 'value' => $value],
            ]);
            self::assertContains($entity->httpStatus, [200, 202]);
            self::assertNotNull($entity->id);
            Evidence::record('legal_entity.create.' . $slot, ['httpStatus' => $entity->httpStatus, 'created' => $entity->created, 'resultCode' => $entity->resultCode, 'id' => $entity->id, 'status' => $entity->status]);
            $entities[$slot] = $entity;
        }

        $read = self::master()->getLegalEntity((string) $entities['a']->id);
        self::assertSame($entities['a']->id, $read->id);
        $list = self::master()->listLegalEntities(externalId: 'getpeppr-php-contract-a');
        self::assertCount(1, $list->data);
        self::assertSame($entities['a']->id, $list->data[0]->id);
        Evidence::record('legal_entity.read_and_list', ['readStatus' => $read->status, 'readResultCode' => $read->resultCode, 'listTotal' => $list->totalCount, 'listResultCode' => $list->resultCode]);

        return ['a' => $entities['a'], 'b' => $entities['b']];
    }

    /** @return array{result: ImportResult, ubl: string, number: string, key: string} */
    #[Test]
    public function an_own_invoice_made_with_num_num_is_accepted(): array
    {
        $number = self::number('NN');
        $key = self::key('NN');
        $ubl = UblFactory::numNum($number, self::OWN_SCHEME, self::OWN_ID, self::OWN_NAME);

        $result = self::standard()->importInvoice($ubl, $number . '.xml', UblFactory::RECIPIENT, idempotencyKey: $key);

        self::assertSame(201, $result->httpStatus);
        self::assertSame('invoices_import.created', $result->resultCode);
        self::assertNotNull($result->providerDocumentId);
        self::assertNull($result->duplicateOf);
        Evidence::record('import.own.num-num', ['number' => $number] + self::ofResult($result));

        return ['result' => $result, 'ubl' => $ubl, 'number' => $number, 'key' => $key];
    }

    #[Test]
    public function an_own_invoice_made_with_josemmo_is_accepted(): ImportResult
    {
        $number = self::number('JM');
        $result = self::standard()->importInvoice(
            UblFactory::josemmo($number, self::OWN_SCHEME, self::OWN_ID, self::OWN_NAME),
            $number . '.xml',
            UblFactory::RECIPIENT,
            idempotencyKey: self::key('JM'),
        );

        self::assertSame(201, $result->httpStatus);
        self::assertNotNull($result->providerDocumentId);
        Evidence::record('import.own.josemmo', ['number' => $number] + self::ofResult($result));

        return $result;
    }

    /** @param array{result: ImportResult, ubl: string, number: string, key: string} $first */
    #[Test]
    #[Depends('an_own_invoice_made_with_num_num_is_accepted')]
    public function replaying_the_same_key_and_body_creates_no_second_document(array $first): void
    {
        $replay = self::standard()->importInvoice($first['ubl'], $first['number'] . '.xml', UblFactory::RECIPIENT, idempotencyKey: $first['key']);

        self::assertSame(201, $replay->httpStatus);
        self::assertSame($first['result']->providerDocumentId, $replay->providerDocumentId);
        self::assertSame($first['result']->submissionId, $replay->submissionId);
        Evidence::record('import.replay.same_key', ['firstDocument' => $first['result']->providerDocumentId] + self::ofResult($replay));
    }

    /** @param array{result: ImportResult, ubl: string, number: string, key: string} $first */
    #[Test]
    #[Depends('an_own_invoice_made_with_num_num_is_accepted')]
    public function resending_the_same_invoice_under_another_key_is_a_duplicate(array $first): void
    {
        try {
            self::standard()->importInvoice($first['ubl'], $first['number'] . '.xml', UblFactory::RECIPIENT, idempotencyKey: self::key('NN-again'));
            self::fail('expected a DuplicateDocumentException');
        } catch (DuplicateDocumentException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('invoices_import.duplicate_document', $e->resultCode);
            self::assertSame($first['result']->providerDocumentId, $e->duplicateOf);
            self::assertStringNotContainsString($first['number'], $e->getMessage());
            self::assertStringNotContainsString($first['number'], (string) $e);
            Evidence::record('import.duplicate.409', ['firstDocument' => $first['result']->providerDocumentId, 'duplicateOf' => $e->duplicateOf, 'numberInMessage' => str_contains($e->getMessage(), $first['number'])] + Evidence::ofException($e));
        }
    }

    /** @param array{result: ImportResult, ubl: string, number: string, key: string} $first */
    #[Test]
    #[Depends('an_own_invoice_made_with_num_num_is_accepted')]
    public function reusing_a_key_for_another_body_is_an_idempotency_key_error(array $first): void
    {
        $other = self::number('NN-other');
        try {
            self::standard()->importInvoice(UblFactory::numNum($other, self::OWN_SCHEME, self::OWN_ID, self::OWN_NAME), $other . '.xml', UblFactory::RECIPIENT, idempotencyKey: $first['key']);
            self::fail('expected an IdempotencyKeyException');
        } catch (IdempotencyKeyException $e) {
            self::assertSame('idempotency.key_reused', $e->resultCode);
            Evidence::record('import.idempotency.key_reused', Evidence::ofException($e));
        }
    }

    #[Test]
    public function a_document_breaking_a_peppol_rule_is_a_request_to_fix(): void
    {
        $number = self::number('BAD');
        try {
            self::standard()->importInvoice(UblFactory::numNum($number, self::OWN_SCHEME, self::OWN_ID, self::OWN_NAME, withBuyerReference: false), $number . '.xml', UblFactory::RECIPIENT, idempotencyKey: self::key('BAD'));
            self::fail('expected an InvalidRequestException');
        } catch (InvalidRequestException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('invoices_import.validation_failed', $e->resultCode);
            self::assertFalse($e->retryable);
            self::assertSame('fix_request', $e->remediation);
            Evidence::record('import.validation_failed', Evidence::ofException($e));
        }
    }

    #[Test]
    public function a_standard_key_naming_a_sender_is_refused(): void
    {
        $number = self::number('STD-SENDER');
        try {
            self::standard()->importInvoice(UblFactory::numNum($number, '9915', 'GETPEPPRPHPA', 'x'), $number . '.xml', UblFactory::RECIPIENT, sender: Sender::externalSubTenant('getpeppr-php-contract-a'));
            self::fail('expected an InvalidRequestException');
        } catch (InvalidRequestException $e) {
            self::assertSame(403, $e->status);
            self::assertSame('authz.scope_denied', $e->resultCode);
            Evidence::record('import.standard_key_with_sender', Evidence::ofException($e));
        }
    }

    #[Test]
    public function a_standard_key_on_a_platform_route_is_an_account_state_refusal(): void
    {
        try {
            self::standard()->listLegalEntities();
            self::fail('expected a ContactSupportException');
        } catch (ContactSupportException $e) {
            self::assertSame(403, $e->status);
            self::assertSame('authz.master_key_required', $e->resultCode);
            self::assertFalse($e->retryable);
            Evidence::record('legal_entities.standard_key', Evidence::ofException($e));
        }
    }

    #[Test]
    public function an_unknown_document_is_not_found(): void
    {
        try {
            self::standard()->getInvoice('00000000-0000-4000-8000-' . bin2hex(random_bytes(6)));
            self::fail('expected an InvalidRequestException');
        } catch (InvalidRequestException $e) {
            self::assertSame(404, $e->status);
            Evidence::record('invoice.read.unknown', Evidence::ofException($e));
        }
    }

    /**
     * @param array{a: LegalEntity, b: LegalEntity} $entities
     * @return array{a: ImportResult, b: ImportResult}
     */
    #[Test]
    #[Depends('platform_sub_tenants_are_created_listed_and_read')]
    public function two_sub_tenants_send_the_same_invoice_number_with_two_operation_keys(array $entities): array
    {
        $number = self::number('SAME');

        $a = self::master()->importInvoice(
            UblFactory::numNum($number, '9915', 'GETPEPPRPHPA', 'getpeppr-php Contract Test A'),
            $number . '.xml',
            UblFactory::RECIPIENT,
            sender: Sender::externalSubTenant('getpeppr-php-contract-a'),
            idempotencyKey: self::key('SAME-a'),
        );
        $b = self::master()->importInvoice(
            UblFactory::josemmo($number, '9915', 'GETPEPPRPHPB', 'getpeppr-php Contract Test B'),
            $number . '.xml',
            UblFactory::RECIPIENT,
            sender: Sender::legalEntity((string) $entities['b']->id),
            idempotencyKey: self::key('SAME-b'),
        );

        self::assertSame(201, $a->httpStatus);
        self::assertSame(201, $b->httpStatus);
        self::assertNotSame($a->providerDocumentId, $b->providerDocumentId);
        Evidence::record('import.sub_tenant.a.num-num', ['number' => $number] + self::ofResult($a));
        Evidence::record('import.sub_tenant.b.josemmo', ['number' => $number] + self::ofResult($b));

        return ['a' => $a, 'b' => $b];
    }

    /**
     * @param array{result: ImportResult, ubl: string, number: string, key: string} $numNum
     * @param array{a: ImportResult, b: ImportResult} $subTenants
     */
    #[Test]
    #[Depends('an_own_invoice_made_with_num_num_is_accepted')]
    #[Depends('an_own_invoice_made_with_josemmo_is_accepted')]
    #[Depends('two_sub_tenants_send_the_same_invoice_number_with_two_operation_keys')]
    public function every_accepted_document_reaches_delivered(array $numNum, ImportResult $josemmo, array $subTenants): void
    {
        $pending = [
            'own.num-num' => [self::standard(), $numNum['result']->providerDocumentId],
            'own.josemmo' => [self::standard(), $josemmo->providerDocumentId],
            'sub_tenant.a' => [self::master(), $subTenants['a']->providerDocumentId],
            'sub_tenant.b' => [self::master(), $subTenants['b']->providerDocumentId],
        ];
        $final = [];
        $deadline = time() + 300;
        while ($pending !== [] && time() < $deadline) {
            foreach ($pending as $name => [$client, $id]) {
                $invoice = $client->getInvoice((string) $id);
                if ($invoice->status !== null && $invoice->status !== 'submitted') {
                    $final[$name] = $invoice->status;
                    Evidence::record('invoice.status.' . $name, ['document' => $id, 'status' => $invoice->status, 'resultCode' => $invoice->resultCode, 'requestId' => $invoice->requestId]);
                    unset($pending[$name]);
                }
            }
            if ($pending !== []) {
                sleep(15);
            }
        }

        self::assertSame([], array_keys($pending), 'still submitted after 5 minutes');
        foreach ($final as $name => $status) {
            self::assertSame('delivered', $status, $name);
        }
    }
}
