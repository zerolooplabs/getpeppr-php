<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * Root of every exception this package throws for an API call.
 *
 * Thrown as such (not as a subclass) when the package cannot tell what the
 * response means: a response getpeppr did not write, or a remediation this
 * version does not know. Do not guess: keep the document, read its status,
 * and quote `requestId` (or `vercelId`) to support.
 *
 * Every field may be null. `publicMessage` is the fixed catalogue sentence of
 * the `Getpeppr-Result-Message` header, never the response body: no exception
 * of this package keeps the response body, the request body, the API key, the
 * sender or the invoice number.
 */
class GetpepprException extends \RuntimeException
{
    public function __construct(
        string $message,
        /** HTTP status, or null when no response was received. */
        public readonly ?int $status = null,
        /** Stable result code (`Getpeppr-Result-Code`), e.g. `invoices_import.validation_failed`. */
        public readonly ?string $resultCode = null,
        /** What to do (`Getpeppr-Remediation`): none, fix_request, contact_support, authenticate, retry, retry_after. */
        public readonly ?string $remediation = null,
        /** Whether the same call, unchanged, can succeed (`Getpeppr-Retryable`). Never decides the family on its own. */
        public readonly ?bool $retryable = null,
        /** Quote it to support (`Getpeppr-Request-Id`). */
        public readonly ?string $requestId = null,
        /** Documentation for this result code (`Getpeppr-Result-Docs`), https only. */
        public readonly ?string $resultDocs = null,
        /** Hosting platform request id (`x-vercel-id`), useful when getpeppr did not write the response. */
        public readonly ?string $vercelId = null,
        /** Fixed catalogue sentence (`Getpeppr-Result-Message`). */
        public readonly ?string $publicMessage = null,
        /** Seconds to wait before retrying (`Retry-After`), when sent. */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
