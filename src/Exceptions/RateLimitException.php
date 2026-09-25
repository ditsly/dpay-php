<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * HTTP 429 `Too Many Attempts.` The SDK never auto-retries an OTP verify
 * (each attempt consumes an OTP) — show the customer "wait N seconds".
 */
class RateLimitException extends ApiException
{
    /** @param array<string, mixed> $body */
    public function __construct(
        string $message,
        public readonly ?int $retryAfter,
        public readonly ?int $limit = null,
        public readonly ?int $resetAt = null,
        array $body = [],
        ?string $requestId = null,
        ?string $problemType = null,
    ) {
        parent::__construct($message, 429, $body, $requestId, $problemType);
    }
}
