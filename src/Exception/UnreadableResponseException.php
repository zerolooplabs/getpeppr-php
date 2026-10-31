<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * The call succeeded (2xx) but its response could not be read, or named
 * nothing the call should return.
 *
 * The request may have taken effect: for a send, the document may have left.
 * Check before repeating it (read the document's status, or resend with the
 * SAME Idempotency-Key, which replays the original answer instead of sending
 * twice), and quote `requestId` or `vercelId` to support.
 */
class UnreadableResponseException extends GetpepprException
{
}
