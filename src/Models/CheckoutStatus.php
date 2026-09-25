<?php

declare(strict_types=1);

namespace DPay\Models;

/** `open` → `paid` | `expired` | `cancelled` (merchant-initiated only). */
enum CheckoutStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? throw new \DPay\Exceptions\UnexpectedResponseException(sprintf('Unknown checkout status "%s".', $value), 200);
    }

    public function isTerminal(): bool
    {
        return $this !== self::Open;
    }
}
