<?php

declare(strict_types=1);

/*
 * Refresh tests/fixtures/api-results.json from the getpeppr result catalogue.
 *
 *   php tools/sync-api-results.php <path/to/api-results.generated.ts> <source-commit>
 *
 * The catalogue is generated in the getpeppr repository
 * (packages/website/src/data/api-results.generated.ts) and published at
 * https://getpeppr.dev/docs/api-results/. This keeps the rows that one of the
 * operations of this package can answer with, plus the edge results, which can
 * answer any path. The error-matrix test is driven by this file.
 */

const OPERATIONS = [
    'POST /v1/invoices/import',
    'GET /v1/invoices/{id}',
    'POST /v1/legal-entities',
    'GET /v1/legal-entities',
    'GET /v1/legal-entities/{id}',
];

if ($argc !== 3) {
    fwrite(STDERR, "usage: php tools/sync-api-results.php <api-results.generated.ts> <source-commit>\n");
    exit(2);
}

[, $path, $commit] = $argv;
$source = file_get_contents($path);
if ($source === false) {
    fwrite(STDERR, "cannot read {$path}\n");
    exit(1);
}

$rows = [];
foreach (explode("\n", $source) as $line) {
    $line = trim($line);
    if (!str_starts_with($line, '{"code"')) {
        continue;
    }
    /** @var array{code: string, status: int, remediation: string, retryable: bool, scope: string, operations: list<string>} $row */
    $row = json_decode(rtrim($line, ','), true, 512, JSON_THROW_ON_ERROR);
    $touches = array_values(array_intersect($row['operations'], OPERATIONS));
    if ($row['scope'] !== 'edge' && $touches === []) {
        continue;
    }
    $rows[] = [
        'code' => $row['code'],
        'status' => $row['status'],
        'retryable' => $row['retryable'],
        'remediation' => $row['remediation'],
        'scope' => $row['scope'],
        'operations' => $touches,
    ];
}

usort($rows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

$fixture = [
    'source' => [
        'file' => 'packages/website/src/data/api-results.generated.ts',
        'commit' => $commit,
        'published' => 'https://getpeppr.dev/docs/api-results/',
        'operations' => OPERATIONS,
    ],
    'results' => $rows,
];

$target = __DIR__ . '/../tests/fixtures/api-results.json';
file_put_contents($target, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
fwrite(STDOUT, count($rows) . " results written to tests/fixtures/api-results.json\n");
