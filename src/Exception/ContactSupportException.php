<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * The account cannot do this right now (remediation `contact_support`).
 *
 * The document is NOT at fault: keep it, and resend it once the account state
 * has changed (identity verified, platform contract active, master key used...).
 * `retryable` is false here, exactly as for a rejected document: the
 * remediation, not `retryable`, is what tells the two apart.
 */
class ContactSupportException extends GetpepprException
{
}
