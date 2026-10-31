<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Unit;

use Getpeppr\Exception\GetpepprException;
use Getpeppr\Exception\WebhookVerificationException;
use Getpeppr\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_SENTINELSECRET0123456789';
    private const NOW = 1_793_000_000;
    private const BODY = '{"id":"evt_3f2a9c","type":"invoice.sent","environment":"sandbox","data":{"invoiceId":"0a1b2c3d-0000-4000-8000-000000000002","invoiceNumber":"SENTINEL-INV-42","status":"delivered"},"createdAt":"2026-10-31T09:00:00.000Z"}';

    private static function sign(string $body, int $timestamp, string $secret = self::SECRET): string
    {
        return 't=' . $timestamp . ',s=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    #[Test]
    public function it_verifies_a_signed_event_and_returns_its_id(): void
    {
        $event = Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW), self::SECRET, now: self::NOW);

        self::assertSame('evt_3f2a9c', $event->id);
        self::assertSame('invoice.sent', $event->type);
        self::assertSame('sandbox', $event->environment);
        self::assertSame('2026-10-31T09:00:00.000Z', $event->createdAt);
        self::assertSame('delivered', $event->data['status'] ?? null);
        self::assertSame('evt_3f2a9c', $event->toArray()['id']);
    }

    #[Test]
    public function two_valid_deliveries_of_the_same_event_are_both_accepted_with_the_same_id(): void
    {
        // Delivery is at least once: a redelivery is re-signed with a new timestamp.
        // Verification proves authenticity and freshness, not uniqueness:
        // deduplicating on the event id is the consumer's job.
        $first = Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW), self::SECRET, now: self::NOW);
        $second = Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW + 60), self::SECRET, now: self::NOW + 60);

        self::assertSame($first->id, $second->id);
    }

    #[Test]
    public function the_raw_body_is_verified_byte_for_byte(): void
    {
        $body = self::BODY . "\n";

        $event = Webhook::verify($body, self::sign($body, self::NOW), self::SECRET, now: self::NOW);

        self::assertSame('evt_3f2a9c', $event->id);
        $this->expectVerificationFailure('signature_mismatch');
        Webhook::verify(self::BODY, self::sign($body, self::NOW), self::SECRET, now: self::NOW);
    }

    #[Test]
    public function a_wrong_secret_is_refused(): void
    {
        $this->expectVerificationFailure('signature_mismatch');

        Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW, 'whsec_other'), self::SECRET, now: self::NOW);
    }

    #[Test]
    public function a_tampered_body_is_refused(): void
    {
        $this->expectVerificationFailure('signature_mismatch');

        Webhook::verify(str_replace('delivered', 'paid', self::BODY), self::sign(self::BODY, self::NOW), self::SECRET, now: self::NOW);
    }

    /** @return iterable<string, array{int}> */
    public static function staleOffsets(): iterable
    {
        yield 'too old' => [-301];
        yield 'too far in the future' => [301];
    }

    #[Test]
    #[DataProvider('staleOffsets')]
    public function a_timestamp_outside_the_tolerance_is_refused(int $offset): void
    {
        $this->expectVerificationFailure('timestamp_outside_tolerance');

        Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW + $offset), self::SECRET, now: self::NOW);
    }

    #[Test]
    public function the_tolerance_boundary_is_inclusive_and_configurable(): void
    {
        Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW - 300), self::SECRET, now: self::NOW);
        Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW - 600), self::SECRET, toleranceSeconds: 600, now: self::NOW);

        $this->expectVerificationFailure('timestamp_outside_tolerance');
        Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW - 31), self::SECRET, toleranceSeconds: 30, now: self::NOW);
    }

    #[Test]
    public function it_uses_the_current_time_by_default(): void
    {
        $event = Webhook::verify(self::BODY, self::sign(self::BODY, time()), self::SECRET);

        self::assertSame('evt_3f2a9c', $event->id);
    }

    #[Test]
    public function a_signature_from_a_forged_timestamp_is_refused(): void
    {
        // Moving a valid signature to a fresh timestamp breaks it: the timestamp is signed.
        $valid = self::sign(self::BODY, self::NOW - 3600);
        $forged = 't=' . self::NOW . ',' . explode(',', $valid)[1];

        $this->expectVerificationFailure('signature_mismatch');
        Webhook::verify(self::BODY, $forged, self::SECRET, now: self::NOW);
    }

    #[Test]
    public function an_unknown_extra_field_in_the_header_is_ignored(): void
    {
        $event = Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW) . ',v2=' . str_repeat('b', 64), self::SECRET, now: self::NOW);

        self::assertSame('evt_3f2a9c', $event->id);
    }

    #[Test]
    public function a_trailing_newline_in_the_signature_is_malformed(): void
    {
        $this->expectVerificationFailure('malformed_signature');

        Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW) . "\n", self::SECRET, now: self::NOW);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedHeaders(): iterable
    {
        $s = str_repeat('a', 64);
        yield 'no t' => ['s=' . $s];
        yield 'no s' => ['t=1793000000'];
        yield 'not a number' => ['t=abc,s=' . $s];
        yield 'negative' => ['t=-1,s=' . $s];
        yield 'short hex' => ['t=1793000000,s=abc'];
        yield 'upper case hex' => ['t=1793000000,s=' . str_repeat('A', 64)];
        yield 'duplicated t' => ['t=1793000000,t=1793000000,s=' . $s];
        yield 'duplicated s' => ['t=1793000000,s=' . $s . ',s=' . $s];
        yield 'garbage' => ['invalid-header'];
    }

    #[Test]
    #[DataProvider('malformedHeaders')]
    public function a_malformed_signature_header_is_refused(string $header): void
    {
        $this->expectVerificationFailure('malformed_signature');

        Webhook::verify(self::BODY, $header, self::SECRET, now: self::NOW);
    }

    #[Test]
    public function missing_inputs_are_refused(): void
    {
        foreach ([
            'missing_body' => ['', self::sign('', self::NOW), self::SECRET],
            'missing_signature' => [self::BODY, '', self::SECRET],
            'missing_secret' => [self::BODY, self::sign(self::BODY, self::NOW), ''],
        ] as $reason => [$body, $header, $secret]) {
            try {
                Webhook::verify($body, $header, $secret, now: self::NOW);
                self::fail('expected ' . $reason);
            } catch (WebhookVerificationException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unusablePayloads(): iterable
    {
        yield 'not json' => ['not json'];
        yield 'json array' => ['["evt_1"]'];
        yield 'no id' => ['{"type":"invoice.sent"}'];
        yield 'numeric id' => ['{"id":42,"type":"invoice.sent"}'];
    }

    #[Test]
    #[DataProvider('unusablePayloads')]
    public function an_authentic_payload_without_an_event_id_is_refused(string $body): void
    {
        $this->expectVerificationFailure('invalid_payload');

        Webhook::verify($body, self::sign($body, self::NOW), self::SECRET, now: self::NOW);
    }

    #[Test]
    public function a_negative_tolerance_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW), self::SECRET, toleranceSeconds: -1, now: self::NOW);
    }

    #[Test]
    public function a_refusal_names_neither_the_secret_nor_the_body(): void
    {
        try {
            Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW, 'whsec_other'), self::SECRET, now: self::NOW);
            self::fail('expected a refusal');
        } catch (WebhookVerificationException $e) {
            self::assertInstanceOf(GetpepprException::class, $e);
            $everything = \Getpeppr\Tests\Support\LeakProbe::render($e);
            self::assertStringNotContainsString('SENTINELSECRET', $everything);
            self::assertStringNotContainsString('SENTINEL-INV-42', $everything);
        }
    }

    private function expectVerificationFailure(string $reason): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/\(' . preg_quote($reason, '/') . '\)/');
    }
}
