# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows [Semantic Versioning](https://semver.org/) (0.x: the interface may still change between minor versions).

## [Unreleased]

### Fixed

- Documentation: resending under the same idempotency key now follows the API contract published with GPR-1434: the same body gets the `201` of the same document (or `RetryableException` with `idempotency.concurrent_request` while an attempt is still running), never a second copy; a corrected invoice needs a new key, because a key stays bound to the first request sent with it. The README, its `catch` example and the `InvalidRequestException` and `UnreadableResponseException` docblocks say so, and the README no longer suggests deriving the key from the document.
- Documentation: the josemmo and platform examples reuse the stored operation id instead of creating a new idempotency key at call time.

## [0.1.0] - 2026-10-31

### Added

- `Client::importInvoice()`: send a UBL Invoice or CreditNote you already generated (`POST /v1/invoices/import`), for your own company or for a platform customer (`Sender`), with an optional idempotency key and validation opt-out. Exposes `duplicateOf` and non-blocking warnings.
- `Client::getInvoice()`: read a document and its status.
- `Client::createLegalEntity()`, `listLegalEntities()`, `getLegalEntity()`: manage a platform's customers.
- `Webhook::verify()`: verify `Getpeppr-Signature` and return the event with its id.
- Exceptions classified by the `Getpeppr-Remediation` header, in a total and ordered matrix, with `DuplicateDocumentException` for the `409` duplicate refusal.
- `UnreadableResponseException` for a success whose response cannot be read: the call may have taken effect.
- `RequestNotSentException` when the PSR-7/PSR-17 implementation cannot build the request: nothing was sent.
- PSR-18 / PSR-17 transport with `php-http/discovery`. PHP 8.2 to 8.5.
