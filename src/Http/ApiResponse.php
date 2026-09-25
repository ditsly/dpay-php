<?php

declare(strict_types=1);

namespace DPay\Http;

/** The raw answer: status, lower-cased headers, body bytes, and the decoded JSON when it is JSON. */
final class ApiResponse
{
    /** @param array<string, string> $headers lower-case names */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $rawBody,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function requestId(): ?string
    {
        return $this->header('x-request-id');
    }

    public function isJson(): bool
    {
        $type = $this->header('content-type') ?? '';

        return str_contains($type, 'json');
    }

    /**
     * Decoded JSON, or null when the body is not valid JSON. Objects decode
     * to associative arrays; a JSON array (the sandbox pay-methods list)
     * decodes to a list.
     *
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        if ($this->rawBody === '') {
            return null;
        }
        try {
            $decoded = json_decode($this->rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function retryAfterSeconds(): ?int
    {
        $value = $this->header('retry-after');
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) $value;
        }
        $at = strtotime($value);

        return $at === false ? null : max(0, $at - time());
    }
}
