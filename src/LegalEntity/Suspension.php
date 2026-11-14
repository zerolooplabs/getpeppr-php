<?php

declare(strict_types=1);

namespace Getpeppr\LegalEntity;

/**
 * Why a send-only Legal Entity cannot send: the `suspension` object, present only
 * while `status` is `suspended`.
 *
 * The causes are independent: a receiving registration that comes back never
 * lifts a block by getpeppr, and lifting a block never lifts a receiving cause.
 */
final class Suspension
{
    /** @param non-empty-list<non-empty-string> $reasons */
    private function __construct(
        /**
         * At least one of receiving_registration_not_found, receiving_registration_lost,
         * receiving_registration_unverifiable, blocked_by_getpeppr. Open set: treat a
         * reason you do not know as a reason to contact support.
         *
         * @var non-empty-list<non-empty-string>
         */
        public readonly array $reasons,
        /** When the earliest cause still in force started (ISO 8601). */
        public readonly string $since,
    ) {
    }

    /**
     * The object, or null when the response did not carry a well-formed one.
     *
     * Any malformed reason drops the whole object: keeping the readable reasons
     * of a list would hand you an incomplete remediation that looks complete.
     *
     * @internal
     */
    public static function fromValue(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }
        $reasons = $value['reasons'] ?? null;
        $since = $value['since'] ?? null;
        if (!is_array($reasons) || $reasons === [] || !array_is_list($reasons) || !is_string($since) || $since === '') {
            return null;
        }
        foreach ($reasons as $reason) {
            if (!is_string($reason) || $reason === '') {
                return null;
            }
        }
        /** @var non-empty-list<non-empty-string> $reasons */

        return new self($reasons, $since);
    }
}
