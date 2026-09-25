<?php

declare(strict_types=1);

namespace DPay\Models;

use DPay\Money\Money;

/**
 * `POST /api/payment/sessions/verify` answered 200: the session is PAID —
 * either now (`Payment verified successfully`, with the nested payment
 * record) or earlier (`Payment already verified`). Both are success: treat
 * them identically and fulfil once.
 *
 * `paymentId` is null on an already-verified answer for a session that has
 * no Payment row (a paid session migrated from legacy, or a double submit
 * racing the record) — spec 01 §3.4 documents the field as null-safe.
 * Success is decided by `status`/`message`, never by the id.
 */
final class VerifyResult
{
    public const VERIFIED = 'Payment verified successfully';
    public const ALREADY_VERIFIED = 'Payment already verified';

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $message,
        public readonly ?int $paymentId,
        public readonly SessionStatus $status,
        public readonly Money $amount,
        public readonly ?string $payMethod,
        public readonly ?string $txId,
        public readonly ?string $receiptUrl,
        public readonly ?PaymentRecord $payment,
        public readonly bool $sandbox,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $payment = Read::arrayOrNull($a, 'payment');

        return new self(
            message: Read::stringOrNull($a, 'message') ?? '',
            paymentId: Read::intOrNull($a, 'payment_id'),
            status: SessionStatus::fromApi(Read::string($a, 'status')),
            amount: Read::money($a, 'amount'),
            payMethod: Read::stringOrNull($a, 'pay_method'),
            txId: Read::stringOrNull($a, 'tx_id'),
            receiptUrl: Read::stringOrNull($a, 'receipt_url'),
            payment: $payment === null ? null : PaymentRecord::fromArray($payment),
            sandbox: Read::bool($a, 'sandbox'),
            raw: $a,
        );
    }

    public function alreadyVerified(): bool
    {
        return $this->message === self::ALREADY_VERIFIED;
    }

    public function isPaid(): bool
    {
        return $this->status === SessionStatus::Paid;
    }
}
