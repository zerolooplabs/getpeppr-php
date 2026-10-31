<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Support;

/**
 * Everything an application might write about an exception that comes from
 * this package: message, string form, previous exceptions, public fields,
 * and the arguments captured in every stack frame the package is responsible
 * for: its own methods, the PHP functions it calls (json_encode…), and the
 * PSR-7/PSR-18 libraries it calls into.
 *
 * Frames of the caller (the test and PHPUnit) are out of the package's hands
 * and are not inspected; their arguments are whatever the caller passed.
 */
final class LeakProbe
{
    public static function render(\Throwable $e): string
    {
        $out = '';
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $out .= $current::class . "\n" . $current->getMessage() . "\n" . (string) $current . "\n";
            $out .= var_export(get_object_vars($current), true) . "\n";
            foreach ($current->getTrace() as $frame) {
                if (!self::isInspected($frame)) {
                    continue;
                }
                $out .= ($frame['class'] ?? '') . '::' . $frame['function'] . "\n";
                $out .= print_r($frame['args'] ?? [], true) . "\n";
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $frame */
    private static function isInspected(array $frame): bool
    {
        $class = is_string($frame['class'] ?? null) ? $frame['class'] : '';
        $file = is_string($frame['file'] ?? null) ? str_replace('\\', '/', $frame['file']) : '';

        if (str_starts_with($class, 'Getpeppr\\Tests\\') || str_starts_with($class, 'PHPUnit\\')) {
            return false;
        }
        if (str_starts_with($class, 'Getpeppr\\')) {
            return true;
        }
        // Called from the package's own code, or from a library the package called into.
        return str_contains($file, '/src/') || (str_contains($file, '/vendor/') && !str_contains($file, '/vendor/phpunit/'));
    }
}
