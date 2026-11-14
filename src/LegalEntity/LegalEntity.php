<?php

declare(strict_types=1);

namespace Getpeppr\LegalEntity;

use Getpeppr\Http\Data;

/** A platform sub-tenant (customer of your platform), as `/v1/legal-entities` returns it. */
final class LegalEntity
{
    /** @param array<array-key, mixed> $data */
    private function __construct(
        public readonly ?string $id,
        /** Your stable reference; usable as `Sender::externalSubTenant()`. */
        public readonly ?string $externalId,
        public readonly ?string $companyName,
        public readonly ?string $country,
        /**
         * Lifecycle status, e.g. verifying, no_registry, active, verification_failed, and
         * suspended (send-only entities only). Open set. `active` means ready for the
         * entity's `networkRole`.
         */
        public readonly ?string $status,
        /**
         * send_and_receive or send_only, fixed at creation. Null when the response did not
         * carry it: an absent role is unknown, never read it as send_and_receive. Open set.
         */
        public readonly ?string $networkRole,
        /**
         * The contract reference you declared at creation. Null when you declared none, and
         * also when the response did not carry it as a string: `toArray()['customerReference']`
         * holds what the response said.
         */
        public readonly ?string $customerReference,
        /** Who receives the entity's invoices. Null when the response did not carry a well-formed object. */
        public readonly ?Receiving $receiving,
        /** Present only while `status` is `suspended` (send-only); null also when malformed: `status` decides. */
        public readonly ?Suspension $suspension,
        /** On a send-only entity only: how its representative's authority was proven. */
        public readonly ?Authorisation $authorisation,
        public readonly ?string $environment,
        public readonly ?string $createdAt,
        /** On `createLegalEntity()` only: true when created (202), false when it already existed (200). Null on reads. */
        public readonly ?bool $created,
        public readonly int $httpStatus,
        public readonly ?string $resultCode,
        public readonly ?string $requestId,
        private readonly array $data,
    ) {
    }

    /**
     * @internal
     * @param array<array-key, mixed> $data
     */
    public static function fromData(array $data, int $httpStatus, ?string $resultCode, ?string $requestId, ?bool $created = null): self
    {
        return new self(
            id: Data::string($data, 'id'),
            externalId: Data::string($data, 'externalId'),
            companyName: Data::string($data, 'companyName'),
            country: Data::string($data, 'country'),
            status: Data::string($data, 'status'),
            networkRole: self::nonEmpty(Data::string($data, 'networkRole')),
            customerReference: Data::string($data, 'customerReference'),
            receiving: Receiving::fromValue($data['receiving'] ?? null),
            suspension: Suspension::fromValue($data['suspension'] ?? null),
            authorisation: Authorisation::fromValue($data['authorisation'] ?? null),
            environment: Data::string($data, 'environment'),
            createdAt: Data::string($data, 'createdAt'),
            created: $created,
            httpStatus: $httpStatus,
            resultCode: $resultCode,
            requestId: $requestId,
            data: $data,
        );
    }

    private static function nonEmpty(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /** @return array<array-key, mixed> The decoded entity. */
    public function toArray(): array
    {
        return $this->data;
    }
}
