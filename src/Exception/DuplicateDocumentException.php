<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * The same document was already sent moments ago (`409 invoices_import.duplicate_document`).
 *
 * Nothing was sent this time. This is not a rejection of the document: it
 * announces that the document already left. Do not send it again; read the
 * status of `duplicateOf` and reconcile your invoice with that document.
 *
 * The response body of this refusal contains the invoice number. It is
 * neither copied into this exception nor logged: only `duplicateOf` is kept.
 */
class DuplicateDocumentException extends GetpepprException
{
    public function __construct(
        string $message,
        /** getpeppr document id of the earlier send, null when the response did not carry a readable one. */
        public readonly ?string $duplicateOf = null,
        ?int $status = null,
        ?string $resultCode = null,
        ?string $remediation = null,
        ?bool $retryable = null,
        ?string $requestId = null,
        ?string $resultDocs = null,
        ?string $vercelId = null,
        ?string $publicMessage = null,
        ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status, $resultCode, $remediation, $retryable, $requestId, $resultDocs, $vercelId, $publicMessage, $retryAfter);
    }
}
