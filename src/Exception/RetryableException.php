<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * A temporary refusal getpeppr announced (remediation `retry` or `retry_after`).
 *
 * Resend later with the SAME `Idempotency-Key`, after `retryAfter` seconds
 * when set. The package never retries on its own.
 */
class RetryableException extends GetpepprException
{
}
