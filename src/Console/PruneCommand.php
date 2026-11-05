<?php

declare(strict_types=1);

namespace Zana\Idempotency\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Idempotency\IdempotencyStore;

/**
 * idempotency:prune: deletes the expired keys. Schedule it, e.g. daily.
 */
#[AsCommand(name: 'idempotency:prune', description: 'Delete the expired idempotency keys')]
final class PruneCommand extends Command
{
    public function handle(IdempotencyStore $store, SymfonyStyle $io): int
    {
        $removed = $store->prune();
        $io->writeln("Deleted {$removed} expired idempotency " . ($removed === 1 ? 'key' : 'keys') . '.');

        return self::SUCCESS;
    }
}
