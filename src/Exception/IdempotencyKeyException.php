<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * The `Idempotency-Key` was misused (`idempotency.key_reused` or `idempotency.key_blank`).
 *
 * One key is one operation with one exact body. Reusing a key with another
 * body is refused; so is a blank key. Fix the key: the document is not at fault.
 */
class IdempotencyKeyException extends GetpepprException
{
}
