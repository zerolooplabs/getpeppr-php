# getpeppr-php

Send the UBL invoices you already generate in PHP over the Peppol network.

You build the invoice with [`num-num/ubl-invoice`](https://github.com/num-num/ubl-invoice), [`josemmo/einvoicing`](https://github.com/josemmo/einvoicing) or any other tool. This package hands the XML to [getpeppr](https://getpeppr.dev), which validates it against the official Peppol rulebooks and delivers it to the recipient's access point. It does not generate, rewrite or repair your document.

- **Send** a UBL Invoice or CreditNote, for your own company or for one of your platform's customers.
- **Read** a document's status.
- **Manage** your platform's customers (Legal Entities).
- **Verify** webhook signatures without writing cryptography.
- **Know what to do** when a call fails: the exception class tells you whether resending can succeed.

It is a thin PSR-18 client: it uses the HTTP client your application already has (Guzzle, Symfony HttpClient, …), and it never retries on its own.

## Install

```bash
composer require getpeppr/getpeppr-php
```

PHP 8.2 or later. If your project has no PSR-18 client yet, add one, for example `composer require guzzlehttp/guzzle`. It is found automatically.

Create an account and a sandbox key (`sk_sandbox_…`) at **[console.getpeppr.dev](https://console.getpeppr.dev/sign-up?source=php)**. Sandbox documents travel on the Peppol test network, to test participants.

## Send an invoice made with num-num/ubl-invoice

```php
use Getpeppr\Client;
use NumNum\UBL;

$invoice = (new UBL\Invoice())
    ->setCustomizationId('urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0')
    ->setProfileId('urn:fdc:peppol.eu:2017:poacc:billing:01:1.0')
    ->setId('INV-2026-001')
    // … supplier, customer, lines and totals, as you already do
;
$xml = (new UBL\Generator())->invoice($invoice, 'EUR');

$getpeppr = new Client(getenv('GETPEPPR_API_KEY'));

$sent = $getpeppr->importInvoice(
    $xml,
    'INV-2026-001.xml',
    '9925:BE0314595348',               // the recipient's Peppol id, scheme:identifier
    idempotencyKey: $operationId,      // see "Resending safely" below
);

echo $sent->providerDocumentId; // read its status with getInvoice()
```

The recipient id, the third argument, is the only thing that decides where the document goes. If it disagrees with the customer address written in the document, the send still goes through and `$sent->warnings` says so (`recipient_endpoint_mismatch`).

Complete, runnable scripts: [`examples/send-with-num-num.php`](examples/send-with-num-num.php) and [`examples/send-with-josemmo.php`](examples/send-with-josemmo.php).

## Send an invoice made with josemmo/einvoicing

```php
use Einvoicing\Invoice;
use Einvoicing\Presets\Peppol;
use Einvoicing\Writers\UblWriter;

$invoice = new Invoice(Peppol::class);
// … number, seller, buyer, lines, as you already do
$xml = (new UblWriter())->export($invoice);

$sent = $getpeppr->importInvoice($xml, 'F-2026-001.xml', '9925:BE0314595348', idempotencyKey: $operationId);
```

## Read the status

```php
$document = $getpeppr->getInvoice($sent->providerDocumentId);

echo $document->status; // submitted, delivered, accepted, rejected, failed, …
```

A successful send means getpeppr accepted the document for transmission, not that it was delivered. Read the status, or listen to the `invoice.*` [webhooks](#webhooks).

## Platforms: send for your customers

With a **master key**, create each customer once, then name it as the sender:

```php
use Getpeppr\Sender;

$customer = $getpeppr->createLegalEntity([
    'externalId'  => 'customer_8412',          // your own reference
    'companyName' => 'Bright Health Ltd',
    'country'     => 'GB',
    'address'     => ['line1' => '10 King Street', 'city' => 'London', 'zip' => 'EC2V 8EA'],
    'identifier'  => ['scheme' => 'GB:VAT', 'value' => 'gb123456789'],
]);
// $customer->created is false when that externalId already existed.

$getpeppr->importInvoice($xml, 'INV-8412-001.xml', $recipient,
    sender: Sender::externalSubTenant('customer_8412'), // or Sender::legalEntity($customer->id)
    idempotencyKey: $operationId,
);

$page = $getpeppr->listLegalEntities(externalId: 'customer_8412');
$one  = $getpeppr->getLegalEntity($customer->id);
```

The supplier written in the document must be that customer: getpeppr never rewrites your document, so a disagreement is refused. A standard key that names a sender is refused with `403`. In the sandbox you can test without a real company: see [the sandbox pilot guide](https://getpeppr.dev/docs/platform/sandbox-pilot/).

## When a call fails

Every exception extends `Getpeppr\Exception\GetpepprException`. Its class tells you what to do:

| Exception | What happened | What to do |
|---|---|---|
| `TransportException` | No response arrived (network, timeout), or the API host answered `429`/`5xx` on its own. | The document **may have been sent**. Resend later with the **same** idempotency key, never a new one. |
| `DocumentTooLargeException` | The request is over the size limit. | At most about 3 MB of document. Nothing was sent. |
| `DuplicateDocumentException` | The same invoice was already sent moments ago (`409`). | **Do not send it again.** Read the status of `$e->duplicateOf`, the document that already left. |
| `IdempotencyKeyException` | The key was reused for another body, or was blank. | Fix the key: one key is one operation. |
| `InvalidRequestException` | The request or the document must be fixed, e.g. a Peppol rule is broken (`invoices_import.validation_failed`). | Resending it unchanged fails the same way. |
| `ContactSupportException` | The account cannot do this right now (identity not verified, platform contract not active, master key needed, …). | **The document is not at fault.** Keep it, and resend it once the account state has changed. |
| `AuthenticationException` | The API key was refused. | Check the key. |
| `RetryableException` | A temporary refusal getpeppr announced (rate limit, validator unavailable, …). | Resend later with the same key, after `$e->retryAfter` seconds when set. |
| `UnsupportedOperationException` | The operation is not supported. | Do not resend. |
| `GetpepprException` itself | A response this version cannot interpret. | Do not guess: keep the document and quote `$e->requestId` to support. |

The family comes from the `Getpeppr-Remediation` header the API sends, not from `retryable` alone: a rejected document and an account that may not send both say `retryable: false`, and they call for opposite actions. `DocumentTooLargeException` is also an `InvalidRequestException`.

Each exception also exposes `status`, `resultCode` (for example `invoices_import.validation_failed`), `remediation`, `retryable`, `requestId`, `resultDocs` (a link to the [result catalogue](https://getpeppr.dev/docs/api-results/)), `retryAfter`, `publicMessage` (the catalogue's fixed sentence) and `vercelId` (when the host answered without getpeppr). Any of them may be `null`.

```php
use Getpeppr\Exception;

try {
    $sent = $getpeppr->importInvoice($xml, $filename, $recipient, idempotencyKey: $operationId);
} catch (Exception\DuplicateDocumentException $e) {
    markAsSent($invoice, $e->duplicateOf);              // it already left
} catch (Exception\TransportException | Exception\RetryableException $e) {
    retryLater($invoice, $operationId, $e->retryAfter); // same key
} catch (Exception\ContactSupportException $e) {
    holdUntilAccountReady($invoice, $e->resultCode);    // keep the document
} catch (Exception\InvalidRequestException $e) {
    markAsRejected($invoice, $e->resultCode, $e->resultDocs);
}
```

**What an exception never contains:** your API key, the document (raw or base64), the sender, the invoice number, the idempotency key or the response body. The message is built from the response headers only, the PSR-18 exception is not chained (it may carry your request), and the package's parameters are marked `#[\SensitiveParameter]` so they stay out of stack traces. The package writes no logs. The rules a rejected document broke are therefore not on the exception: open `resultDocs`, or check the document against the [validation endpoint](https://getpeppr.dev/reference).

## Resending safely

The package never retries by itself. When you retry, the idempotency key decides whether the API sees a retry or a new send.

- **Use an opaque operation id**, for example a UUID, created once per logical send and stored with the invoice. Reuse it only to resend exactly the same request.
- **Do not derive the key from the invoice number alone.** The API keeps one entry per API key, endpoint and body fingerprint: on a platform, two of your customers may use the same invoice number under the same master key, and the second legitimate send would be refused. If you must derive it, include at least the sender, the number, the recipient and the document type.
- Same key, same body: the original response is replayed and nothing is sent twice.
- Same key, different body: `IdempotencyKeyException` (`idempotency.key_reused`).

What the API does on top, which you do not have to reimplement:

- The same invoice (same sender, recipient, number, invoice or credit note) sent again **within 15 minutes**, under another key or none, is refused with `DuplicateDocumentException`. Nothing was sent.
- **After 15 minutes**, it is accepted and `$sent->duplicateOf` names the earlier document: a second copy was accepted for transmission. Check it.
- Two requests sent at the same instant under two different keys are not serialised: each can pass. Reuse one key for the retries of a send.
- The duplicate check looks back 30 days.

## Webhooks

```php
use Getpeppr\Webhook;
use Getpeppr\Exception\WebhookVerificationException;

$rawBody = file_get_contents('php://input');           // the raw body, never a re-encoded one
$header  = $_SERVER['HTTP_GETPEPPR_SIGNATURE'] ?? '';

try {
    $event = Webhook::verify($rawBody, $header, getenv('GETPEPPR_WEBHOOK_SECRET'));
} catch (WebhookVerificationException $e) {
    http_response_code(400);                           // $e->reason says why
    exit;
}

// Deliveries are at least once: the same event can arrive again, with the same id.
// Reserve the id atomically BEFORE acting on it (PostgreSQL shown; INSERT IGNORE on MySQL).
$reserve = $pdo->prepare('INSERT INTO getpeppr_events (id) VALUES (?) ON CONFLICT (id) DO NOTHING');
$reserve->execute([$event->id]);
if ($reserve->rowCount() === 1) {
    handle($event->type, $event->data);                // invoice.sent, invoice.error, …
}
http_response_code(200);
```

`verify()` checks the HMAC-SHA256 signature in constant time and refuses a timestamp more than 300 seconds away (`toleranceSeconds:` changes it). It proves the event comes from getpeppr and is recent; it cannot prove it is new, so a legitimate redelivery verifies too. Any answer other than `2xx`, or no answer within 5 seconds, makes getpeppr deliver again after 1, 5 and 30 minutes. A runnable endpoint: [`examples/webhook-endpoint.php`](examples/webhook-endpoint.php). Event types: [getpeppr.dev/docs/webhooks](https://getpeppr.dev/docs/webhooks).

## Configuration

```php
$getpeppr = new Client(
    apiKey: getenv('GETPEPPR_API_KEY'),     // sk_sandbox_… or sk_live_…: the API decides the environment from the key
    baseUrl: 'https://api.getpeppr.dev/v1', // the default
    httpClient: $psr18Client,               // optional; set your timeouts on it
    requestFactory: $psr17Factory,          // optional
    streamFactory: $psr17Factory,           // optional
);
```

`importInvoice()` also takes `skipValidation: true`, which sends without the Peppol rulebook check for that call (`x-skip-validation`). Use it only for a document you validated yourself.

Every result exposes `requestId`, `resultCode` and `httpStatus`, and `toArray()` returns the decoded response for fields this version does not map. Invalid arguments (a blank id, an API key or idempotency key that cannot be an HTTP header value) throw `\InvalidArgumentException` before anything is sent.

## Scope and limits

- This package sends UBL you already have. To send invoices described as JSON, use the API directly or the [TypeScript SDK](https://www.npmjs.com/package/@getpeppr/sdk).
- Countries and document types depend on the getpeppr service, not on this package: see [getpeppr.dev/countries](https://getpeppr.dev/countries).
- Version 0.x: the interface may still change between minor versions. PHP 8.2 is supported until its security support ends; the first release after 31 December 2026 requires PHP 8.3.

## Links

- Documentation: [getpeppr.dev/docs](https://getpeppr.dev/docs)
- API reference: [getpeppr.dev/reference](https://getpeppr.dev/reference)
- Result codes: [getpeppr.dev/docs/api-results](https://getpeppr.dev/docs/api-results/)
- Account and API keys: [console.getpeppr.dev](https://console.getpeppr.dev/sign-up?source=php)
- Security: [SECURITY.md](SECURITY.md)

## License

MIT. See [LICENSE](LICENSE).
