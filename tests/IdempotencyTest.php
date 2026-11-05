<?php

declare(strict_types=1);

namespace Zana\Idempotency\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Zana\Database\ConnectionFactory;
use Zana\Database\ConnectionInterface;
use Zana\Http\Exception\HttpException;
use Zana\Http\Exception\UnprocessableEntityHttpException;
use Zana\Idempotency\IdempotencyMiddleware;
use Zana\Idempotency\IdempotencyStore;
use Zana\Idempotency\IdempotencyTableMigration;

/**
 * FE-61, ADR-029, on every database available here: a retried request runs
 * once and gets the stored response; a repeat while the first runs gets 409,
 * the key with another request 422; failures free the key.
 */
final class IdempotencyTest extends TestCase
{
    private ConnectionInterface $db;

    private IdempotencyStore $store;

    private PayController $controller;

    private int $now = 1_800_000_000;

    private ?string $user = null;

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return Databases::names();
    }

    #[DataProvider('drivers')]
    public function testARetryGetsTheStoredResponseAndDoesNotPayTwice(string $driver): void
    {
        $this->boot($driver);

        $first = $this->send($this->pay('key-1'));
        $retry = $this->send($this->pay('key-1'));

        $this->assertSame(1, $this->controller->payments);
        $this->assertSame([201, 201], [$first->getStatusCode(), $retry->getStatusCode()]);
        $this->assertSame('{"payment":1}', (string) $retry->getBody());
        $this->assertSame('/payments/1', $retry->getHeaderLine('Location'), 'headers are replayed');
        $this->assertSame(['', 'true'], [$first->getHeaderLine(IdempotencyMiddleware::REPLAYED), $retry->getHeaderLine(IdempotencyMiddleware::REPLAYED)]);
        $this->assertSame('{"payment":1}', (string) $first->getBody(), 'the first response can still be read');

        $this->send($this->pay('key-2'));
        $this->assertSame(2, $this->controller->payments, 'another key is another payment');
    }

    #[DataProvider('drivers')]
    public function testTheKeyWithAnotherRequestIsRefused(string $driver): void
    {
        $this->boot($driver);
        $this->send($this->pay('key-1', '{"amount":25}'));

        try {
            $this->send($this->pay('key-1', '{"amount":2500}'));
            $this->fail('The key was used with another amount.');
        } catch (UnprocessableEntityHttpException $e) {
            $this->assertSame(['Idempotency-Key' => ['This key was already used with a different request.']], $e->errors());
        }
        $this->assertSame(1, $this->controller->payments);
    }

    #[DataProvider('drivers')]
    public function testARepeatWhileTheFirstRunsGets409(string $driver): void
    {
        $this->boot($driver);
        $repeat = null;
        $this->controller->answer = function () use (&$repeat): ResponseInterface {
            try {
                $this->send($this->pay('key-1'));
            } catch (HttpException $e) {
                $repeat = $e;
            }

            return new Response(201, [], 'paid');
        };

        $this->send($this->pay('key-1'));

        $this->assertInstanceOf(HttpException::class, $repeat);
        $this->assertSame([409, ['Retry-After' => '1']], [$repeat->status, $repeat->headers]);
        $this->assertSame(1, $this->controller->payments);
    }

    #[DataProvider('drivers')]
    public function testFailuresFreeTheKeySoTheClientCanRetry(string $driver): void
    {
        $this->boot($driver);
        $this->controller->answer = static fn (): ResponseInterface => throw new \RuntimeException('The payment provider timed out.');
        try {
            $this->send($this->pay('key-1'));
        } catch (\RuntimeException) {
        }

        $this->controller->answer = static fn (): ResponseInterface => new Response(503, [], 'busy');
        $this->assertSame(503, $this->send($this->pay('key-1'))->getStatusCode());

        $this->controller->answer = null;
        $this->assertSame(201, $this->send($this->pay('key-1'))->getStatusCode());
        $this->assertSame(3, $this->controller->payments, 'each failed attempt let the next one run');

        $this->controller->answer = static fn (): ResponseInterface => new Response(402, [], 'card declined');
        $this->assertSame(402, $this->send($this->pay('key-2'))->getStatusCode());
        $this->assertSame('card declined', (string) $this->send($this->pay('key-2'))->getBody(), 'a client error is an answer, and is replayed');
        $this->assertSame(4, $this->controller->payments);
    }

    #[DataProvider('drivers')]
    public function testARequestThatStoppedIsTakenOverOnceItsLockRunsOut(string $driver): void
    {
        $this->boot($driver);
        // A first process claimed the key, then stopped before answering.
        $this->assertTrue($this->store->claim(hash('sha256', "\nkey-1"), $this->fingerprintOf('key-1'), 60, 86_400));

        try {
            $this->send($this->pay('key-1'));
            $this->fail('The lock still holds.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->now += 61;
        $this->assertSame(201, $this->send($this->pay('key-1'))->getStatusCode());
        $this->assertSame(1, $this->controller->payments);
    }

    #[DataProvider('drivers')]
    public function testKeysExpireAndArePruned(string $driver): void
    {
        $this->boot($driver);
        $this->send($this->pay('key-1'));
        $this->assertSame(['stored' => 1, 'running' => 0, 'expired' => 0], $this->store->counts());

        $this->now += 86_400;
        $this->assertSame(['stored' => 0, 'running' => 0, 'expired' => 1], $this->store->counts());
        $this->send($this->pay('key-1'));
        $this->assertSame(2, $this->controller->payments, 'an expired key is a new request');

        $this->now += 86_400;
        $this->assertSame(1, $this->store->prune());
        $this->assertSame(0, $this->db->table(IdempotencyStore::TABLE)->count());
    }

    #[DataProvider('drivers')]
    public function testKeysBelongToTheSignedInUser(string $driver): void
    {
        $this->boot($driver);
        $this->user = 'buyer-1';
        $this->send($this->pay('key-1', '{"amount":25}'));
        $this->user = 'buyer-2';
        $this->send($this->pay('key-1', '{"amount":40}'));

        $this->assertSame(2, $this->controller->payments, "another buyer's key is not this buyer's");
    }

    #[DataProvider('drivers')]
    public function testBinaryBodiesAreReplayedExactly(string $driver): void
    {
        $this->boot($driver);
        $pdf = "%PDF-1.7\0\x01\xff tickets";
        $this->controller->answer = static fn (): ResponseInterface => new Response(200, ['Content-Type' => 'application/pdf'], $pdf);

        $this->send($this->pay('key-1'));
        $this->assertSame($pdf, (string) $this->send($this->pay('key-1'))->getBody());
    }

    public function testTheKeyIsRequiredOnlyWhereTheHandlerSaysSo(): void
    {
        $this->boot('sqlite');

        try {
            $this->send($this->pay(null));
            $this->fail('pay requires a key.');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringContainsString('Idempotency-Key header', $e->getMessage());
        }
        try {
            $this->send($this->pay('bad key'));
            $this->fail('A key with a space is refused.');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->status);
        }

        $optional = new ServerRequest('POST', '/subscribe');
        $this->assertSame('ok', (string) $this->send($optional, 'optional')->getBody(), 'without a key, an optional handler runs');
        $this->send($optional, 'optional');
        $this->assertSame(2, $this->controller->payments, 'unprotected, it runs each time');

        $plain = new ServerRequest('POST', '/plain', ['Idempotency-Key' => 'key-1']);
        $this->send($plain, 'plain');
        $this->assertSame(0, $this->db->table(IdempotencyStore::TABLE)->count(), 'a handler without #[Idempotent] is left alone');
    }

    private function boot(string $driver): void
    {
        $settings = Databases::settings($driver);
        if (is_string($settings)) {
            $this->markTestSkipped($settings);
        }
        $this->db = ConnectionFactory::create($settings);
        (new IdempotencyTableMigration())->down($this->db->schema());
        (new IdempotencyTableMigration())->up($this->db->schema());
        $this->store = new IdempotencyStore($this->db, fn (): int => $this->now);
        $this->controller = new PayController();
    }

    private function send(ServerRequest $request, string $method = 'pay'): ResponseInterface
    {
        $factory = new Psr17Factory();
        $middleware = new IdempotencyMiddleware($this->store, $factory, $factory, fn (): ?string => $this->user);

        return $middleware->process($request->withAttribute('_handler', [PayController::class, $method]), new Handler($this->controller, $method));
    }

    private function pay(?string $key, string $body = '{"amount":25}'): ServerRequest
    {
        return new ServerRequest('POST', '/orders/7/pay', $key === null ? [] : ['Idempotency-Key' => $key], $body);
    }

    /**
     * The fingerprint the middleware computes for pay($key), read back from a stored row.
     */
    private function fingerprintOf(string $key): string
    {
        $probe = new self('probe');
        $probe->boot('sqlite');
        $probe->send($probe->pay($key));
        $row = $probe->db->table(IdempotencyStore::TABLE)->first();

        return (string) ($row['fingerprint'] ?? '');
    }
}
