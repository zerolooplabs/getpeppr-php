<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * The request or the document must be fixed (remediation `fix_request`).
 *
 * Fix the request: a corrected document is a new send, under a new
 * Idempotency-Key. Typical causes: a
 * Peppol rule violated (`invoices_import.validation_failed`), a wrong
 * recipient or supplier identity, or a standard key sending `sender`
 * (`403 authz.scope_denied`).
 */
class InvalidRequestException extends GetpepprException
{
}
