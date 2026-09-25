<?php

declare(strict_types=1);

namespace DPay\Http;

/** One outbound API call, before it becomes a PSR-7 request. */
final class ApiRequest
{
    /**
     * @param array<string, mixed>|null    $body    JSON body (POST) or null
     * @param array<string, string|int>    $query   query-string parameters
     * @param array<string, string>        $headers extra headers (Idempotency-Key, …)
     * @param bool                         $retryable may the transport retry this call on 429/503/connection failure?
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?array $body = null,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly bool $retryable = false,
        public readonly bool $authenticated = true,
    ) {
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->method, $this->path, $this->body, $this->query, $headers, $this->retryable, $this->authenticated);
    }

    public function withRetryable(bool $retryable): self
    {
        return new self($this->method, $this->path, $this->body, $this->query, $this->headers, $retryable, $this->authenticated);
    }
}
