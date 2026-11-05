<?php

declare(strict_types=1);

namespace Zana\Idempotency;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Http\Exception\BadRequestHttpException;
use Zana\Http\Exception\HttpException;
use Zana\Http\Exception\UnprocessableEntityHttpException;
use Zana\Idempotency\Internal\HandlerAttribute;

/**
 * Enforces #[Idempotent] on the matched handler, so it runs as route
 * middleware (FE-61, ADR-029). The first request with an Idempotency-Key
 * runs and its response is stored; a repeat with the same request gets that
 * response again (Idempotent-Replayed: true), a repeat while the first is
 * still running gets 409, and the same key with another request gets 422.
 *
 * Keys belong to the signed-in user when there is one. A response of 500 or
 * more, or an exception, frees the key, so the client can retry.
 */
final readonly class IdempotencyMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'Idempotency-Key';

    public const string REPLAYED = 'Idempotent-Replayed';

    /**
     * @param \Closure(): (string|null) $user The signed-in user's identifier
     */
    public function __construct(
        private IdempotencyStore $store,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private \Closure $user,
        private int $ttl = 86_400,
        private int $lockSeconds = 60,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $options = HandlerAttribute::first($request->getAttribute('_handler'), Idempotent::class);
        if ($options === null) {
            return $handler->handle($request);
        }

        $key = $request->getHeaderLine(self::HEADER);
        if ($key === '') {
            if ($options->required) {
                throw new BadRequestHttpException('This request needs an Idempotency-Key header, so that a retry does not run it twice.');
            }

            return $handler->handle($request);
        }
        if (strlen($key) > 255 || preg_match('/^[\x21-\x7e]+$/', $key) !== 1) {
            throw new BadRequestHttpException('The Idempotency-Key header must be 1 to 255 visible ASCII characters.');
        }

        $id = hash('sha256', ($this->user)() . "\n" . $key);
        $fingerprint = self::fingerprint($request);
        $ttl = $options->ttl ?? $this->ttl;

        if (!$this->store->claim($id, $fingerprint, $this->lockSeconds, $ttl)) {
            $stored = $this->store->find($id);
            if ($stored === null || $stored['expires_at'] <= $this->store->now()) {
                // Freed or expired since the claim: claim it once more.
                $this->store->forgetExpired($id);
                if (!$this->store->claim($id, $fingerprint, $this->lockSeconds, $ttl)) {
                    throw self::running();
                }
            } elseif ($stored['fingerprint'] !== $fingerprint) {
                throw new UnprocessableEntityHttpException(
                    [self::HEADER => 'This key was already used with a different request.'],
                    'The Idempotency-Key was already used with a different request.',
                );
            } elseif ($stored['status'] !== null) {
                return $this->replay($stored['status'], $stored['headers'], $stored['body']);
            } elseif ($stored['locked_until'] >= $this->store->now() || !$this->store->takeOver($id, $stored['locked_until'], $this->lockSeconds)) {
                throw self::running();
            }
        }

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            $this->store->release($id);
            throw $e;
        }
        if ($response->getStatusCode() >= 500) {
            $this->store->release($id);

            return $response;
        }

        $body = (string) $response->getBody();
        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        }
        $this->store->complete($id, $response->getStatusCode(), array_map(array_values(...), $response->getHeaders()), $body);

        return $response;
    }

    /**
     * The method, path, query and body: a repeat must be the same request.
     */
    private static function fingerprint(ServerRequestInterface $request): string
    {
        $body = $request->getBody();
        $content = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return hash('sha256', (string) json_encode([
            $request->getMethod(),
            $request->getUri()->getPath(),
            $request->getQueryParams(),
            hash('sha256', $content),
            $content === '' ? $request->getParsedBody() : null,   // requests built in tests carry a parsed body only
        ], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private static function running(): HttpException
    {
        return new HttpException(409, 'A request with this Idempotency-Key is still running; retry it shortly.', ['Retry-After' => '1']);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function replay(int $status, array $headers, string $body): ResponseInterface
    {
        $response = $this->responses->createResponse($status)->withBody($this->streams->createStream($body));
        foreach ($headers as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response->withHeader(self::REPLAYED, 'true');
    }
}
