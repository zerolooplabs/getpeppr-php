<?php

declare(strict_types=1);

namespace Getpeppr\Tests\Support;

use Nyholm\Psr7\Response;

/** Builders for responses shaped like the ones getpeppr and its host send. */
final class Responses
{
    /**
     * A response getpeppr wrote: JSON body plus the result headers.
     *
     * @param array<string, mixed>|list<mixed> $body
     * @param array<string, string> $extraHeaders
     */
    public static function getpeppr(
        int $status,
        string $code,
        string $remediation,
        bool $retryable,
        array $body = [],
        array $extraHeaders = [],
    ): Response {
        return new Response($status, array_merge([
            'Content-Type' => 'application/json',
            'Getpeppr-Request-Id' => 'req_0123456789abcdef0123456789abcdef',
            'Getpeppr-Result-Code' => $code,
            'Getpeppr-Result-Message' => 'Fixed catalogue sentence for ' . $code . '.',
            'Getpeppr-Retryable' => $retryable ? 'true' : 'false',
            'Getpeppr-Remediation' => $remediation,
        ], $extraHeaders), json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * A response the hosting platform wrote on its own: no getpeppr header.
     *
     * @param array<string, string> $headers
     */
    public static function host(int $status, string $body = '', array $headers = []): Response
    {
        return new Response($status, array_merge([
            'Content-Type' => 'text/plain; charset=utf-8',
            'x-vercel-id' => 'lhr1::iad1::abcde-1730000000000-0123456789ab',
        ], $headers), $body);
    }
}
