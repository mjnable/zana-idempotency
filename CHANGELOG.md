# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.14.0 — Unreleased

First release (FE-61, ADR-029). Idempotency keys for Zana HTTP routes. A retried request with the
same `Idempotency-Key` gets the stored response instead of running twice, a repeat while the first
request runs gets 409, and the key with another request gets 422. Keys are claimed atomically in
the database and belong to the signed-in user. Tested on SQLite, MySQL and PostgreSQL, including
four simultaneous processes. Requires PHP 8.5 or later.

Documentation: [the idempotency guide](https://github.com/mjnable/zana-docs/blob/main/guides/idempotency.md).
