<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Unit\Http;

use Getpeppr\Exception\AuthenticationException;
use Getpeppr\Exception\ContactSupportException;
use Getpeppr\Exception\DocumentTooLargeException;
use Getpeppr\Exception\DuplicateDocumentException;
use Getpeppr\Exception\GetpepprException;
use Getpeppr\Exception\IdempotencyKeyException;
use Getpeppr\Exception\InvalidRequestException;
use Getpeppr\Exception\RetryableException;
use Getpeppr\Exception\TransportException;
use Getpeppr\Exception\UnsupportedOperationException;
use Getpeppr\Http\ErrorClassifier;
use Getpeppr\Tests\Support\FakeNetworkException;
use Getpeppr\Tests\Support\Responses;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The total, ordered error matrix of the package design (§4.3), driven by the
 * published result catalogue (tests/fixtures/api-results.json).
 *
 * The expected family is written here independently of the classifier, in the
 * order of the design table: code first (duplicate, idempotency key), then
 * remediation. `retryable` never decides a family on its own.
 */
final class ErrorMatrixTest extends TestCase
{
    /** Remediations the design table knows. Anything else must be reviewed before it is classified. */
    private const KNOWN_REMEDIATIONS = ['none', 'fix_request', 'contact_support', 'authenticate', 'retry', 'retry_after'];

