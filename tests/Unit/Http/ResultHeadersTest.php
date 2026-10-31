<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Unit\Http;

use Getpeppr\Http\ResultHeaders;
use Getpeppr\Tests\Support\RawHeaderResponse;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ResultHeadersTest extends TestCase
{
    #[Test]
    public function it_reads_all_result_headers(): void
    {
        $headers = ResultHeaders::fromResponse(new Response(422, [
            'Getpeppr-Request-Id' => 'req_0123456789abcdef0123456789abcdef',
            'Getpeppr-Result-Code' => 'invoices_import.validation_failed',
            'Getpeppr-Result-Message' => 'The document violates at least one fatal Peppol rule.',
            'Getpeppr-Retryable' => 'false',
            'Getpeppr-Remediation' => 'fix_request',
            'Getpeppr-Result-Docs' => 'https://getpeppr.dev/docs/api-results/invoices_import.validation_failed',
            'x-vercel-id' => 'lhr1::abcde-1730000000000-0123456789ab',
            'Retry-After' => '30',
        ]));

        self::assertSame('req_0123456789abcdef0123456789abcdef', $headers->requestId);
        self::assertSame('invoices_import.validation_failed', $headers->resultCode);
        self::assertSame('The document violates at least one fatal Peppol rule.', $headers->publicMessage);
        self::assertFalse($headers->retryable);
        self::assertSame('fix_request', $headers->remediation);
        self::assertSame('https://getpeppr.dev/docs/api-results/invoices_import.validation_failed', $headers->resultDocs);
        self::assertSame('lhr1::abcde-1730000000000-0123456789ab', $headers->vercelId);
        self::assertSame(30, $headers->retryAfter);
    }

    #[Test]
    public function every_header_is_optional(): void
    {
        $headers = ResultHeaders::fromResponse(new Response(413));

        self::assertNull($headers->requestId);
        self::assertNull($headers->resultCode);
        self::assertNull($headers->publicMessage);
        self::assertNull($headers->retryable);
        self::assertNull($headers->remediation);
        self::assertNull($headers->resultDocs);
        self::assertNull($headers->vercelId);
        self::assertNull($headers->retryAfter);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableBooleans(): iterable
    {
        yield 'upper case' => ['TRUE'];
        yield 'number' => ['1'];
        yield 'word' => ['maybe'];
        yield 'nbsp padding' => ["\u{00A0}true"];
        yield 'control character' => ["true\u{007F}"];
        yield 'duplicated header joined' => ['true, true'];
    }

    #[Test]
    #[DataProvider('unreadableBooleans')]
    public function an_unreadable_retryable_value_is_absent_never_a_permission(string $raw): void
    {
        $headers = ResultHeaders::fromResponse(self::raw(['Getpeppr-Retryable' => $raw]));

        self::assertNull($headers->retryable);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableTokens(): iterable
    {
        yield 'trailing DEL' => ["retry_after\u{007F}"];
        yield 'C1 CSI' => ["retry\u{009B}"];
        yield 'inner space' => ['retry after'];
        yield 'nbsp' => ["retry\u{00A0}"];
        yield 'duplicated header joined' => ['retry, fix_request'];
        yield 'over long' => [str_repeat('a', 257)];
        yield 'invalid utf-8' => ["retry\xC3"];
    }

    #[Test]
    #[DataProvider('unreadableTokens')]
    public function an_unreadable_machine_token_is_dropped_never_repaired(string $raw): void
    {
        $headers = ResultHeaders::fromResponse(self::raw([
            'Getpeppr-Remediation' => $raw,
            'Getpeppr-Result-Code' => $raw,
        ]));

        self::assertNull($headers->remediation);
        self::assertNull($headers->resultCode);
    }

    #[Test]
    public function an_unknown_but_well_formed_remediation_is_passed_through(): void
    {
        $headers = ResultHeaders::fromResponse(new Response(400, ['Getpeppr-Remediation' => 'call_your_mother']));

        self::assertSame('call_your_mother', $headers->remediation);
    }

    #[Test]
    public function the_public_message_is_sanitised_for_printing(): void
    {
        $headers = ResultHeaders::fromResponse(self::raw([
            'Getpeppr-Result-Message' => "Fixed\u{009B}31m sentence\u{0007}.",
        ]));

        self::assertSame('Fixed 31m sentence .', $headers->publicMessage);
    }

    #[Test]
    public function an_over_long_public_message_is_dropped_whole(): void
    {
        $headers = ResultHeaders::fromResponse(new Response(400, [
            'Getpeppr-Result-Message' => str_repeat('a', 1025),
        ]));

        self::assertNull($headers->publicMessage);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeDocsLinks(): iterable
    {
        yield 'http' => ['http://getpeppr.dev/docs'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'credentials' => ['https://getpeppr.dev@evil.test/docs'];
        yield 'relative' => ['/docs/api-results'];
        yield 'backslash' => ['https://getpeppr.dev\\@evil.test'];
        yield 'control' => ["https://getpeppr.dev/docs\u{0009}x"];
    }

    #[Test]
    #[DataProvider('unsafeDocsLinks')]
    public function an_unsafe_docs_link_is_dropped(string $raw): void
    {
        $headers = ResultHeaders::fromResponse(self::raw(['Getpeppr-Result-Docs' => $raw]));

        self::assertNull($headers->resultDocs);
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function retryAfterValues(): iterable
    {
        yield 'seconds' => ['12', 12];
        yield 'zero' => ['0', 0];
        yield 'http date is not read' => ['Wed, 21 Oct 2026 07:28:00 GMT', null];
        yield 'negative' => ['-5', null];
        yield 'absurd' => ['99999999999', null];
    }

    #[Test]
    #[DataProvider('retryAfterValues')]
    public function retry_after_is_read_as_delta_seconds_only(string $raw, ?int $expected): void
    {
        $headers = ResultHeaders::fromResponse(new Response(429, ['Retry-After' => $raw]));

        self::assertSame($expected, $headers->retryAfter);
    }

    /** @param array<string, string> $headers */
    private static function raw(array $headers): ResponseInterface
    {
        return new RawHeaderResponse(400, $headers);
    }
}
