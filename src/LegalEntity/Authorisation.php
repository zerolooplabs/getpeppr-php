<?php

declare(strict_types=1);

namespace Getpeppr\LegalEntity;

/** On a send-only Legal Entity only: how its representative's authority was proven, or why it still waits. */
final class Authorisation
{
    private function __construct(
        /** qualified_signature, manual_review or sandbox_simulated; null until the authority is proven. Open set. */
        public readonly ?string $method,
        /** Why the authorisation still waits, e.g. representative_review; null otherwise. Open set. */
        public readonly ?string $reason,
    ) {
    }

    /**
     * The object, or null when the response did not carry a well-formed one.
     *
     * A malformed `method` read as null would claim "not proven yet", so it drops
     * the whole object instead.
     *
     * @internal
     */
    public static function fromValue(mixed $value): ?self
    {
        if (!is_array($value) || !NullableToken::isWellFormed($value, 'method') || !NullableToken::isWellFormed($value, 'reason')) {
            return null;
        }

        return new self(NullableToken::read($value, 'method'), NullableToken::read($value, 'reason'));
    }
}
