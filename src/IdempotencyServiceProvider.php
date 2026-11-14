<?php

declare(strict_types=1);

namespace Zana\Idempotency;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Zana\Configuration\ConfigurationInterface;
use Zana\Console\ConsoleKernel;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\InspectionSection;
use Zana\Core\ServiceProvider;
use Zana\Database\ConnectionInterface;
use Zana\Idempotency\Console\InstallCommand;
use Zana\Idempotency\Console\PruneCommand;
use Zana\Idempotency\Inspection\IdempotencyCheck;
use Zana\Idempotency\Inspection\IdempotencySection;
use Zana\Security\SecurityContext;
use Zana\Session\SessionContext;

/**
 * Registers the store, IdempotencyMiddleware (scoped, as it reads the
 * signed-in user from SecurityContext when security is installed, and an
 * anonymous visitor's session from SessionContext when sessions are), the
 * idempotency: commands, and the check and inspect contributions. Needs
 * DatabaseServiceProvider and HttpServiceProvider. Reads idempotency.ttl
 * (86,400 seconds) and idempotency.lock_seconds (60).
 */
final class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services
            ->singleton(IdempotencyStore::class, static function (ServiceResolverInterface $r): IdempotencyStore {
                $connection = $r->get(ConnectionInterface::class);
                assert($connection instanceof ConnectionInterface);

                return new IdempotencyStore($connection);
            })->uses(ConnectionInterface::class)
            ->scoped(IdempotencyMiddleware::class, static function (ServiceResolverInterface $r): IdempotencyMiddleware {
                $config = $r->get(ConfigurationInterface::class);
                $responses = $r->get(ResponseFactoryInterface::class);
                $streams = $r->get(StreamFactoryInterface::class);
                assert($config instanceof ConfigurationInterface && $responses instanceof ResponseFactoryInterface && $streams instanceof StreamFactoryInterface);
                $security = class_exists(SecurityContext::class) && $r->has(SecurityContext::class) ? $r->get(SecurityContext::class) : null;
                $user = $security instanceof SecurityContext
                    ? static fn (): ?string => $security->user()?->identifier()
                    : static fn (): ?string => null;
                $sessions = class_exists(SessionContext::class) && $r->has(SessionContext::class) ? $r->get(SessionContext::class) : null;
                $session = $sessions instanceof SessionContext
                    ? static fn (): ?string => $sessions->has() ? $sessions->session()->id() : null
                    : null;

                return new IdempotencyMiddleware(
                    self::store($r),
                    $responses,
                    $streams,
                    $user,
                    $config->int('idempotency.ttl', 86_400),
                    $config->int('idempotency.lock_seconds', 60),
                    $session,
                );
            })->uses(IdempotencyStore::class, ConfigurationInterface::class, ResponseFactoryInterface::class, StreamFactoryInterface::class, SecurityContext::class, SessionContext::class)
            // The store is resolved lazily: resolving the tagged checks and sections must not need the database.
            ->singleton(IdempotencyCheck::class, static fn (ServiceResolverInterface $r): IdempotencyCheck => new IdempotencyCheck(static fn (): IdempotencyStore => self::store($r)))
            ->uses(IdempotencyStore::class)
            ->tag(ApplicationCheck::TAG, IdempotencyCheck::class)
            ->singleton(IdempotencySection::class, static fn (ServiceResolverInterface $r): IdempotencySection => new IdempotencySection(static fn (): IdempotencyStore => self::store($r)))
            ->uses(IdempotencyStore::class)
            ->tag(InspectionSection::TAG, IdempotencySection::class);

        if (class_exists(ConsoleKernel::class)) {
            foreach ([InstallCommand::class, PruneCommand::class] as $command) {
                $services->singleton($command)->tag(ConsoleKernel::TAG, $command);
            }
        }
    }

    public function origin(): string
    {
        return 'package:mj-zana/idempotency';
    }

    private static function store(ServiceResolverInterface $r): IdempotencyStore
    {
        $store = $r->get(IdempotencyStore::class);
        assert($store instanceof IdempotencyStore);

        return $store;
    }
}
