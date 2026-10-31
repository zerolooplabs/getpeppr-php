<?php

declare(strict_types=1);

namespace Getpeppr\Exception;

/**
 * A webhook request that must not be trusted. Answer it with a 4xx and do not act on it.
 *
 * `reason` is one of: missing_body, missing_signature, missing_secret,
 * malformed_signature, timestamp_outside_tolerance, signature_mismatch,
 * invalid_payload. The message never contains the secret or the body.
 */
class WebhookVerificationException extends GetpepprException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message . ' (' . $reason . ')');
    }
}
