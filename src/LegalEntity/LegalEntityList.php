<?php

declare(strict_types=1);

namespace Getpeppr\LegalEntity;

/** One page of `GET /v1/legal-entities`, newest first. */
final class LegalEntityList
{
    /** @param list<LegalEntity> $data */
    public function __construct(
        public readonly array $data,
        public readonly ?int $totalCount,
        public readonly ?int $offset,
        public readonly ?int $limit,
        public readonly ?bool $hasMore,
        public readonly int $httpStatus,
        public readonly ?string $resultCode,
        public readonly ?string $requestId,
    ) {
    }
}
