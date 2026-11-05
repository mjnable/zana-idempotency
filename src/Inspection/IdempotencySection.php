<?php

declare(strict_types=1);

namespace Zana\Idempotency\Inspection;

use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\InspectionSection;
use Zana\Idempotency\IdempotencyStore;

/**
 * `inspect idempotency`: the stored, running and expired keys.
 */
final readonly class IdempotencySection implements InspectionSection
{
    /**
     * @param \Closure(): IdempotencyStore $store
     */
    public function __construct(private \Closure $store)
    {
    }

    public function name(): string
    {
        return 'idempotency';
    }

    public function inspect(ApplicationInterface $application): array
    {
        try {
            return ($this->store)()->counts();
        } catch (\Throwable $e) {
            return ['error' => 'The idempotency keys cannot be read: ' . strtok($e->getMessage(), "\n")];
        }
    }
}
