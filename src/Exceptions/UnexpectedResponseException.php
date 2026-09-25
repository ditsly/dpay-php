<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * The server answered, but not with something the SDK can read: a redirect
 * (the SDK never follows them), a non-JSON body, or a 2xx whose shape does not
 * match the documented contract.
 */
class UnexpectedResponseException extends \RuntimeException implements DPayException
{
    /** @param array<string, mixed>|null $body */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?array $body = null,
        public readonly ?string $rawBody = null,
    ) {
        parent::__construct($message);
    }
}
