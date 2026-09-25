<?php

declare(strict_types=1);

namespace DPay\Tests\Support;

/** One recorded request/response pair of the Postman collection. */
final class Sample
{
    /**
     * @param array<string, string> $headers         request headers (variables substituted)
     * @param array<string, string> $responseHeaders lower-case names
     */
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly string $responseName,
        public readonly int $responseCode,
        public readonly array $responseHeaders,
        public readonly string $responseBody,
    ) {
    }

    public function key(): string
    {
        return $this->path.'/'.$this->name.' → '.$this->responseName;
    }

    public function urlPath(): string
    {
        return (string) parse_url($this->url, PHP_URL_PATH);
    }

    public function isWebhook(): bool
    {
        return str_starts_with($this->path, '/Webhooks');
    }

    public function isSandbox(): bool
    {
        return str_contains($this->url, '/api/sandbox/');
    }

    /** @return array<string, mixed> */
    public function bodyJson(): array
    {
        if ($this->body === null) {
            return [];
        }
        $decoded = json_decode($this->body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Sample body is not JSON: '.$this->key());
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function responseJson(): array
    {
        $decoded = json_decode($this->responseBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Sample response is not JSON: '.$this->key());
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
