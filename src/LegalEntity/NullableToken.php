<?php

declare(strict_types=1);

namespace Getpeppr\LegalEntity;

/**
 * A member the API sends as a non-empty string or null.
 *
 * Absent, empty or of another type is malformed, and the caller drops the whole
 * object: reading it as null would state something the API never said.
 *
 * @internal
 */
final class NullableToken
{
    /** @param array<array-key, mixed> $data */
    public static function isWellFormed(array $data, string $key): bool
    {
        if (!array_key_exists($key, $data)) {
            return false;
        }
        $value = $data[$key];

        return $value === null || (is_string($value) && $value !== '');
    }

    /** @param array<array-key, mixed> $data Checked with isWellFormed() first. */
    public static function read(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
