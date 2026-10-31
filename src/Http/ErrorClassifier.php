<?php

declare(strict_types=1);

namespace Getpeppr\Http;

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
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Turns a failed call into the exception of its family.
 *
 * The matrix is total and ordered; the first row that applies wins:
 *
 *  1. no HTTP response                              → TransportException
 *  2. no result code, 413                           → DocumentTooLargeException
 *  3. no result code, 429 or 5xx                    → TransportException
 *  4. no result code, any other status              → GetpepprException (unexpected)
 *  5. invoices_import.duplicate_document            → DuplicateDocumentException
 *  6. idempotency.key_reused / idempotency.key_blank → IdempotencyKeyException
 *  7. remediation fix_request                       → InvalidRequestException
 *  8. remediation contact_support                   → ContactSupportException
 *  9. remediation authenticate                      → AuthenticationException
 * 10. remediation retry / retry_after               → RetryableException
 * 11. remediation none on an error status           → UnsupportedOperationException
 * 12. remediation absent or unknown                 → GetpepprException
 *
 * `Getpeppr-Retryable` is exposed but never decides a family on its own: an
 * account-state refusal and a rejected document both say `false`.
 *
 * @internal
 */
final class ErrorClassifier
{
    /** Bounded read of the one body the matrix needs (the duplicate refusal). */
    private const MAX_DUPLICATE_BODY_BYTES = 65536;

    private const DUPLICATE_CODE = 'invoices_import.duplicate_document';
    private const IDEMPOTENCY_CODES = ['idempotency.key_reused', 'idempotency.key_blank'];

    public static function fromTransportFailure(#[\SensitiveParameter] ClientExceptionInterface $failure): TransportException
    {
        // The PSR-18 exception is deliberately NOT chained: it may carry the
        // request, its body and its Authorization header. Only its class is kept.
        return new TransportException(sprintf(
            'No response was received from getpeppr (%s). The document may have been sent: '
            . 'resend with the same Idempotency-Key, never a new one. The package does not retry on its own.',
            $failure::class,
        ));
    }

    public static function fromResponse(#[\SensitiveParameter] ResponseInterface $response): GetpepprException
    {
        $status = $response->getStatusCode();
        $headers = ResultHeaders::fromResponse($response);
        $code = $headers->resultCode;

        if ($code === null) {
            if ($status === 413) {
                return self::build(DocumentTooLargeException::class, $status, $headers,
                    'The request is larger than the API accepts: about 3 MB of document at most. Nothing was sent.');
            }
            if ($status === 429 || $status >= 500) {
                return self::build(TransportException::class, $status, $headers,
                    'The API host answered without a getpeppr result. Retry later with the same Idempotency-Key.');
            }

            return self::build(GetpepprException::class, $status, $headers,
                'Unexpected response that getpeppr did not write. Do not guess: keep the document and quote the request id to support.');
        }

        if ($code === self::DUPLICATE_CODE) {
            return new DuplicateDocumentException(
                self::message($status, $headers,
                    'This document was already sent moments ago and nothing was sent this time. '
                    . 'Do not send it again: read the status of the earlier document (duplicateOf).'),
                self::duplicateOf($response),
                ...self::fields($status, $headers),
            );
        }

        if (in_array($code, self::IDEMPOTENCY_CODES, true)) {
            return self::build(IdempotencyKeyException::class, $status, $headers,
                'The Idempotency-Key was misused: one key is one operation with one exact body.');
        }

        return match ($headers->remediation) {
            'fix_request' => self::build(InvalidRequestException::class, $status, $headers,
                'The request or the document must be fixed. Resending it unchanged fails the same way.'),
            'contact_support' => self::build(ContactSupportException::class, $status, $headers,
                'The document is not at fault: the account cannot do this right now. Keep the document and resend it once the account state has changed.'),
            'authenticate' => self::build(AuthenticationException::class, $status, $headers,
                'The API key was refused.'),
            'retry', 'retry_after' => self::build(RetryableException::class, $status, $headers,
                'Temporary refusal. Retry later with the same Idempotency-Key.'),
            'none' => self::build(UnsupportedOperationException::class, $status, $headers,
                'This operation is not supported. Do not resend.'),
            default => self::build(GetpepprException::class, $status, $headers,
                'This version of getpeppr-php does not know what this result asks for. Do not guess: keep the document and quote the request id to support.'),
        };
    }

    /**
     * @template T of GetpepprException
     * @param class-string<T> $class
     * @return T
     */
    private static function build(string $class, int $status, ResultHeaders $headers, string $sentence): GetpepprException
    {
        return new $class(self::message($status, $headers, $sentence), ...self::fields($status, $headers));
    }

    /**
     * @return array{status: int, resultCode: ?string, remediation: ?string, retryable: ?bool, requestId: ?string, resultDocs: ?string, vercelId: ?string, publicMessage: ?string, retryAfter: ?int}
     */
    private static function fields(int $status, ResultHeaders $headers): array
    {
        return [
            'status' => $status,
            'resultCode' => $headers->resultCode,
            'remediation' => $headers->remediation,
            'retryable' => $headers->retryable,
            'requestId' => $headers->requestId,
            'resultDocs' => $headers->resultDocs,
            'vercelId' => $headers->vercelId,
            'publicMessage' => $headers->publicMessage,
            'retryAfter' => $headers->retryAfter,
        ];
    }

    /** Built only from validated headers: never from a body. */
    private static function message(int $status, ResultHeaders $headers, string $sentence): string
    {
        $facts = ['HTTP ' . $status];
        if ($headers->resultCode !== null) {
            $facts[] = $headers->resultCode;
        }
        if ($headers->requestId !== null) {
            $facts[] = 'request ' . $headers->requestId;
        } elseif ($headers->vercelId !== null) {
            $facts[] = 'host request ' . $headers->vercelId;
        }
        if ($headers->retryAfter !== null) {
            $facts[] = 'retry after ' . $headers->retryAfter . ' s';
        }

        $message = $sentence . ' (' . implode(', ', $facts) . ')';
        if ($headers->publicMessage !== null) {
            $message .= ' ' . $headers->publicMessage;
        }

        return $message;
    }

    /** The one value extracted from the duplicate refusal body; the rest is discarded. */
    private static function duplicateOf(#[\SensitiveParameter] ResponseInterface $response): ?string
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = $body->read(self::MAX_DUPLICATE_BODY_BYTES);

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        } finally {
            unset($raw);
        }

        $value = is_array($decoded) ? ($decoded['duplicateOf'] ?? null) : null;
        if (!is_string($value) || preg_match('/^[A-Za-z0-9-]{1,128}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
