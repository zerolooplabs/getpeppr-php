<?php

declare(strict_types=1);

namespace Getpeppr\LegalEntity;

/**
 * Who receives a Legal Entity's invoices: the `receiving` object of `/v1/legal-entities`.
 *
 * `networkDiscovery` (in `toArray()`) stays the detailed proof; for a send-only
 * entity it describes the discovery of the OTHER access point's publication.
 */
final class Receiving
{
    private function __construct(
        /** getpeppr, elsewhere, none or unknown. Open set: treat a value you do not know like `unknown`. */
        public readonly string $handledBy,
        /** The SMP host serving the entity's identifier, only when `handledBy` is `elsewhere` and the host is known; null otherwise. */
        public readonly ?string $smpHost,
        /** When getpeppr's latest receiving check ran (ISO 8601), including one that could not read the network; null when none ran yet, and for `getpeppr`. */
        public readonly ?string $lastVerifiedAt,
        /** Whether the buyer's responses (accepted, refused, paid…) come back to getpeppr. False for a send-only entity: its documents stop at `delivered`. */
        public readonly bool $lifecycleResponses,
    ) {
    }

    /**
     * The object, or null when the response did not carry a well-formed one.
     *
     * A malformed member drops the whole object: a member is never repaired into
     * null, because null is itself a statement (no SMP host, never verified).
     *
     * @internal
     */
    public static function fromValue(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }
        $handledBy = $value['handledBy'] ?? null;
        $lifecycleResponses = $value['lifecycleResponses'] ?? null;
        if (!is_string($handledBy) || $handledBy === '' || !is_bool($lifecycleResponses)) {
            return null;
        }
        if (!NullableToken::isWellFormed($value, 'smpHost') || !NullableToken::isWellFormed($value, 'lastVerifiedAt')) {
            return null;
        }

        return new self($handledBy, NullableToken::read($value, 'smpHost'), NullableToken::read($value, 'lastVerifiedAt'), $lifecycleResponses);
    }
}
