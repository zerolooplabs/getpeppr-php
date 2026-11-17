# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows [Semantic Versioning](https://semver.org/) (0.x: the interface may still change between minor versions).

## [Unreleased]

### Fixed

- Documentation: send-only activation is production's (getpeppr GPR-1464, live since 2026-11-16). The README « Send-only » section and the `createLegalEntity()` docblock now say that the sandbox is open to every platform account, that `legal_entities.send_only_not_enabled` is a production refusal, and how a sandbox send-only customer moves through its lifecycle (simulated authorisation; the proof identity `0208:0000146389` becomes `active`). No code change.

## [0.2.0] - 2026-11-14

### Added

- Send-only Legal Entities (GPR-1460). `LegalEntity` exposes `networkRole`, `customerReference`, `receiving` (`Receiving`: `handledBy`, `smpHost`, `lastVerifiedAt`, `lifecycleResponses`), `suspension` (`Suspension`: `reasons`, `since`, while `status` is `suspended`) and `authorisation` (`Authorisation`: `method`, `reason`, send-only entities only), which `/v1/legal-entities` returns since 2026-11-13. They are never filled in: an absent `networkRole` is `null`, never `send_and_receive`, and an object with any malformed member is `null` as a whole (a member is never repaired into `null`, a suspension never shortened to the reasons that could be read); `toArray()` keeps the raw value. Vocabularies stay open strings. `createLegalEntity()` documents `networkRole` and `customerReference`, which it already sent as given. README section « Send-only (Belgium) ».

### Changed

- `tests/fixtures/api-results.json` resynchronised with the result catalogue at getpeppr `e44c14e1a`: 15 results added, none removed — the send-only codes (`legal_entities.send_only_*`, `network_role_*`, `customer_reference_immutable`, `receiving_registration_*`, `invoices.sender_sending_suspended`, `invoices.send_only_daily_limit`), `invoices.sender_country_not_served`, `idempotency.concurrent_request` and `idempotency.cache_unreadable`. The classifier is unchanged: every new row lands in the family its `Getpeppr-Remediation` names (for example `ContactSupportException` for `legal_entities.send_only_not_enabled` and `invoices.sender_sending_suspended`, `RetryableException` with `retryAfter` for `invoices.send_only_daily_limit`).

### Fixed

- Documentation: resending under the same idempotency key now follows the API contract published with GPR-1434: the same body gets the `201` of the same document (or `RetryableException` with `idempotency.concurrent_request` while an attempt is still running), never a second copy (a resend can still first meet a refusal that sends nothing, such as authentication, a rate limit or `idempotency.cache_unreadable`); a corrected invoice needs a new key, because a key stays bound to the first request sent with it. The `InvalidRequestException` row now names the request as well as the document. The README, its `catch` example and the `InvalidRequestException` and `UnreadableResponseException` docblocks say so, and the README no longer suggests deriving the key from the document.
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
