<?php

declare(strict_types=1);

namespace DPay\Models;

/** One payment session the customer opened on the hosted page (newest first). */
final class CheckoutAttempt
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly int $sessionId,
        public readonly ?string $payMethod,
        public readonly SessionStatus $status,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            sessionId: Read::int($a, 'session_id'),
            payMethod: Read::stringOrNull($a, 'pay_method'),
            status: SessionStatus::fromApi(Read::string($a, 'status')),
            createdAt: Dates::parse($a['created_at'] ?? null),
            raw: $a,
        );
    }
}
