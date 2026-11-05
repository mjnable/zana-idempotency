<?php

declare(strict_types=1);

namespace Zana\Idempotency\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zana\Console\Command;
use Zana\Core\ApplicationInterface;
use Zana\Core\ConsoleScript;
use Zana\Idempotency\IdempotencyTableMigration;

/**
 * idempotency:install: writes database/migrations/<date>_create_idempotency_table.php,
 * a migration that returns IdempotencyTableMigration. Run migrate afterwards.
 */
#[AsCommand(name: 'idempotency:install', description: 'Write the migration of the idempotency keys table')]
final class InstallCommand extends Command
{
    private const string STUB = <<<'PHP'
        <?php

        declare(strict_types=1);

        // The idempotency keys table of mj-zana/idempotency.
        return new \Zana\Idempotency\IdempotencyTableMigration();

        PHP;

    public function handle(ApplicationInterface $application, SymfonyStyle $io): int
    {
        $folder = $application->path('database', 'migrations');
        foreach (glob("{$folder}/*_create_idempotency_table.php") ?: [] as $existing) {
            $io->writeln('The idempotency migration exists: ' . basename($existing) . '.');

            return self::SUCCESS;
        }
        if (!is_dir($folder) && !mkdir($folder, 0777, true) && !is_dir($folder)) {
            $io->error("Cannot create {$folder}.");

            return self::FAILURE;
        }

        $file = $folder . '/' . gmdate('Y_m_d_His') . '_create_idempotency_table.php';
        file_put_contents($file, self::STUB);
        $io->success('Wrote ' . basename($file) . ' (' . IdempotencyTableMigration::class . '). Run ' . ConsoleScript::command('migrate') . '.');

        return self::SUCCESS;
    }
}
