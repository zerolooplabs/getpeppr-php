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
        /** Lifecycle status, e.g. verifying, no_registry, active, verification_failed. Open set. */
        public readonly ?string $status,
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
            environment: Data::string($data, 'environment'),
            createdAt: Data::string($data, 'createdAt'),
            created: $created,
            httpStatus: $httpStatus,
            resultCode: $resultCode,
            requestId: $requestId,
            data: $data,
        );
    }

    /** @return array<array-key, mixed> The decoded entity. */
    public function toArray(): array
    {
        return $this->data;
    }
}
