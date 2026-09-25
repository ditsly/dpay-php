<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * HTTP 422 carrying a field map — the Laravel `{"message","errors"}` envelope
 * on v1 and the RFC 7807 `errors` member on v2.
 */
class ValidationException extends ApiException
{
    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>        $body
     */
    public function __construct(
        string $message,
        public readonly array $errors,
        int $status = 422,
        array $body = [],
        ?string $requestId = null,
        ?string $problemType = null,
    ) {
        parent::__construct($message, $status, $body, $requestId, $problemType);
    }

    /** @return list<string> */
    public function fields(): array
    {
        return array_keys($this->errors);
    }

    public function has(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    public function first(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }
}
