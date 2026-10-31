<?php

declare(strict_types=1);

namespace Getpeppr;

use Getpeppr\Exception\WebhookVerificationException;

/**
 * Verifies the `Getpeppr-Signature` header of a webhook request.
 *
 *     $event = Webhook::verify(file_get_contents('php://input'), $_SERVER['HTTP_GETPEPPR_SIGNATURE'] ?? '', $secret);
 *
 * The header is `t={unix timestamp},s={hex HMAC-SHA256}`, computed over
 * `{timestamp}.{raw body}` with the endpoint's `whsec_…` secret. Pass the raw
 * body exactly as received: never a parsed and re-encoded one.
 *
 * Verification proves the event comes from getpeppr and is recent. It does not
 * prove it is new: a legitimate redelivery verifies too. Deduplicate on the
 * returned event id.
 */
final class Webhook
{
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * @param int $toleranceSeconds Maximum distance between the signed timestamp and now, either way.
     * @param int|null $now Unix time to verify against; the current time when null.
     *
     * @throws WebhookVerificationException
     */
    public static function verify(
        #[\SensitiveParameter] string $rawBody,
        #[\SensitiveParameter] string $signatureHeader,
        #[\SensitiveParameter] string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): WebhookEvent {
        if ($toleranceSeconds < 0) {
            throw new \InvalidArgumentException('The tolerance cannot be negative.');
        }
        if ($rawBody === '') {
            throw new WebhookVerificationException('The webhook request has no body.', 'missing_body');
        }
        if ($signatureHeader === '') {
            throw new WebhookVerificationException('The webhook request has no Getpeppr-Signature header.', 'missing_signature');
        }
        if ($secret === '') {
            throw new WebhookVerificationException('No webhook secret was given.', 'missing_secret');
        }

        [$timestamp, $signature] = self::parseHeader($signatureHeader);

        $now ??= time();
        if (abs($now - (int) $timestamp) > $toleranceSeconds) {
            throw new WebhookVerificationException(
                sprintf('The signature timestamp is more than %d seconds away from now.', $toleranceSeconds),
                'timestamp_outside_tolerance',
            );
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new WebhookVerificationException('The webhook signature does not match.', 'signature_mismatch');
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }
        $id = is_array($payload) ? ($payload['id'] ?? null) : null;
        if (!is_array($payload) || !is_string($id) || $id === '') {
            throw new WebhookVerificationException('The webhook payload is not an event with an id.', 'invalid_payload');
        }

        return WebhookEvent::fromPayload($id, $payload);
    }

    /** @return array{0: string, 1: string} timestamp digits, lowercase hex signature */
    private static function parseHeader(#[\SensitiveParameter] string $header): array
    {
        // Fields other than t and s are ignored, so that a field added later does
        // not break verification. A repeated field is refused: never guess which one counts.
        $fields = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2 || $pair[0] === '' || isset($fields[$pair[0]])) {
                self::malformed();
            }
            $fields[$pair[0]] = $pair[1];
        }

        $timestamp = $fields['t'] ?? '';
        $signature = $fields['s'] ?? '';
        if (preg_match('/^\d{1,12}$/D', $timestamp) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $signature) !== 1) {
            self::malformed();
        }

        return [$timestamp, $signature];
    }

    private static function malformed(): never
    {
        throw new WebhookVerificationException(
            "The Getpeppr-Signature header is malformed: expected 't={timestamp},s={hex signature}'.",
            'malformed_signature',
        );
    }
}
