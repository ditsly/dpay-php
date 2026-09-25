<?php

declare(strict_types=1);

namespace DPay\Models;

use DPay\Money\Money;

/**
 * The winning attempt of a paid checkout. Record `amountCharged` (the 2dp
 * fee-inclusive debit) and `txId`; never recompute fees.
 */
final class CheckoutPayment
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly int $sessionId,
        public readonly ?string $payMethod,
        public readonly ?string $txId,
        public readonly Money $amountCharged,
        public readonly ?Money $feeAmount,
        public readonly ?string $feePercent,
        public readonly ?\DateTimeImmutable $paidAt,
        public readonly ?string $receiptUrl,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            sessionId: Read::int($a, 'session_id'),
            payMethod: Read::stringOrNull($a, 'pay_method'),
            txId: Read::stringOrNull($a, 'tx_id'),
            amountCharged: Read::money($a, 'amount_charged'),
            feeAmount: Read::moneyOrNull($a, 'fee_amount'),
            feePercent: Read::moneyOrNull($a, 'fee_percent')?->value,
            paidAt: Dates::parse($a['paid_at'] ?? null),
            receiptUrl: Read::stringOrNull($a, 'receipt_url'),
            raw: $a,
        );
    }
}
