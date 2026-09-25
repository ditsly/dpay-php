<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * The gateway refused the payment and the session is now FAILED (a
 * non-retryable outcome): the sandbox `000000` simulated decline, and the
 * final decline of a non-OTP gateway.
 */
class GatewayDeclinedException extends PaymentRequestException
{
    public const SANDBOX_MESSAGE = 'Sandbox: gateway declined the transaction (simulated final failure)';

    /** @param array<string, mixed> $body */
    public function __construct(
        string $message,
        int $status,
        public readonly ?int $sessionId = null,
        array $body = [],
        ?string $requestId = null,
    ) {
        parent::__construct($message, $status, $body, $requestId);
    }
}
