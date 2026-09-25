<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * An HTTP error answered by the DPay API. `$message` is the API's own text,
 * byte for byte (legacy `{"message": ...}` or RFC 7807 `detail`/`title`), so
 * merchant support can quote it. Use {@see \DPay\Messages\Messages} to say it
 * in Arabic.
 */
class ApiException extends \RuntimeException implements DPayException
{
    /**
     * @param array<string, mixed> $body      the decoded JSON body
     * @param string|null          $requestId the `x-request-id` header, for support tickets
     * @param string|null          $problemType RFC 7807 `type` (v2 only), e.g. `/api/v2/problems/conflict`
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $body = [],
        public readonly ?string $requestId = null,
        public readonly ?string $problemType = null,
    ) {
        parent::__construct($message, $status);
    }

    /** RFC 7807 `code` / legacy `error` machine code when the API sent one. */
    public function errorCode(): ?string
    {
        foreach (['code', 'error'] as $key) {
            if (isset($this->body[$key]) && is_string($this->body[$key])) {
                return $this->body[$key];
            }
        }

        return null;
    }
}
