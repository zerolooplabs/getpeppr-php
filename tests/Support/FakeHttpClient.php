<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that records every request and answers from a queue.
 * A queued Throwable is thrown instead of answering.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $queue;

    public function __construct(ResponseInterface|\Throwable ...$queue)
    {
        $this->queue = array_values($queue);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeHttpClient: no response queued for ' . $request->getMethod() . ' ' . $request->getUri());
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('FakeHttpClient: no request was sent');
        }

        return $last;
    }
}
