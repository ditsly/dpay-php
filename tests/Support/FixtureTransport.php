<?php

declare(strict_types=1);

namespace DPay\Tests\Support;

use DPay\Exceptions\ConnectionException;
use DPay\Http\ApiResponse;
use DPay\Http\TransportInterface;

/**
 * A transport that answers from a queue of canned responses and records
 * every request it was asked to send.
 */
final class FixtureTransport implements TransportInterface
{
    /** @var list<ApiResponse|ConnectionException> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null, timeout: float}> */
    public array $requests = [];

    /** @param array<string, string> $headers */
    public function enqueue(int $status, string $body, array $headers = ['content-type' => 'application/json']): self
    {
        $this->queue[] = new ApiResponse($status, array_change_key_case($headers, CASE_LOWER), $body);

        return $this;
    }

    /** @param array<mixed> $json */
    public function enqueueJson(int $status, array $json, array $headers = []): self
    {
        return $this->enqueue($status, (string) json_encode($json), array_merge(['content-type' => 'application/json'], $headers));
    }

    public function enqueueFailure(string $message = 'connection refused'): self
    {
        $this->queue[] = new ConnectionException($message);

        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): ApiResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException(sprintf('FixtureTransport: no response queued for %s %s', $method, $url));
        }
        if ($next instanceof ConnectionException) {
            throw $next;
        }

        return $next;
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: string|null, timeout: float} */
    public function last(): array
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No request was sent.');
        }

        return $last;
    }

    /** @return array<string, mixed> */
    public function lastJson(): array
    {
        $body = $this->last()['body'];
        $decoded = $body === null ? [] : json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \LogicException('Last request body is not JSON.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function count(): int
    {
        return count($this->requests);
    }
}
