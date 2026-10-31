<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A response whose header values reach the reader verbatim. Some PSR-7
 * implementations refuse control characters, others let them through: the
 * reader must hold either way.
 */
final class RawHeaderResponse implements ResponseInterface
{
    /** @var array<string, string> */
    private readonly array $raw;
    private readonly Response $inner;

    /** @param array<string, string> $headers */
    public function __construct(int $status, array $headers)
    {
        $this->raw = array_change_key_case($headers);
        $this->inner = new Response($status);
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->raw);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->raw[strtolower($name)] ?? '';
    }

    /** @return list<string> */
    public function getHeader(string $name): array
    {
        return $this->hasHeader($name) ? [$this->getHeaderLine($name)] : [];
    }

    /** @return array<string, list<string>> */
    public function getHeaders(): array
    {
        return array_map(static fn (string $value): array => [$value], $this->raw);
    }

    public function getStatusCode(): int
    {
        return $this->inner->getStatusCode();
    }

    public function getReasonPhrase(): string
    {
        return $this->inner->getReasonPhrase();
    }

    public function getProtocolVersion(): string
    {
        return $this->inner->getProtocolVersion();
    }

    public function getBody(): StreamInterface
    {
        return $this->inner->getBody();
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        throw new \LogicException('immutable test double');
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        throw new \LogicException('immutable test double');
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        throw new \LogicException('immutable test double');
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        throw new \LogicException('immutable test double');
    }

    public function withoutHeader(string $name): MessageInterface
    {
        throw new \LogicException('immutable test double');
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        throw new \LogicException('immutable test double');
    }
}
