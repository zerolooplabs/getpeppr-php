<?php

declare(strict_types=1);

namespace Getpeppr;

use Getpeppr\Http\Data;

/**
 * A verified webhook event.
 *
 * Deliveries are at least once: the same event can arrive again, with the
 * same `id`. Reserve the id atomically on your side (for example an insert
 * under a unique constraint) BEFORE acting on the event.
 */
final class WebhookEvent
{
    /**
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed> $payload
     */
    private function __construct(
        /** Stable across redeliveries: deduplicate on it. */
        public readonly string $id,
        /** e.g. invoice.sent, invoice.error, legal_entity.registered, test.ping. Open set. */
        public readonly ?string $type,
        /** sandbox or production; absent on test.ping and older events. */
        public readonly ?string $environment,
        public readonly ?string $createdAt,
        public readonly array $data,
        private readonly array $payload,
    ) {
    }

    /**
     * @internal
     * @param array<array-key, mixed> $payload
     */
    public static function fromPayload(string $id, array $payload): self
    {
        return new self(
            id: $id,
            type: Data::string($payload, 'type'),
            environment: Data::string($payload, 'environment'),
            createdAt: Data::string($payload, 'createdAt'),
            data: Data::object($payload, 'data') ?? [],
            payload: $payload,
        );
    }

    /** @return array<array-key, mixed> The whole decoded event. */
    public function toArray(): array
    {
        return $this->payload;
    }
}
