<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * No usable answer: the request may or may not have reached getpeppr.
 *
 * Either no HTTP response arrived (DNS, TLS, connection reset, timeout:
 * `status` is null), or the hosting platform answered `429` or `5xx` on its
 * own. The document may have been sent. Resend later with the SAME
 * `Idempotency-Key`, never a new one, after `retryAfter` seconds when set.
 * The package never retries on its own.
 */
class TransportException extends GetpepprException
{
}
