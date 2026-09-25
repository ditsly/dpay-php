<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * 400 `Amount is below the minimum deposit of N` /
 * `Amount exceeds the maximum deposit of N`. Limits are integers compared
 * against the RAW amount.
 */
class AmountOutOfRangeException extends PaymentRequestException
{
    public const BELOW_PREFIX = 'Amount is below the minimum deposit of ';
    public const ABOVE_PREFIX = 'Amount exceeds the maximum deposit of ';

    /** @param array<string, mixed> $body */
    public function __construct(
        string $message,
        public readonly bool $belowMinimum,
        public readonly ?string $limit,
        array $body = [],
        ?string $requestId = null,
    ) {
        parent::__construct($message, 400, $body, $requestId);
    }

    public function aboveMaximum(): bool
    {
        return !$this->belowMinimum;
    }
}
