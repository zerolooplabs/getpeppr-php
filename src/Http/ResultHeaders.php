<?php

declare(strict_types=1);

namespace Getpeppr\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * The result contract getpeppr publishes in response headers.
 *
 * Every field is optional, and independently so: a response the hosting
 * platform wrote on its own carries none of them, and a proxy may strip some.
 * An unreadable value is ABSENT, never repaired into a plausible one. A human
 * sentence may be cleaned for printing; a machine token is compared, so it is
 * either exactly what the server sent or nothing.
 *
 * @internal
 */
final class ResultHeaders
{
    /** Absurdity guards, several times the catalogue's real budget. */
    private const MAX_BYTES_TOKEN = 256;
    private const MAX_BYTES_REMEDIATION = 64;
    private const MAX_BYTES_MESSAGE = 1024;
    private const MAX_BYTES_DOCS = 2048;
    private const MAX_BYTES_BOOLEAN = 32;

    /** C0 controls, DEL and C1. */
    private const CONTROL_CHARACTERS = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u';

    /** An absolute https URL, matched on the raw value: no userinfo, no backslash, no whitespace. */
    private const STRICT_HTTPS_URL = '~^https://[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?(?::\d{1,5})?(?:/[^\s\\\\@]*)?$~i';

    private function __construct(
        public readonly ?string $requestId,
        public readonly ?string $resultCode,
        public readonly ?string $publicMessage,
        public readonly ?bool $retryable,
        public readonly ?string $remediation,
        public readonly ?string $resultDocs,
        public readonly ?string $vercelId,
        public readonly ?int $retryAfter,
    ) {
    }

    public static function fromResponse(#[\SensitiveParameter] ResponseInterface $response): self
    {
        return new self(
            requestId: self::machineToken(self::header($response, 'Getpeppr-Request-Id'), self::MAX_BYTES_TOKEN),
            resultCode: self::machineToken(self::header($response, 'Getpeppr-Result-Code'), self::MAX_BYTES_TOKEN),
            publicMessage: self::sentence(self::header($response, 'Getpeppr-Result-Message'), self::MAX_BYTES_MESSAGE),
            retryable: self::boolean(self::header($response, 'Getpeppr-Retryable')),
            remediation: self::machineToken(self::header($response, 'Getpeppr-Remediation'), self::MAX_BYTES_REMEDIATION),
            resultDocs: self::docsUrl(self::header($response, 'Getpeppr-Result-Docs')),
            vercelId: self::machineToken(self::header($response, 'x-vercel-id'), self::MAX_BYTES_TOKEN),
            retryAfter: self::deltaSeconds(self::header($response, 'Retry-After')),
        );
    }

    private static function header(ResponseInterface $response, string $name): ?string
    {
        return $response->hasHeader($name) ? $response->getHeaderLine($name) : null;
    }

    private static function bounded(?string $raw, int $maxBytes): ?string
    {
        if ($raw === null || $raw === '' || strlen($raw) > $maxBytes) {
            return null;
        }

        return $raw;
    }

    /** True when the value is valid UTF-8 and carries no control character. */
    private static function isClean(string $value): bool
    {
        return preg_match(self::CONTROL_CHARACTERS, $value) === 0;
    }

    private static function machineToken(?string $raw, int $maxBytes): ?string
    {
        $value = self::bounded($raw, $maxBytes);
        if ($value === null || !self::isClean($value)) {
            return null;
        }
        // No legitimate token carries whitespace, Unicode whitespace included.
        if (preg_match('/\s/u', $value) !== 0) {
            return null;
        }

        return $value;
    }

    private static function sentence(?string $raw, int $maxBytes): ?string
    {
        $value = self::bounded($raw, $maxBytes);
        if ($value === null) {
            return null;
        }
        $cleaned = preg_replace(self::CONTROL_CHARACTERS, ' ', $value);
        if ($cleaned === null) {
            return null; // not valid UTF-8
        }
        $cleaned = trim($cleaned);

        return $cleaned === '' ? null : $cleaned;
    }

    private static function boolean(?string $raw): ?bool
    {
        $token = self::machineToken($raw, self::MAX_BYTES_BOOLEAN);

        return match ($token) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    private static function docsUrl(?string $raw): ?string
    {
        $value = self::bounded($raw, self::MAX_BYTES_DOCS);
        if ($value === null || !self::isClean($value)) {
            return null;
        }

        return preg_match(self::STRICT_HTTPS_URL, $value) === 1 ? $value : null;
    }

    private static function deltaSeconds(?string $raw): ?int
    {
        $value = self::bounded($raw, 6);
        if ($value === null || preg_match('/^\d{1,6}$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
