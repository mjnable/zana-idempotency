<?php

declare(strict_types=1);

namespace Zana\Idempotency;

use Zana\Database\ConnectionInterface;

/**
 * The idempotency_keys rows. A key is claimed with one insert that the
 * primary key makes atomic, so of two simultaneous requests exactly one runs.
 * Response bodies are stored as base64, since they may be binary and
 * PostgreSQL text cannot hold NUL bytes.
 */
final class IdempotencyStore
{
    public const string TABLE = 'idempotency_keys';

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param \Closure(): int|null $clock
     */
    public function __construct(private readonly ConnectionInterface $connection, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    /**
     * Claim the key for a request; false when the key is taken.
     *
     * @phpstan-impure
     */
    public function claim(string $id, string $fingerprint, int $lockSeconds, int $ttl): bool
    {
        $now = $this->now();

        return $this->table()->upsert(
            ['id' => $id, 'fingerprint' => $fingerprint, 'locked_until' => $now + $lockSeconds, 'expires_at' => $now + $ttl, 'created_at' => $now],
            uniqueBy: ['id'],
            update: [],
        ) > 0;
    }

    /**
     * @return array{fingerprint: string, status: int|null, headers: array<string, list<string>>, body: string, locked_until: int, expires_at: int}|null
     */
    public function find(string $id): ?array
    {
        $row = $this->table()->where('id', $id)->first();
        if ($row === null) {
            return null;
        }
        $headers = is_string($row['headers'] ?? null) ? json_decode($row['headers'], true) : [];
        $body = is_string($row['body'] ?? null) ? base64_decode($row['body'], true) : '';

        return [
            'fingerprint' => (string) $row['fingerprint'],
            'status' => is_numeric($row['status'] ?? null) ? (int) $row['status'] : null,
            'headers' => is_array($headers) ? $headers : [],
            'body' => is_string($body) ? $body : '',
            'locked_until' => (int) $row['locked_until'],
            'expires_at' => (int) $row['expires_at'],
        ];
    }

    /**
     * Take over a key whose request stopped without a response (its lock ran out).
     */
    public function takeOver(string $id, int $lockedUntil, int $lockSeconds): bool
    {
        return $this->table()->where('id', $id)->whereNull('status')->where('locked_until', $lockedUntil)
            ->update(['locked_until' => $this->now() + $lockSeconds]) === 1;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public function complete(string $id, int $status, array $headers, string $body): void
    {
        $this->table()->where('id', $id)->update([
            'status' => $status,
            'headers' => json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'body' => base64_encode($body),
        ]);
    }

    /**
     * Free a key whose request failed, so a retry runs it again.
     */
    public function release(string $id): void
    {
        $this->table()->where('id', $id)->whereNull('status')->delete();
    }

    /**
     * Remove the key if it has expired; true when it did.
     */
    public function forgetExpired(string $id): bool
    {
        return $this->table()->where('id', $id)->where('expires_at', '<=', $this->now())->delete() > 0;
    }

    /**
     * @return int The expired keys removed
     */
    public function prune(): int
    {
        return $this->table()->where('expires_at', '<=', $this->now())->delete();
    }

    /**
     * @return array{stored: int, running: int, expired: int}
     */
    public function counts(): array
    {
        $now = $this->now();

        return [
            'stored' => $this->table()->whereNotNull('status')->where('expires_at', '>', $now)->count(),
            'running' => $this->table()->whereNull('status')->where('expires_at', '>', $now)->count(),
            'expired' => $this->table()->where('expires_at', '<=', $now)->count(),
        ];
    }

    private function table(): \Zana\Database\Query\QueryBuilder
    {
        return $this->connection->table(self::TABLE);
    }
}
