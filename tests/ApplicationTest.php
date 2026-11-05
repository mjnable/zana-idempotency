<?php

declare(strict_types=1);

namespace Zana\Idempotency\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Console\ConsoleKernel;
use Zana\Console\ConsoleServiceProvider;
use Zana\Container\Contracts\ContainerInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\InspectionSection;
use Zana\Core\ServiceProvider;
use Zana\Database\DatabaseServiceProvider;
use Zana\Http\HttpKernel;
use Zana\Http\HttpServiceProvider;
use Zana\Idempotency\IdempotencyMiddleware;
use Zana\Idempotency\IdempotencyServiceProvider;
use Zana\Idempotency\Inspection\IdempotencyCheck;
use Zana\Idempotency\Inspection\IdempotencySection;
use Zana\Routing\Router;
use Zana\Routing\RoutingServiceProvider;
use Zana\Security\SecurityServiceProvider;
use Zana\Testing\ConsoleTester;
use Zana\Testing\HttpTestClient;

/**
 * The package in an application: the middleware on a route through the HTTP
 * kernel (errors rendered as problem responses), idempotency:install and
 * migrate, check, inspect and idempotency:prune.
 */
final class ApplicationTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = str_replace('\\', '/', sys_get_temp_dir()) . '/zana-idempotency-app-' . bin2hex(random_bytes(4));
        mkdir("{$this->base}/var", 0777, true);
    }

    protected function tearDown(): void
    {
        exec(PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' . escapeshellarg(str_replace('/', '\\', $this->base)) : 'rm -rf ' . escapeshellarg($this->base));
    }

    public function testInstallCheckServeAndPrune(): void
    {
        $controller = new PayController();
        $app = Application::configure($this->base)->withEnvironment('testing')
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class, DatabaseServiceProvider::class, SecurityServiceProvider::class, ConsoleServiceProvider::class, IdempotencyServiceProvider::class, new PayRoutes())
            ->withConfiguration(['database.connections.main' => ['driver' => 'sqlite', 'database' => ':memory:']])
            ->withServices(static fn (ServiceCollectionInterface $s) => $s->instance(PayController::class, $controller))
            ->build();
        $console = new ConsoleTester($app->container()->get(ConsoleKernel::class));
        $check = self::tagged($app, ApplicationCheck::TAG, IdempotencyCheck::class);

        $this->assertSame(['idempotency.table', 'idempotency.route'], self::ids($check->findings($app)), 'no table yet; /unguarded lacks the middleware');
        $this->assertStringContainsString('_create_idempotency_table.php', $console->run('idempotency:install')->output());
        $this->assertStringContainsString('The idempotency migration exists', $console->run('idempotency:install')->output());
        $this->assertSame(0, $console->run('migrate')->exitCode());
        $this->assertSame(['idempotency.route'], self::ids($check->findings($app)));

        $kernel = $app->container()->get(HttpKernel::class);
        assert($kernel instanceof HttpKernel);
        $http = new HttpTestClient($kernel);
        $http->post('/orders/7/pay', ['amount' => 25], ['Idempotency-Key' => 'k-1'])->assertCreated()->assertJsonPath('payment', 1);
        $http->post('/orders/7/pay', ['amount' => 25], ['Idempotency-Key' => 'k-1'])->assertCreated()->assertJsonPath('payment', 1)->assertHeader('Idempotent-Replayed', 'true');
        $http->post('/orders/7/pay', ['amount' => 99], ['Idempotency-Key' => 'k-1'])->assertUnprocessable()->assertJsonPath('errors.Idempotency-Key.0', 'This key was already used with a different request.');
        $http->post('/orders/7/pay', ['amount' => 25])->assertStatus(400);
        $this->assertSame(1, $controller->payments);

        $this->assertSame(['stored' => 1, 'running' => 0, 'expired' => 0], self::tagged($app, InspectionSection::TAG, IdempotencySection::class)->inspect($app));
        $this->assertStringContainsString('Deleted 0 expired idempotency keys.', $console->run('idempotency:prune')->output());
    }

    /**
     * @param list<Finding> $findings
     * @return list<string>
     */
    private static function ids(array $findings): array
    {
        return array_map(static fn (Finding $f): string => $f->id, $findings);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function tagged(ApplicationInterface $app, string $tag, string $class): object
    {
        foreach ($app->container()->tagged($tag) as $service) {
            if ($service instanceof $class) {
                return $service;
            }
        }
        self::fail("{$class} is not tagged {$tag}.");
    }
}

final class PayRoutes extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
    }

    public function boot(ContainerInterface $container): void
    {
        $router = $container->get(Router::class);
        assert($router instanceof Router);
        $router->post('/orders/7/pay', [PayController::class, 'pay'])->middleware(IdempotencyMiddleware::class);
        $router->post('/unguarded', [PayController::class, 'optional']);
    }
}
