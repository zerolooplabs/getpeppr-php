<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Contract\Support;

use Getpeppr\Client;
use Getpeppr\Exception\GetpepprException;

/**
 * What a PHP developer receives, scenario by scenario, appended to
 * build/contract-evidence.jsonl. Test data only; never a key.
 */
final class Evidence
{
    /** @param array<string, mixed> $facts */
    public static function record(string $scenario, array $facts): void
    {
        $dir = __DIR__ . '/../../../build';
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }
        $line = json_encode(['at' => gmdate('Y-m-d\TH:i:s\Z'), 'package' => Client::VERSION, 'php' => PHP_VERSION, 'scenario' => $scenario] + $facts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($dir . '/contract-evidence.jsonl', $line . "\n", FILE_APPEND);
    }

    /** @return array<string, mixed> */
    public static function ofException(GetpepprException $e): array
    {
        return [
            'exception' => $e::class,
            'status' => $e->status,
            'resultCode' => $e->resultCode,
            'remediation' => $e->remediation,
            'retryable' => $e->retryable,
            'requestId' => $e->requestId,
            'message' => $e->getMessage(),
        ];
    }
}
