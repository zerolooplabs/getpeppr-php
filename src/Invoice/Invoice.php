<?php

declare(strict_types=1);

namespace Getpeppr\Invoice;

use Getpeppr\Http\Data;

/** A document as `GET /v1/invoices/{id}` returns it. */
final class Invoice
{
    /** @param array<array-key, mixed> $data */
    private function __construct(
        public readonly ?string $id,
        public readonly ?string $submissionId,
        public readonly ?string $providerDocumentId,
        public readonly ?string $number,
        /** e.g. submitted, delivered, accepted, rejected, failed, no_action. Open set: compare with values you know. */
        public readonly ?string $status,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
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
    public static function fromResponse(array $data, int $httpStatus, ?string $resultCode, ?string $requestId): self
    {
        return new self(
            id: Data::string($data, 'id'),
            submissionId: Data::string($data, 'submissionId'),
            providerDocumentId: Data::string($data, 'providerDocumentId'),
            number: Data::string($data, 'number'),
            status: Data::string($data, 'status'),
            createdAt: Data::string($data, 'createdAt'),
            updatedAt: Data::string($data, 'updatedAt'),
            httpStatus: $httpStatus,
            resultCode: $resultCode,
            requestId: $requestId,
            data: $data,
        );
    }

    /** @return array<array-key, mixed> The decoded response body. */
    public function toArray(): array
    {
        return $this->data;
    }
}
