<?php

/**
 * Builds a ClaimRevApi backed by a Guzzle MockHandler.
 *
 * Tests get a real GuzzleHttp\Client, so URL building, auth headers, and JSON
 * encoding are genuinely exercised; only the wire is faked. The recorded
 * request history lets a test assert what the module actually sent.
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClaimRevConnector\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClaimRevConnector\ClaimRevApi;

final class MockApiFactory
{
    /** @var list<array<string, mixed>> Guzzle's recorded transaction history. */
    private array $history = [];

    public readonly ClaimRevApi $api;

    /**
     * @param list<Response|\Throwable> $queue Responses returned in order, one per request.
     */
    public function __construct(array $queue, string $accessToken = 'test-token')
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.example.test',
        ]);
        $this->api = new ClaimRevApi($client, $accessToken);
    }

    /**
     * Convenience constructor for the common single-JSON-response case.
     *
     * @param array<string|int, mixed> $payload
     */
    public static function withJson(array $payload, int $status = 200): self
    {
        return new self([
            new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
    }

    /** How many requests were recorded. */
    public function requestCount(): int
    {
        return count($this->history);
    }

    /** The nth recorded request, zero-indexed. PSR-7 requests are immutable, so this is safe to hand out. */
    public function request(int $index = 0): \Psr\Http\Message\RequestInterface
    {
        return $this->history[$index]['request'];
    }

    /** The path (with query string) of the nth recorded request, zero-indexed. */
    public function requestTarget(int $index = 0): string
    {
        return $this->request($index)->getRequestTarget();
    }

    /** The decoded JSON body of the nth recorded request, zero-indexed. */
    public function requestBody(int $index = 0): mixed
    {
        return json_decode((string) $this->request($index)->getBody(), true);
    }

    /** The value of a header on the nth recorded request, zero-indexed. */
    public function requestHeader(string $name, int $index = 0): string
    {
        return $this->request($index)->getHeaderLine($name);
    }
}
