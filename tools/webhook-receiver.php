<?php

/*
 * Development receiver for the real webhook proof (not shipped: tools/ is export-ignored).
 *
 *   WEBHOOK_RUN=live php -S 0.0.0.0:8099 tools/webhook-receiver.php
 *
 * Reads the endpoint secret from build/webhook-secret, verifies every delivery
 * with the package, records what it saw in build/webhook-$WEBHOOK_RUN/deliveries.jsonl
 * and keeps each raw body and signature next to it. It answers 500 to the first
 * delivery of an event, so that getpeppr delivers it again, then 200.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Getpeppr\Exception\WebhookVerificationException;
use Getpeppr\Webhook;

$base = __DIR__ . '/../build';
$run = preg_replace('/[^a-z0-9-]/', '', (string) getenv('WEBHOOK_RUN'));
$dir = $base . '/webhook-' . ($run !== '' && $run !== null ? $run : 'default');
if (!is_dir($dir)) {
    mkdir($dir, 0o775, true);
}

$raw = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_GETPEPPR_SIGNATURE'] ?? '');
$secret = is_file($base . '/webhook-secret') ? trim((string) file_get_contents($base . '/webhook-secret')) : '';

$record = [
    'at' => gmdate('Y-m-d\TH:i:s\Z'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'signatureHeaderShape' => preg_replace('/s=[0-9a-f]{64}/', 's=<64 hex>', $signature),
    'bodySha256' => hash('sha256', $raw),
];

try {
    $event = Webhook::verify($raw, $signature, $secret);
    $marker = $dir . '/seen-' . hash('sha256', $event->id);
    $first = !is_file($marker);
    touch($marker);
    $status = $first ? 500 : 200;
    $record += ['verified' => true, 'eventId' => $event->id, 'type' => $event->type, 'delivery' => $first ? 'first' : 'repeat', 'answered' => $status];
} catch (WebhookVerificationException $e) {
    $status = 400;
    $record += ['verified' => false, 'reason' => $e->reason, 'answered' => $status];
}

$n = count(glob($dir . '/*.body') ?: []) + 1;
file_put_contents(sprintf('%s/%03d.body', $dir, $n), $raw);
file_put_contents(sprintf('%s/%03d.sig', $dir, $n), $signature);
file_put_contents($dir . '/deliveries.jsonl', json_encode($record + ['file' => sprintf('%03d', $n)], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

http_response_code($status);
header('Content-Type: text/plain');
echo $status === 200 ? 'ok' : 'retry';
