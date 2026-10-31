<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Support;

/**
 * Everything an application might write about an exception that comes from
 * this package: message, string form, previous exceptions, public fields,
 * and the arguments captured in the stack frames of the package itself.
 *
 * Frames of the caller (here, the test and PHPUnit) are out of the package's
 * hands and are not inspected; their arguments are whatever the caller passed.
 */
final class LeakProbe
{
    public static function render(\Throwable $e): string
    {
        $out = '';
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $out .= $current->getMessage() . "\n" . (string) $current . "\n";
            $out .= var_export(get_object_vars($current), true) . "\n";
            foreach ($current->getTrace() as $frame) {
                if (!self::isPackageFrame($frame)) {
                    continue;
                }
                $out .= ($frame['class'] ?? '') . '::' . $frame['function'] . "\n";
                $out .= print_r($frame['args'] ?? [], true) . "\n";
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $frame */
    private static function isPackageFrame(array $frame): bool
    {
        $class = $frame['class'] ?? '';

        return is_string($class) && str_starts_with($class, 'Getpeppr\\') && !str_starts_with($class, 'Getpeppr\\Tests\\');
    }
}
