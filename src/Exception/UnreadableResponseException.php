<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * The call succeeded (2xx) but its response could not be read, or named
 * nothing the call should return.
 *
 * The request may have taken effect: for a send, the document may have left.
 * Check before repeating it (read the document's status, or resend with the
 * SAME Idempotency-Key, which never sends it twice: the retry replays the
 * original answer when it was recorded, and otherwise gets an error that does
 * not mean the document was refused), and quote `requestId` or `vercelId` to
 * support.
 */
class UnreadableResponseException extends GetpepprException
{
}
