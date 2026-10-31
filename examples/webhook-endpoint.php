<?php

/*
 * A minimal webhook endpoint.
 *
 *   GETPEPPR_WEBHOOK_SECRET=whsec_… php -S 0.0.0.0:8080 webhook-endpoint.php
 *
 * It verifies the signature, then reserves the event id before acting on it,
 * because a delivery can arrive more than once. A file stands in for your
 * database here; use an insert under a unique constraint in production.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Getpeppr\Exception\WebhookVerificationException;
use Getpeppr\Webhook;

$rawBody = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_GETPEPPR_SIGNATURE'] ?? '';

try {
    $event = Webhook::verify($rawBody, $signature, (string) getenv('GETPEPPR_WEBHOOK_SECRET'));
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    error_log('getpeppr webhook refused: ' . $e->reason);
    exit;
}

// Atomic reservation: fopen mode 'x' fails when the file already exists.
$seen = sys_get_temp_dir() . '/getpeppr-event-' . hash('sha256', $event->id);
$first = @fopen($seen, 'x');
if ($first !== false) {
    fclose($first);
    error_log("getpeppr event {$event->id} ({$event->type}): handled");
} else {
    error_log("getpeppr event {$event->id}: already handled, ignored");
}

http_response_code(200);
