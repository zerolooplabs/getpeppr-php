<?php

declare(strict_types=1);

namespace Getpeppr\Invoice;

use Getpeppr\Http\Data;

/**
 * The answer to an accepted send (`201` of `POST /v1/invoices/import`).
 *
 * Accepted for transmission is not delivered: read the status with
 * `Client::getInvoice()` or listen to the `invoice.*` webhooks.
 */
final class ImportResult
{
    /**
     * @param list<array<array-key, mixed>> $warnings
     * @param array<array-key, mixed>|null $rulebook
     * @param array<array-key, mixed>|null $transmission
     * @param array<array-key, mixed> $data
     */
    private function __construct(
        /** Provider document id; accepted by `getInvoice()`. */
        public readonly ?string $providerDocumentId,
        /** getpeppr submission id, the `invoiceId` your webhooks carry. Null when the local record could not be written. */
        public readonly ?string $submissionId,
        /** Status at submission time, usually `submitted`. */
        public readonly ?string $status,
        /**
         * Set when this same document (sender, recipient, number) was already
         * sent more than 15 minutes ago: a SECOND copy was accepted. Check it.
         */
        public readonly ?string $duplicateOf,
        /** Non-blocking warnings, e.g. `recipient_endpoint_mismatch`. Never turned into an exception. */
        public readonly array $warnings,
        /** The rulebook the document was validated against; null when validation was skipped. */
        public readonly ?array $rulebook,
        /** What getpeppr does not guarantee about your bytes (`bytePreservation: not_guaranteed`). */
        public readonly ?array $transmission,
        public readonly ?string $createdAt,
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
            providerDocumentId: Data::string($data, 'providerDocumentId') ?? Data::string($data, 'id'),
            submissionId: Data::string($data, 'submissionId'),
            status: Data::string($data, 'status'),
            duplicateOf: Data::string($data, 'duplicateOf'),
            warnings: Data::listOfObjects($data, 'warnings'),
            rulebook: Data::object($data, 'rulebook'),
            transmission: Data::object($data, 'transmission'),
            createdAt: Data::string($data, 'createdAt'),
            httpStatus: $httpStatus,
            resultCode: $resultCode,
            requestId: $requestId,
            data: $data,
        );
    }

    /**
     * The decoded response body, for fields this version does not map.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
