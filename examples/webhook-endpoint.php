<?php

/*
 * A minimal webhook endpoint.
 *
 *   GETPEPPR_WEBHOOK_SECRET=whsec_… php -S 0.0.0.0:8080 webhook-endpoint.php
 *
 * It verifies the signature, then reserves the event id before acting on it,
 * because a delivery can arrive more than once. If handling fails, the
 * reservation is released and the endpoint answers 500, so the redelivery is
 * handled rather than skipped. A file stands in for your database here; in
 * production, insert the id under a unique constraint in the same transaction
 * as the effect.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Getpeppr\Exception\WebhookVerificationException;
use Getpeppr\Webhook;

$rawBody = (string) file_get_contents('php://input');
$signature = is_string($_SERVER['HTTP_GETPEPPR_SIGNATURE'] ?? null) ? $_SERVER['HTTP_GETPEPPR_SIGNATURE'] : '';

try {
    $event = Webhook::verify($rawBody, $signature, (string) getenv('GETPEPPR_WEBHOOK_SECRET'));
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    error_log('getpeppr webhook refused: ' . $e->reason);
    exit;
}

// Atomic reservation: fopen mode 'x' fails when the file already exists.
$seen = sys_get_temp_dir() . '/getpeppr-event-' . hash('sha256', $event->id);
$reserved = @fopen($seen, 'x');
if ($reserved === false) {
    error_log("getpeppr event {$event->id}: already handled, ignored");
    http_response_code(200);
    exit;
}
fclose($reserved);

try {
    handle($event);
} catch (Throwable $e) {
    unlink($seen); // release the reservation: the redelivery must be handled
    http_response_code(500);
    exit;
}

http_response_code(200);

function handle(Getpeppr\WebhookEvent $event): void
{
    error_log("getpeppr event {$event->id} ({$event->type}): handled");
}
