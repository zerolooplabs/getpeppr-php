# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows [Semantic Versioning](https://semver.org/) (0.x: the interface may still change between minor versions).

## [0.1.0] - unreleased

### Added

- `Client::importInvoice()`: send a UBL Invoice or CreditNote you already generated (`POST /v1/invoices/import`), for your own company or for a platform customer (`Sender`), with an optional idempotency key and validation opt-out. Exposes `duplicateOf` and non-blocking warnings.
- `Client::getInvoice()`: read a document and its status.
- `Client::createLegalEntity()`, `listLegalEntities()`, `getLegalEntity()`: manage a platform's customers.
- `Webhook::verify()`: verify `Getpeppr-Signature` and return the event with its id.
- Exceptions classified by the `Getpeppr-Remediation` header, in a total and ordered matrix, with `DuplicateDocumentException` for the `409` duplicate refusal.
- `UnreadableResponseException` for a success whose response cannot be read: the call may have taken effect.
- PSR-18 / PSR-17 transport with `php-http/discovery`. PHP 8.2 to 8.5.
