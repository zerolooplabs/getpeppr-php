<?php

declare(strict_types=1);

namespace Getpeppr;

use Getpeppr\Exception\UnreadableResponseException;
use Getpeppr\Http\Data;
use Getpeppr\Http\ErrorClassifier;
use Getpeppr\Http\ResultHeaders;
use Getpeppr\Invoice\ImportResult;
use Getpeppr\Invoice\Invoice;
use Getpeppr\LegalEntity\LegalEntity;
use Getpeppr\LegalEntity\LegalEntityList;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * getpeppr API client: send a UBL document you already generated, read its
 * status, and manage the customers of a platform (Legal Entities).
 *
 * Every call is made once. The package never retries on its own: an exception
 * tells you, by its class, whether resending can succeed (see the README).
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.getpeppr.dev/v1';

    /** Wrapped so that var_dump, print_r, var_export and serialize never show it. */
    private readonly \SensitiveParameterValue $apiKey;
    private readonly string $baseUrl;
    private readonly ClientInterface $httpClient;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param string $apiKey `sk_sandbox_…` or `sk_live_…`. The environment is the one the API assigns to the key.
     * @param string|null $baseUrl Defaults to https://api.getpeppr.dev/v1. Plain http is accepted for localhost only.
     * @param ClientInterface|null $httpClient Any PSR-18 client; discovered when omitted. Configure timeouts on it.
     */
    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        ?string $baseUrl = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        if (preg_match('/^[\x21-\x7E]+$/D', $apiKey) !== 1) {
            throw new \InvalidArgumentException('The API key must be a non-empty string of visible ASCII characters.');
        }
        $this->apiKey = new \SensitiveParameterValue($apiKey);
        $this->baseUrl = self::validatedBaseUrl($baseUrl ?? self::DEFAULT_BASE_URL);
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * Send a UBL Invoice or CreditNote over Peppol (`POST /v1/invoices/import`).
     *
     * The bytes are sent as they are, base64-encoded; the package never alters
     * the document. `$recipientPeppolId` (`scheme:identifier`) is the sole
     * routing authority.
     *
     * @param string $ubl The XML document, exactly as generated.
     * @param Sender|null $sender Send for one of your platform's customers (master key).
     * @param string|null $idempotencyKey An opaque id created once per logical send and stored with the
     *                                     invoice; reuse it only to resend exactly the same request.
     * @param bool $skipValidation Bypass the Peppol rulebooks for this call (`x-skip-validation: true`).
     */
    public function importInvoice(
        #[\SensitiveParameter] string $ubl,
        #[\SensitiveParameter] string $filename,
        #[\SensitiveParameter] string $recipientPeppolId,
        #[\SensitiveParameter] ?Sender $sender = null,
        #[\SensitiveParameter] ?string $idempotencyKey = null,
        bool $skipValidation = false,
    ): ImportResult {
        $payload = [
            'file' => base64_encode($ubl),
            'filename' => $filename,
            'to' => ['peppolId' => $recipientPeppolId],
        ];
        if ($sender !== null) {
            $payload['sender'] = $sender->toArray();
        }

        $headers = [];
        if ($idempotencyKey !== null) {
            if (preg_match('/^[\x20-\x7E]*$/D', $idempotencyKey) !== 1) {
                throw new \InvalidArgumentException('The idempotency key must contain printable ASCII characters only.');
            }
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        if ($skipValidation) {
            $headers['x-skip-validation'] = 'true';
        }

        [$data, $status, $meta] = $this->call('POST', '/invoices/import', $payload, $headers, ['providerDocumentId', 'id']);

        return ImportResult::fromResponse($data, $status, $meta->resultCode, $meta->requestId);
    }

    /**
     * Read a document and its status (`GET /v1/invoices/{id}`).
     *
     * @param string $id The provider document id or the submission id; both reach the same document.
     */
    public function getInvoice(#[\SensitiveParameter] string $id): Invoice
    {
        [$data, $status, $meta] = $this->call('GET', '/invoices/' . self::pathSegment($id), identifiedBy: ['id', 'providerDocumentId', 'submissionId']);

        return Invoice::fromResponse($data, $status, $meta->resultCode, $meta->requestId);
    }

    /**
     * Create a customer of your platform (`POST /v1/legal-entities`, master key).
     *
     * Idempotent by `externalId`: replaying the same entity returns it with `created === false`.
     *
     * @param array<string, mixed> $legalEntity externalId, companyName, country, address, identifier…
     */
    public function createLegalEntity(#[\SensitiveParameter] array $legalEntity): LegalEntity
    {
        [$data, $status, $meta] = $this->call('POST', '/legal-entities', $legalEntity, identifiedBy: ['id']);

        return LegalEntity::fromData($data, $status, $meta->resultCode, $meta->requestId, created: $status === 202);
    }

    /** List the customers of your platform, newest first (`GET /v1/legal-entities`, master key). */
    public function listLegalEntities(#[\SensitiveParameter] ?string $externalId = null, ?int $limit = null, ?int $offset = null): LegalEntityList
    {
        $query = array_filter(
            ['externalId' => $externalId, 'limit' => $limit, 'offset' => $offset],
            static fn (string|int|null $value): bool => $value !== null,
        );
        $path = '/legal-entities' . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        [$data, $status, $meta] = $this->call('GET', $path, identifiedBy: ['data']);

        $entities = [];
        foreach (Data::listOfObjects($data, 'data') as $entity) {
            $entities[] = LegalEntity::fromData($entity, $status, $meta->resultCode, $meta->requestId);
        }
        $pagination = Data::object($data, 'pagination') ?? [];

        return new LegalEntityList(
            data: $entities,
            totalCount: Data::int($pagination, 'total_count'),
            offset: Data::int($pagination, 'offset'),
            limit: Data::int($pagination, 'limit'),
            hasMore: Data::bool($pagination, 'has_more'),
            httpStatus: $status,
            resultCode: $meta->resultCode,
            requestId: $meta->requestId,
        );
    }

    /** Read one customer of your platform (`GET /v1/legal-entities/{id}`, master key). */
    public function getLegalEntity(#[\SensitiveParameter] string $id): LegalEntity
    {
        [$data, $status, $meta] = $this->call('GET', '/legal-entities/' . self::pathSegment($id), identifiedBy: ['id']);

        return LegalEntity::fromData($data, $status, $meta->resultCode, $meta->requestId);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => '[redacted]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('A getpeppr client holds an API key and cannot be serialised.');
    }

    /**
     * @param array<array-key, mixed>|null $payload
     * @param array<string, string> $headers
     * @param list<string> $identifiedBy A successful body must carry at least one of these fields.
     * @return array{0: array<array-key, mixed>, 1: int, 2: ResultHeaders}
     */
    private function call(
        string $method,
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] ?array $payload = null,
        #[\SensitiveParameter] array $headers = [],
        array $identifiedBy = [],
    ): array {
        $apiKey = $this->apiKey->getValue();
        assert(is_string($apiKey));
        $request = $this->requestFactory->createRequest($method, $this->baseUrl . $path)
            ->withHeader('Authorization', 'Bearer ' . $apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'getpeppr-php/' . self::VERSION);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($payload !== null) {
            // Deliberately without JSON_THROW_ON_ERROR: a JsonException would capture
            // a json_encode frame whose argument is the whole payload.
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new \InvalidArgumentException(
                    'The request cannot be encoded as JSON: every string must be valid UTF-8 '
                    . '(convert Latin-1 text with mb_convert_encoding() first) and every number finite. Nothing was sent.',
                );
            }
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));
        }

        $response = $this->send($request);
        $status = $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            throw ErrorClassifier::fromResponse($response);
        }

        $meta = ResultHeaders::fromResponse($response);

        return [$this->decode($response, $status, $meta, $identifiedBy), $status, $meta];
    }

    private function send(#[\SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (\Throwable $failure) {
            // Any failure of the HTTP client, PSR-18 or not, is a transport failure:
            // the request may have left. Never chained: it may carry the request.
            throw ErrorClassifier::fromTransportFailure($failure);
        }
    }

    /**
     * @param list<string> $identifiedBy
     * @return array<array-key, mixed>
     */
    private function decode(#[\SensitiveParameter] ResponseInterface $response, int $status, ResultHeaders $meta, array $identifiedBy): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $decoded = null;
        }
        if (is_array($decoded) && Data::identifies($decoded, $identifiedBy)) {
            return $decoded;
        }

        // A success we cannot read is not a failure to repeat blindly: the call may have taken effect.
        throw new UnreadableResponseException(
            sprintf(
                'The request succeeded (HTTP %d) but its response could not be read. It may have taken effect: '
                . 'check before repeating it (for a send, read the document status or resend with the same Idempotency-Key), '
                . 'and quote the request id to support.',
                $status,
            ),
            status: $status,
            resultCode: $meta->resultCode,
            remediation: $meta->remediation,
            retryable: $meta->retryable,
            requestId: $meta->requestId,
            resultDocs: $meta->resultDocs,
            vercelId: $meta->vercelId,
            publicMessage: $meta->publicMessage,
        );
    }

    private static function pathSegment(#[\SensitiveParameter] string $id): string
    {
        if (trim($id) === '' || $id === '.' || $id === '..') {
            throw new \InvalidArgumentException('The id cannot be blank, "." or "..".');
        }

        return rawurlencode($id);
    }

    private static function validatedBaseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);

        if (
            !is_array($parts)
            || $host === ''
            || !($scheme === 'https' || ($scheme === 'http' && $local))
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('The base URL must be an https URL without credentials, query or fragment (plain http is allowed for localhost only).');
        }

        return rtrim($baseUrl, '/');
    }
}
