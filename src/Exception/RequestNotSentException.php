<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * The request could not be built, so NOTHING was sent.
 *
 * Your PSR-7/PSR-17 implementation (request factory, stream factory or message)
 * refused to build it. No response exists, and getpeppr saw nothing: fix the
 * HTTP setup, then send again (reusing the same Idempotency-Key is safe).
 *
 * Unlike the root GetpepprException, which means a response came back that
 * this version cannot interpret, this one carries no doubt about the outcome.
 * The underlying exception is not chained: it may hold your key or document.
 */
class RequestNotSentException extends GetpepprException
{
}