    /** @return array{source: array{commit: string}, results: list<array{code: string, status: int, retryable: bool, remediation: string, scope: string, operations: list<string>}>} */
    private static function fixture(): array
    {
        $json = file_get_contents(__DIR__ . '/../../fixtures/api-results.json');
        self::assertIsString($json);
        /** @var array{source: array{commit: string}, results: list<array{code: string, status: int, retryable: bool, remediation: string, scope: string, operations: list<string>}>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /** @return class-string<GetpepprException> */
    private static function expectedFamily(string $code, string $remediation): string
    {
        // Rows 5 and 6: the code decides before the remediation does.
        if ($code === 'invoices_import.duplicate_document') {
            return DuplicateDocumentException::class;
        }
        if ($code === 'idempotency.key_reused' || $code === 'idempotency.key_blank') {
            return IdempotencyKeyException::class;
        }

        // Rows 7 to 12.
        return match ($remediation) {
            'fix_request' => InvalidRequestException::class,
            'contact_support' => ContactSupportException::class,
            'authenticate' => AuthenticationException::class,
            'retry', 'retry_after' => RetryableException::class,
            'none' => UnsupportedOperationException::class,
            default => GetpepprException::class,
        };
    }

    /** @return iterable<string, array{string, int, string, bool}> */
    public static function catalogueErrors(): iterable
    {
        foreach (self::fixture()['results'] as $row) {
            if ($row['status'] >= 200 && $row['status'] < 300) {
                continue;
            }
            yield $row['code'] => [$row['code'], $row['status'], $row['remediation'], $row['retryable']];
        }
    }

    #[Test]
    #[DataProvider('catalogueErrors')]
    public function every_catalogue_error_of_the_package_operations_lands_in_its_family(
        string $code,
        int $status,
        string $remediation,
        bool $retryable,
    ): void {
        $exception = ErrorClassifier::fromResponse(Responses::getpeppr($status, $code, $remediation, $retryable));

        self::assertSame(self::expectedFamily($code, $remediation), $exception::class);
        self::assertSame($status, $exception->status);
        self::assertSame($code, $exception->resultCode);
        self::assertSame($remediation, $exception->remediation);
        self::assertSame($retryable, $exception->retryable);
        self::assertSame('req_0123456789abcdef0123456789abcdef', $exception->requestId);
        self::assertSame('Fixed catalogue sentence for ' . $code . '.', $exception->publicMessage);
    }

    #[Test]
    public function the_fixture_covers_the_codes_the_matrix_names(): void
    {
        $byCode = [];
        foreach (self::fixture()['results'] as $row) {
            $byCode[$row['code']] = $row;
        }

        // A rename of any of these in the catalogue must turn this test red.
        self::assertSame(409, $byCode['invoices_import.duplicate_document']['status'] ?? null);
        self::assertSame(422, $byCode['idempotency.key_reused']['status'] ?? null);
        self::assertSame(400, $byCode['idempotency.key_blank']['status'] ?? null);
        self::assertSame(422, $byCode['invoices_import.validation_failed']['status'] ?? null);
        self::assertSame('contact_support', $byCode['invoices_import.platform_billing_not_active']['remediation'] ?? null);
        self::assertSame(403, $byCode['authz.scope_denied']['status'] ?? null);
        self::assertSame('contact_support', $byCode['authz.master_key_required']['remediation'] ?? null);
    }

    #[Test]
    public function every_remediation_of_the_package_operations_is_one_the_matrix_knows(): void
    {
        foreach (self::fixture()['results'] as $row) {
            self::assertContains(
                $row['remediation'],
                self::KNOWN_REMEDIATIONS,
                $row['code'] . ' carries a remediation the design table does not classify: review §4.3 before resyncing.',
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function accountStateRefusals(): iterable
    {
        yield 'platform_billing_not_active' => ['invoices_import.platform_billing_not_active'];
        yield 'production_access_expired' => ['invoices_import.production_access_expired'];
        yield 'sender_identity_incomplete' => ['invoices_import.sender_identity_incomplete'];
        yield 'no_sender_identifier' => ['invoices_import.no_sender_identifier'];
        yield 'sub_tenant_has_no_identifier' => ['invoices_import.sub_tenant_has_no_identifier'];
    }

    #[Test]
    #[DataProvider('accountStateRefusals')]
    public function an_account_state_refusal_is_never_a_document_to_fix(string $code): void
    {
        // retryable: false, like a rejected document. Only the remediation tells them apart.
        $exception = ErrorClassifier::fromResponse(Responses::getpeppr(422, $code, 'contact_support', false));

        self::assertSame(ContactSupportException::class, $exception::class);
    }

    #[Test]
    public function the_duplicate_exposes_the_earlier_document(): void
    {
        $exception = ErrorClassifier::fromResponse(Responses::getpeppr(409, 'invoices_import.duplicate_document', 'fix_request', false, [
            'error' => 'duplicate_document',
            'message' => 'Invoice SENTINEL-INV-42 was already sent 7s ago.',
            'duplicateOf' => 'e8ec77a9-0000-4000-8000-000000000000',
        ]));

        self::assertInstanceOf(DuplicateDocumentException::class, $exception);
        self::assertSame('e8ec77a9-0000-4000-8000-000000000000', $exception->duplicateOf);
    }

    #[Test]
    public function a_malformed_duplicate_of_is_absent(): void
    {
        $exception = ErrorClassifier::fromResponse(Responses::getpeppr(409, 'invoices_import.duplicate_document', 'fix_request', false, [
            'duplicateOf' => "e8ec77a9\u{001B}[31m",
        ]));

        self::assertInstanceOf(DuplicateDocumentException::class, $exception);
        self::assertNull($exception->duplicateOf);
    }

    #[Test]
    public function row_1_no_response_is_a_transport_failure(): void
    {
        $failure = new FakeNetworkException(new Request('POST', 'https://api.getpeppr.dev/v1/invoices/import'), 'Connection reset');

        $exception = ErrorClassifier::fromTransportFailure($failure);

        self::assertSame(TransportException::class, $exception::class);
        self::assertNull($exception->status);
        self::assertNull($exception->resultCode);
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function row_2_a_host_413_is_a_document_too_large(): void
    {
        $exception = ErrorClassifier::fromResponse(Responses::host(413, 'Request Entity Too Large'));

        self::assertSame(DocumentTooLargeException::class, $exception::class);
        self::assertSame(413, $exception->status);
        self::assertSame('lhr1::iad1::abcde-1730000000000-0123456789ab', $exception->vercelId);
        self::assertNull($exception->resultCode);
    }

    /** @return iterable<string, array{int}> */
    public static function hostRetryStatuses(): iterable
    {
        yield '429' => [429];
        yield '500' => [500];
        yield '502' => [502];
        yield '503' => [503];
        yield '504' => [504];
    }

    #[Test]
    #[DataProvider('hostRetryStatuses')]
    public function row_3_a_host_429_or_5xx_is_a_transport_failure(int $status): void
    {
        $exception = ErrorClassifier::fromResponse(Responses::host($status, 'upstream', ['Retry-After' => '7']));

        self::assertSame(TransportException::class, $exception::class);
        self::assertSame($status, $exception->status);
        self::assertSame(7, $exception->retryAfter);
    }

    /** @return iterable<string, array{int}> */
    public static function hostOtherStatuses(): iterable
    {
        yield '400' => [400];
        yield '404' => [404];
        yield '405' => [405];
        yield '308' => [308];
        yield '422' => [422];
    }

    #[Test]
    #[DataProvider('hostOtherStatuses')]
    public function row_4_any_other_host_response_is_unexpected(int $status): void
    {
        $exception = ErrorClassifier::fromResponse(Responses::host($status, 'Not Found'));

        self::assertSame(GetpepprException::class, $exception::class);
        self::assertSame($status, $exception->status);
        self::assertNotNull($exception->vercelId);
    }

    #[Test]
    public function a_remediation_without_a_result_code_does_not_classify(): void
    {
        // The matrix keys rows 2 to 4 on the code header, not on the remediation.
        $exception = ErrorClassifier::fromResponse(new Response(422, ['Getpeppr-Remediation' => 'fix_request']));

        self::assertSame(GetpepprException::class, $exception::class);
    }

    #[Test]
    public function the_code_decides_before_the_remediation(): void
    {
        $duplicate = ErrorClassifier::fromResponse(Responses::getpeppr(409, 'invoices_import.duplicate_document', 'retry', true));
        $reused = ErrorClassifier::fromResponse(Responses::getpeppr(422, 'idempotency.key_reused', 'contact_support', false));

        self::assertSame(DuplicateDocumentException::class, $duplicate::class);
        self::assertSame(IdempotencyKeyException::class, $reused::class);
    }

    #[Test]
    public function retryable_alone_never_makes_a_retryable_family(): void
    {
        $exception = ErrorClassifier::fromResponse(Responses::getpeppr(422, 'invoices_import.validation_failed', 'fix_request', true));

        self::assertSame(InvalidRequestException::class, $exception::class);
        self::assertTrue($exception->retryable);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownRemediations(): iterable
    {
        yield 'unknown' => ['call_your_mother'];
        yield 'wait (outside the design table)' => ['wait'];
    }

    #[Test]
    #[DataProvider('unknownRemediations')]
    public function row_12_an_unknown_remediation_is_the_root_family(string $remediation): void
    {
        $exception = ErrorClassifier::fromResponse(Responses::getpeppr(409, 'invoices_import.something_new', $remediation, false));

        self::assertSame(GetpepprException::class, $exception::class);
        self::assertSame($remediation, $exception->remediation);
    }

    #[Test]
    public function row_12_a_missing_remediation_is_the_root_family(): void
    {
        $exception = ErrorClassifier::fromResponse(new Response(422, ['Getpeppr-Result-Code' => 'invoices_import.validation_failed']));

        self::assertSame(GetpepprException::class, $exception::class);
        self::assertSame('invoices_import.validation_failed', $exception->resultCode);
    }

    #[Test]
    public function row_10_keeps_retry_after(): void
    {
        $exception = ErrorClassifier::fromResponse(Responses::getpeppr(429, 'rate_limit.key_exceeded', 'retry_after', true, [], ['Retry-After' => '42']));

        self::assertSame(RetryableException::class, $exception::class);
        self::assertSame(42, $exception->retryAfter);
    }

    #[Test]
    public function a_documented_too_large_from_getpeppr_is_also_a_document_too_large_family_member(): void
    {
        $exception = ErrorClassifier::fromResponse(Responses::getpeppr(413, 'request.body_too_large', 'fix_request', false));

        self::assertInstanceOf(InvalidRequestException::class, $exception);
        self::assertInstanceOf(DocumentTooLargeException::class, ErrorClassifier::fromResponse(Responses::host(413)));
        self::assertInstanceOf(InvalidRequestException::class, ErrorClassifier::fromResponse(Responses::host(413)));
    }
}
