<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Contract\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Spaces requests so one sandbox key stays under its 10 requests per minute. */
final class PacedHttpClient implements ClientInterface
{
    private float $last = 0.0;

    public function __construct(private readonly ClientInterface $inner, private readonly float $minIntervalSeconds = 7.0)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $wait = $this->last + $this->minIntervalSeconds - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        try {
            return $this->inner->sendRequest($request);
        } finally {
            $this->last = microtime(true);
        }
    }
}
