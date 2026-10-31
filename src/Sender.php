<?php

declare(strict_types=1);

namespace Getpeppr;

/**
 * The sub-tenant a platform sends for. Exactly one selector, by construction.
 *
 * Requires a master key: a standard key that names a sender is refused with
 * `403 authz.scope_denied` (InvalidRequestException). Omit the sender to send
 * under your own account's identity.
 */
final class Sender
{
    /** Wrapped so that var_dump, print_r, var_export and serialize never show it. */
    private readonly \SensitiveParameterValue $value;

    private function __construct(
        private readonly string $field,
        #[\SensitiveParameter] string $value,
    ) {
        $this->value = new \SensitiveParameterValue($value);
    }

    /** The getpeppr Legal Entity id (`id` of a LegalEntity). */
    public static function legalEntity(#[\SensitiveParameter] string $legalEntityId): self
    {
        return new self('legalEntityId', self::nonBlank($legalEntityId));
    }

    /** Your own stable reference for the sub-tenant (`externalId` of a LegalEntity). */
    public static function externalSubTenant(#[\SensitiveParameter] string $externalId): self
    {
        return new self('externalSubTenantId', self::nonBlank($externalId));
    }

    /**
     * @internal
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [$this->field => $this->value->getValue()];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['field' => $this->field, 'value' => '[redacted]'];
    }

    private static function nonBlank(#[\SensitiveParameter] string $value): string
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException('A sender selector cannot be blank.');
        }

        return $value;
    }
}
