<?php

declare(strict_types=1);

namespace DPay\Models;

use DPay\Money\Money;

/**
 * A settled Payment row — nested in the verify answer and listed by
 * `GET /api/payments`. Rows are stored as completed/refunded/voided.
 */
final class PaymentRecord
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly int $id,
        public readonly ?int $userId,
        public readonly ?int $companyId,
        public readonly ?int $paymentSessionId,
        public readonly Money $amount,
        public readonly ?string $currency,
        public readonly string $status,
        public readonly ?string $payMethod,
        public readonly ?string $txId,
        public readonly ?string $systemReference,
        public readonly ?string $networkReference,
        public readonly ?string $paidThrough,
        public readonly ?string $payerAccount,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            id: Read::int($a, 'id'),
            userId: Read::intOrNull($a, 'user_id'),
            companyId: Read::intOrNull($a, 'company_id'),
            paymentSessionId: Read::intOrNull($a, 'payment_session_id'),
            amount: Read::money($a, 'amount'),
            currency: Read::stringOrNull($a, 'currency'),
            status: Read::string($a, 'status'),
            payMethod: Read::stringOrNull($a, 'pay_method'),
            txId: Read::stringOrNull($a, 'tx_id'),
            systemReference: Read::stringOrNull($a, 'system_reference'),
            networkReference: Read::stringOrNull($a, 'network_reference'),
            paidThrough: Read::stringOrNull($a, 'paid_through'),
            payerAccount: Read::stringOrNull($a, 'payer_account'),
            createdAt: Dates::parse($a['created_at'] ?? null),
            updatedAt: Dates::parse($a['updated_at'] ?? null),
            raw: $a,
        );
    }
}
