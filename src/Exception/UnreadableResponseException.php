<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * The call succeeded (2xx) but its response could not be read, or named
 * nothing the call should return.
 *
 * The request may have taken effect: for a send, the document may have left.
 * Check before repeating it (read the document's status, or resend with the
 * SAME Idempotency-Key and the same body, which never sends it twice: the
 * retry gets the 201 of the document if it went out, unless it first meets a
 * refusal raised before that check, which sends nothing), and quote
 * `requestId` or `vercelId` to support.
 */
class UnreadableResponseException extends GetpepprException
{
}
