<?php

declare(strict_types=1);

namespace Zana\Idempotency\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zana\Database\ConnectionFactory;
use Zana\Database\Schema\Blueprint;
use Zana\Idempotency\IdempotencyTableMigration;

/**
 * FE-61's acceptance: four processes send the same "pay" at the same moment;
 * the payment is taken once. The others get 409 (still running) or the
 * stored response, and a later retry gets the stored response.
 */
final class ConcurrencyTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return Databases::names();
    }

    #[DataProvider('drivers')]
    public function testSimultaneousRetriesPayOnce(string $driver): void
    {
        $file = $driver === 'sqlite' ? str_replace('\\', '/', sys_get_temp_dir()) . '/zana-idempotency-race-' . bin2hex(random_bytes(4)) . '.sqlite' : null;
        $settings = $file === null ? Databases::settings($driver) : ['driver' => 'sqlite', 'database' => $file];
        if (is_string($settings)) {
            $this->markTestSkipped($settings);
        }
        $db = ConnectionFactory::create($settings);
        (new IdempotencyTableMigration())->down($db->schema());
        (new IdempotencyTableMigration())->up($db->schema());
        $db->schema()->dropIfExists('payments');
        $db->schema()->create('payments', static function (Blueprint $t): void {
            $t->id();
            $t->integer('pid');
        });

        $php = [PHP_BINARY, '-d', 'xdebug.mode=off'];
        exec(escapeshellarg(PHP_BINARY) . " -r \"echo extension_loaded('pdo_{$driver}') ? 1 : 0;\"", $loaded);
        if ($driver !== 'sqlite' && ($loaded[0] ?? '1') === '0') {
            array_push($php, '-d', "extension=pdo_{$driver}");
        }
        $start = (string) (microtime(true) + 1.5);   // time for every process to boot
        $workers = [];
        $pipes = [];
        foreach (range(1, 4) as $_) {
            $worker = proc_open([...$php, __DIR__ . '/pay-worker.php', json_encode($settings, JSON_THROW_ON_ERROR), $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[]);
            $this->assertIsResource($worker, 'a buyer process starts');
            $workers[] = $worker;
        }
        $answers = [];
        foreach ($workers as $i => $worker) {
            $output = (string) stream_get_contents($pipes[$i][1]);
            $errors = (string) stream_get_contents($pipes[$i][2]);
            $this->assertSame(0, proc_close($worker), "{$output}\n{$errors}");
            $answers[] = $output;
        }

        $this->assertSame(1, $db->table('payments')->count(), 'paid once: ' . implode(', ', $answers));
        $this->assertSame(1, count(array_keys($answers, '201', true)), 'one process ran the payment: ' . implode(', ', $answers));
        $this->assertSame([], array_diff($answers, ['201', '409', '201 replayed']), 'the others waited or got the stored response');

        $later = proc_open([...$php, __DIR__ . '/pay-worker.php', json_encode($settings, JSON_THROW_ON_ERROR), '0'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $retry);
        $this->assertIsResource($later);
        $output = (string) stream_get_contents($retry[1]);
        $errors = (string) stream_get_contents($retry[2]);
        $this->assertSame(0, proc_close($later), $errors);
        $this->assertSame('201 replayed', $output, 'a later retry gets the stored response');
        $this->assertSame(1, $db->table('payments')->count());

        if ($file !== null) {
            unset($db);
            @unlink($file);
        }
    }
}
