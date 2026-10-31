# Security policy

## Reporting a vulnerability

Please report security issues privately, never in a public issue:

- through GitHub's private vulnerability reporting on this repository (**Security → Report a vulnerability**), or
- by email to **hello@getpeppr.dev**, with "Security" in the subject.

Include the version of `getpeppr/getpeppr-php`, the PHP version, and the steps to reproduce. Do not send real invoices, API keys or webhook secrets: a minimal reproduction with test data is enough. We acknowledge reports within three working days.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

Fixes are released in the latest 0.x minor version.

## What the package promises

- Exceptions never contain the API key, the document sent, the sender, the invoice number, the idempotency key or the response body (see "When a call fails" in the README). A report showing otherwise is a vulnerability.
- Webhook signatures are compared in constant time (`hash_equals`), and a timestamp outside the tolerance (300 seconds by default) is refused.
- The package never retries a send on its own.

Vulnerabilities in the getpeppr API itself are also welcome at the same address.
