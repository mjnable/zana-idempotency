<?php

declare(strict_types=1);

namespace Zana\Idempotency\Internal;

use Closure;

/**
 * Reads an attribute of the matched route handler (the "_handler" request
 * attribute, or a route's handler): on the method, otherwise on the class.
 *
 * @internal
 */
final class HandlerAttribute
{
    /**
     * @template T of object
     * @param class-string<T> $attribute
     * @return T|null
     */
    public static function first(mixed $handler, string $attribute): ?object
    {
        foreach (array_reverse(self::targets($handler)) as $target) {
            foreach ($target->getAttributes($attribute) as $found) {
                return $found->newInstance();
            }
        }

        return null;
    }

    /**
     * @return list<\ReflectionClass<object>|\ReflectionMethod|\ReflectionFunction>
     */
    private static function targets(mixed $handler): array
    {
        if ($handler instanceof Closure) {
            return [new \ReflectionFunction($handler)];
        }
        [$class, $method] = match (true) {
            is_string($handler) => [$handler, '__invoke'],
            is_array($handler) && is_string($handler[0] ?? null) && is_string($handler[1] ?? null) => [$handler[0], $handler[1]],
            default => ['', ''],
        };
        if (!class_exists($class)) {
            return [];
        }
        $reflection = new \ReflectionClass($class);

        return $reflection->hasMethod($method) ? [$reflection, $reflection->getMethod($method)] : [$reflection];
    }
}
