<?php

declare(strict_types=1);

namespace DPay\Models;

use DPay\Money\Money;

/**
 * `GET /api/payment/sessions/{id}` — the authoritative status. `amount` is
 * the 2dp fee-inclusive charge. `payment_link` is present for Moamalat only;
 * the MPGS page is `{base}/mpgs-pay/{id}` (persist it from the open).
 */
final class PaymentSession
{
    /**
     * @param array<string, mixed>|null $data stored data: your keys + `original_amount`, `fee_percent`, `fee_amount`, `return_url`
     * @param array<string, mixed>      $raw
     */
    public function __construct(
        public readonly int $sessionId,
        public readonly SessionStatus $status,
        public readonly Money $amount,
        public readonly ?string $payMethod,
        public readonly ?string $txId,
        public readonly ?\DateTimeImmutable $expiredAt,
        public readonly ?array $data,
        public readonly ?string $paymentLink,
        public readonly bool $sandbox,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            sessionId: Read::int($a, 'session_id'),
            status: SessionStatus::fromApi(Read::string($a, 'status')),
            amount: Read::money($a, 'amount'),
            payMethod: Read::stringOrNull($a, 'pay_method'),
            txId: Read::stringOrNull($a, 'tx_id'),
            expiredAt: Dates::parse($a['expired_at'] ?? null),
            data: Read::arrayOrNull($a, 'data'),
            paymentLink: Read::stringOrNull($a, 'payment_link'),
            sandbox: Read::bool($a, 'sandbox'),
            raw: $a,
        );
    }

    public function isPaid(): bool
    {
        return $this->status === SessionStatus::Paid;
    }

    public function isPending(): bool
    {
        return $this->status === SessionStatus::Pending;
    }

    /** Pending but past `expired_at` — the server will expire it on its next read. */
    public function isPastExpiry(?\DateTimeInterface $now = null): bool
    {
        if ($this->expiredAt === null) {
            return false;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->expiredAt->getTimestamp() <= $now->getTimestamp();
    }

    /** The raw amount the merchant asked for (`data.original_amount`), when stored. */
    public function originalAmount(): ?Money
    {
        return Money::fromApi($this->data['original_amount'] ?? null);
    }

    public function dataValue(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
