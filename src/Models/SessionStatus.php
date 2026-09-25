<?php

declare(strict_types=1);

namespace DPay\Models;

/** Payment-session lifecycle. Every session ends in exactly one terminal status. */
enum SessionStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case Voided = 'voided';

    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? throw new \DPay\Exceptions\UnexpectedResponseException(sprintf('Unknown session status "%s".', $value), 200);
    }

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /** Money was collected (paid, or paid then refunded/voided — the payment record exists). */
    public function wasPaid(): bool
    {
        return in_array($this, [self::Paid, self::Refunded, self::Voided], true);
    }
}
