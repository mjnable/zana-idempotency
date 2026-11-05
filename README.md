# Zana Idempotency

Idempotency keys for Zana HTTP routes. A client sends an `Idempotency-Key`
header with a request that must not run twice, such as "pay". The first
request runs and its response is stored. A retry with the same key gets
that response again instead of paying twice. Requires PHP 8.5 or later.

```
composer require mj-zana/idempotency
```

## Marking a route

Mark the handler with `#[Idempotent]`, and run `IdempotencyMiddleware` on
its route:

```php
#[Route('/api/orders/{id}/pay', methods: ['POST'], middleware: [IdempotencyMiddleware::class])]
#[Idempotent(required: true)]
public function pay(PayOrder $command): ResponseInterface { /* ... */ }
```

- **`required: true`** refuses a request without a key (400). By default, a
  request without a key runs unprotected.
- **`ttl`** keeps the key for that many seconds. The default is
  `idempotency.ttl`, a day.
- **The middleware** only acts on `#[Idempotent]` handlers, so it can run
  on a whole group of routes.

## What a client gets

| Request | Response |
|---|---|
| A new key | The handler runs; its response is stored |
| The same key and the same request | The stored response, with `Idempotent-Replayed: true` |
| The same key while the first request still runs | 409, with `Retry-After: 1` |
| The same key with another method, path, query or body | 422 |
| A key that is not 1 to 255 visible ASCII characters | 400 |

- **Atomic:** a key is claimed with one database insert, so of two
  simultaneous requests exactly one runs, across processes and servers.
- **Failures free the key:** a response of 500 or more, or an exception,
  is not stored, so the client can retry. A 4xx response is an answer and
  is replayed.
- **Stopped requests:** a request that stops without an answer holds its key
  for `idempotency.lock_seconds`; a retry after that runs it again.
- **Per user:** with `mj-zana/security`, keys belong to the signed-in user,
  so two users never share one. Without a signed-in user, keys are shared,
  so clients must generate unique keys (a UUID).

## Configuration

Register `IdempotencyServiceProvider` with `HttpServiceProvider` and
`DatabaseServiceProvider`. Create the table, and delete expired keys daily:

```
php bin/zana idempotency:install
php bin/zana migrate
php bin/zana idempotency:prune
```

```php
// config/idempotency.php
return [
    'ttl' => 86400,       // seconds a key and its response are kept
    'lock_seconds' => 60, // how long a running request holds its key
];
```

## Check and inspect

- **`check`:** reports an error when the keys table cannot be read. It warns
  about an `#[Idempotent]` handler whose route does not run the middleware
  (with `mj-zana/routing`).
- **`inspect idempotency`:** shows the stored, running and expired keys.

## License

MIT
