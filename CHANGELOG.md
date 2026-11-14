# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.20.0 — 2026-11-14

### Security

- An anonymous client's keys belong to its session (with mj-zana/session), else to its address (the one
  `TrustedProxiesMiddleware` found, else REMOTE_ADDR). Before, all anonymous clients shared one key space, so
  another client sending the same key and body got the first one's stored response. Signed-in users' keys are
  unchanged. An anonymous retry that spans the upgrade runs again, as its key is now stored under another id.
- `IdempotencyMiddleware` takes an optional `session` closure, which the provider fills from `SessionContext`.

## 2.14.0 — 2026-11-05

First release (FE-61, ADR-029). Idempotency keys for Zana HTTP routes. A retried request with the
same `Idempotency-Key` gets the stored response instead of running twice, a repeat while the first
request runs gets 409, and the key with another request gets 422. Keys are claimed atomically in
the database and belong to the signed-in user. Tested on SQLite, MySQL and PostgreSQL, including
four simultaneous processes. Requires PHP 8.5 or later.

Documentation: [the idempotency guide](https://github.com/mjnable/zana-docs/blob/main/guides/idempotency.md).
