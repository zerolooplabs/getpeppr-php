<?php

declare(strict_types=1);

namespace Getpeppr\LegalEntity;

/** One page of `GET /v1/legal-entities`, newest first. */
final class LegalEntityList
{
    /** @var list<LegalEntity> */
    public readonly array $data;

    /**
     * @param list<LegalEntity> $entities
     * @param array<array-key, mixed> $raw The decoded response.
     */
    public function __construct(
        array $entities,
        public readonly ?int $totalCount,
        public readonly ?int $offset,
        public readonly ?int $limit,
        public readonly ?bool $hasMore,
        public readonly int $httpStatus,
        public readonly ?string $resultCode,
        public readonly ?string $requestId,
        private readonly array $raw = [],
    ) {
        $this->data = $entities;
    }

    /** @return array<array-key, mixed> The decoded response body, for fields this version does not map. */
    public function toArray(): array
    {
        return $this->raw;
    }
}
