<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * The request or the document must be fixed (remediation `fix_request`).
 *
 * Terminal: resending the same request fails the same way. Typical causes: a
 * Peppol rule violated (`invoices_import.validation_failed`), a wrong
 * recipient or supplier identity, or a standard key sending `sender`
 * (`403 authz.scope_denied`).
 */
class InvalidRequestException extends GetpepprException
{
}
