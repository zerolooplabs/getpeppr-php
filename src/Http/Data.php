<?php

declare(strict_types=1);

namespace Getpeppr\Http;

/**
 * Typed, lenient reads from a decoded JSON object: a missing or mistyped field
 * is null, never an invented value.
 *
 * @internal
 */
final class Data
{
    /** @param array<array-key, mixed> $data */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<array-key, mixed> $data */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /** @param array<array-key, mixed> $data */
    public static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>|null
     */
    public static function object(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    public static function listOfObjects(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
