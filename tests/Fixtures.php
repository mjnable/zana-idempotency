<?php

declare(strict_types=1);

namespace Zana\Idempotency\Tests;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Idempotency\Idempotent;
use Zana\Security\UserInterface;

/**
 * Counts the payments it takes; answers 201 with the payment number, or what
 * $answer returns.
 */
final class PayController
{
    public int $payments = 0;

    /** @var (\Closure(ServerRequestInterface): ResponseInterface)|null */
    public ?\Closure $answer = null;

    #[Idempotent(required: true)]
    public function pay(ServerRequestInterface $request): ResponseInterface
    {
        $this->payments++;
        if ($this->answer !== null) {
            return ($this->answer)($request);
        }

        return new Response(201, ['Content-Type' => 'application/json', 'Location' => "/payments/{$this->payments}"], json_encode(['payment' => $this->payments], JSON_THROW_ON_ERROR));
    }

    #[Idempotent]
    public function optional(): ResponseInterface
    {
        $this->payments++;

        return new Response(200, [], 'ok');
    }

    public function plain(): ResponseInterface
    {
        return new Response(200, [], 'plain');
    }
}

/**
 * Runs a PayController method as the route handler would.
 */
final readonly class Handler implements RequestHandlerInterface
{
    public function __construct(private PayController $controller, private string $method)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->controller->{$this->method}($request);
    }
}

final readonly class Buyer implements UserInterface
{
    public function __construct(private string $id)
    {
    }

    public function identifier(): string
    {
        return $this->id;
    }

    public function roles(): array
    {
        return [];
    }
}
