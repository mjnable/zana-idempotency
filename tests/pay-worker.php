<?php

declare(strict_types=1);

// One buyer process of ConcurrencyTest: waits until the shared start time, then sends "pay" with the
// shared Idempotency-Key through the middleware. The handler records a payment row and takes 300 ms,
// as a payment provider would. Prints the status, with " replayed" for a stored response.

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Database\ConnectionFactory;
use Zana\Database\ConnectionInterface;
use Zana\Http\Exception\HttpException;
use Zana\Idempotency\IdempotencyMiddleware;
use Zana\Idempotency\IdempotencyStore;
use Zana\Idempotency\Tests\PayController;

require dirname(__DIR__) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
assert(is_array($arguments) && is_string($arguments[1] ?? null) && is_string($arguments[2] ?? null));
$settings = json_decode($arguments[1], true, flags: JSON_THROW_ON_ERROR);
assert(is_array($settings));
$db = ConnectionFactory::create($settings);
if ($db->driver() === 'sqlite') {
    $db->execute('PRAGMA busy_timeout = 10000');
}

$handler = new readonly class ($db) implements RequestHandlerInterface {
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->db->table('payments')->insert(['pid' => getmypid()]);
        usleep(300_000);

        return new Response(201, [], 'paid');
    }
};

$factory = new Psr17Factory();
$middleware = new IdempotencyMiddleware(new IdempotencyStore($db), $factory, $factory, static fn (): string => 'buyer-1');
$request = (new ServerRequest('POST', '/orders/7/pay', ['Idempotency-Key' => 'pay-7'], '{"amount":25}'))
    ->withAttribute('_handler', [PayController::class, 'pay']);

while (microtime(true) < (float) $arguments[2]) {
    usleep(1_000);
}
try {
    $response = $middleware->process($request, $handler);
    echo $response->getStatusCode(), $response->getHeaderLine(IdempotencyMiddleware::REPLAYED) === 'true' ? ' replayed' : '';
} catch (HttpException $e) {
    echo $e->status;
}
