<?php

declare(strict_types=1);

namespace Zana\Idempotency;

use Attribute;

/**
 * Makes a handler idempotent; enforced by IdempotencyMiddleware as route
 * middleware. A request with an Idempotency-Key header runs once; a repeat
 * gets the stored response.
 *
 *     #[Route('/api/orders/{id}/pay', methods: ['POST'], middleware: [IdempotencyMiddleware::class])]
 *     #[Idempotent(required: true)]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class Idempotent
{
    /**
     * @param bool $required Refuse requests without a key (400), rather than run them unprotected
     * @param int|null $ttl Seconds a key is kept; idempotency.ttl (86,400) when null
     */
    public function __construct(
        public bool $required = false,
        public ?int $ttl = null,
    ) {
    }
}
