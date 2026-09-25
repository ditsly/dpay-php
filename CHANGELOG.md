# Changelog

All notable changes to `dpay/dpay-php` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [1.0.0] — 2026-09-25

First public release.

### Added
- `Client::live()` / `Client::sandbox()` / `Client::fromToken()` — one client per token; the
  token kind decides the environment (`sb_tk_…` is sandbox).
- **Hosted checkout** (`checkoutSessions()`): `create`, `get`, `list`, `cancel` against
  `/api/v2/checkout-sessions` (amendment A34, API.md §2.17) with `Idempotency-Key`
  replay detection, RFC 7807 problems mapped to typed exceptions, the
  `CheckoutSession::matchesOrder()` money guard and `cs_` / `cs_test_` id validation.
- **Raw per-method surface**: `payMethods()` (live envelope and sandbox bare array,
  `otp_length` read when present), `paymentSessions()->open/verify/get` with typed
  `OpenSessionRequest` constructors per gateway (EDFali, Sadad, MobiCash, MITF banks,
  Moamalat, Mastercard), `payments()->list/filter`, `sandboxTools()` magic OTPs.
- `Environment` enum encoding every live/sandbox difference (paths, hidden slugs,
  expiry, string amounts, fee decimals, envelopes, webhook `live` flag, id prefixes).
- `BaseUrlPolicy`: https only, DPay hosts only (`dpay.ly`, `next.dpay.ly`, `pg.dits.ly`)
  unless opted in; redirects are never followed; TLS verification stays on when the SDK
  builds Guzzle itself.
- `Money` (string decimals, never floats) with the legacy rounding port `PhpRound`
  (identical on PHP 8.1–8.5) and `FeeMath` (3dp fee, 3dp total, 2dp stored charge,
  2dp sandbox/replay fee) proven against the platform's golden vectors.
- `Gateways\Rules`, `LibyanMobile`, `BankCardNumber`, `Digits` (Arabic-Indic folding).
- Exception hierarchy keyed on the exact controller strings, for both the legacy
  `{message}` dialect and the v2 RFC 7807 dialect.
- `Messages`: the Arabic-first payer-facing message table ported from the checkout app.
- `Webhooks\Verifier` / `Event`: `hash_equals`, 300 s window, live-flag check, secret
  rotation grace, `checkout.*` and `payment.*` and `webhook.test` payloads.
- `ReturnUrl`: the legacy `session_id/status/payment_id` appender and parser, plus the
  hosted checkout's `checkout_session_id/status/payment_id` pair.
- `Idempotency\KeyFactory`: deterministic keys with a per-install `store_uid`.
- PSR-18/PSR-17 transport via `php-http/discovery`, no hard Guzzle dependency;
  retries only on GET and keyed POSTs for 429/503/connection failures; PSR-3 logging
  with redaction.
- Test tiers: unit; contract (replays every request/response pair of the
  execution-generated Postman collection and fails when a sample cannot be classified).
- CI: PHP 8.1–8.5 matrix, PHPStan max, php-cs-fixer PSR-12 + strict types.
- `CheckoutSessions::createRaw()` (untyped body, for advanced integrations and the contract tier)
  and the shared `CheckoutSessions::assertIdempotencyKey()`.

### Security
- The API token is private on `Config` (`token()` / `maskedToken()`), masked by
  `var_dump`/`print_r`/`dd()` (`__debugInfo`), absent from `json_encode`, and `Client`, `Config`
  and `Webhooks\Verifier` refuse `serialize()`; constructor secrets carry `#[\SensitiveParameter]`
  (redacted from stack traces on PHP ≥ 8.2).
- `PsrTransport` refuses an injected Guzzle client that follows redirects (`allow_redirects`) or
  skips TLS verification (`verify => false`); `clientClass()` / `isHardened()` expose what is in
  use. README shows the Symfony client with `max_redirects => 0`.

[1.0.0]: https://github.com/ditsly/dpay-php/releases/tag/v1.0.0
