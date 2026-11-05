<?php

declare(strict_types=1);

namespace Zana\Idempotency\Inspection;

use Zana\Configuration\ConfigurationInterface;
use Zana\Core\ApplicationInterface;
use Zana\Core\ConsoleScript;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;
use Zana\Idempotency\Idempotent;
use Zana\Idempotency\IdempotencyMiddleware;
use Zana\Idempotency\IdempotencyStore;
use Zana\Idempotency\Internal\HandlerAttribute;
use Zana\Routing\Router;

/**
 * idempotency.table when the keys table cannot be read (ADR-029), and
 * idempotency.route for an #[Idempotent] handler whose route does not run
 * IdempotencyMiddleware, so retries of it would still run twice. The store
 * is given lazily, as the database is only reached when check runs.
 */
final readonly class IdempotencyCheck implements ApplicationCheck
{
    /**
     * @param \Closure(): IdempotencyStore $store
     */
    public function __construct(private \Closure $store)
    {
    }

    public function findings(ApplicationInterface $application): array
    {
        $findings = [];
        try {
            ($this->store)()->counts();
        } catch (\Throwable $e) {
            $findings[] = new Finding(
                'idempotency.table',
                Severity::Error,
                'The idempotency keys cannot be read: ' . strtok($e->getMessage(), "\n"),
                hint: 'Run ' . ConsoleScript::command('idempotency:install') . ', then ' . ConsoleScript::command('migrate') . '.',
            );
        }

        $container = $application->container();
        if (!class_exists(Router::class) || !$container->has(Router::class)) {
            return $findings;
        }
        $global = $container->get(ConfigurationInterface::class)->array('http.middleware', []);
        if (in_array(IdempotencyMiddleware::class, $global, true)) {
            return $findings;
        }
        $router = $container->get(Router::class);
        assert($router instanceof Router);
        foreach ($router->routes() as $route) {
            if (HandlerAttribute::first($route->handler, Idempotent::class) === null) {
                continue;
            }
            $middleware = array_map(static fn (object|string $m): string => is_string($m) ? $m : $m::class, $route->getMiddleware());
            if (!in_array(IdempotencyMiddleware::class, $middleware, true)) {
                $name = $route->getName() ?? implode('|', $route->methods) . ' ' . $route->path;
                $findings[] = new Finding(
                    'idempotency.route',
                    Severity::Warning,
                    "The route {$name} has an #[Idempotent] handler but does not run IdempotencyMiddleware, so a retry runs it twice.",
                    hint: 'Add IdempotencyMiddleware::class to the middleware of its #[Route], or of its group.',
                );
            }
        }

        return $findings;
    }
}
