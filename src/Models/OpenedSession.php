<?php

declare(strict_types=1);

namespace DPay\Models;

use DPay\Money\Money;

/**
 * `POST /api/payment/sessions/open` answered 200.
 *
 * `amount` is the RAW request amount echoed; `feeAmount`/`total` are the
 * live 3dp figures (sandbox: 2dp fee, unrounded total); the payer is charged
 * {@see OpenedSession::charge()} = round(total, 2), which is the `amount`
 * every later read, the verify answer and the webhook report.
 */
final class OpenedSession
{
    /**
     * @param array<string, mixed>|null $data     the request `data` echoed verbatim (replays: the STORED data)
     * @param bool                      $replayed an Idempotency-Key replay of an earlier open ({@see OpenedSession::isReplay()}); the fields describe THAT session
     * @param array<string, mixed>      $raw
     */
    public function __construct(
        public readonly int $sessionId,
        public readonly SessionStatus $status,
        public readonly Money $amount,
        public readonly ?string $currency,
        public readonly string $feePercent,
        public readonly Money $feeAmount,
        public readonly Money $total,
        public readonly string $payMethod,
        public readonly \DateTimeImmutable $expiredAt,
        public readonly ?array $data,
        public readonly ?string $paymentLink,
        public readonly bool $sandbox,
        public readonly bool $replayed,
        public readonly string $message,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a, bool $live): self
    {
        $currency = Read::stringOrNull($a, 'currency');
        $data = Read::arrayOrNull($a, 'data');

        return new self(
            sessionId: Read::int($a, 'session_id'),
            status: SessionStatus::fromApi(Read::string($a, 'status')),
            amount: Read::money($a, 'amount'),
            currency: $currency,
            feePercent: Read::money($a, 'fee')->value,
            feeAmount: Read::money($a, 'fee_amount'),
            total: Read::money($a, 'total'),
            payMethod: Read::string($a, 'pay_method'),
            expiredAt: Dates::require($a['expired_at'] ?? null, 'expired_at'),
            data: $data,
            paymentLink: Read::stringOrNull($a, 'payment_link'),
            sandbox: Read::bool($a, 'sandbox'),
            replayed: self::isReplay($live, $currency, $data, Read::string($a, 'status')),
            message: Read::stringOrNull($a, 'message') ?? '',
            raw: $a,
        );
    }

    /**
     * Was this answer an Idempotency-Key REPLAY of an earlier open?
     *
     *   - live: a replay omits `currency` (a fresh open always carries it);
     *   - sandbox (A34: the key is honoured there too): the replay body is
     *     byte-shaped like a fresh open and echoes the STORED `data`, which
     *     carries the server's own keys (`fee_percent`, `original_amount`)
     *     — a fresh open echoes the request's `data` verbatim — and is no
     *     longer `pending` once the session settled.
     *
     * @param array<string, mixed>|null $data
     */
    private static function isReplay(bool $live, ?string $currency, ?array $data, string $status): bool
    {
        if ($live) {
            return $currency === null;
        }
        if ($status !== SessionStatus::Pending->value) {
            return true;
        }

        return $data !== null && (array_key_exists('fee_percent', $data) || array_key_exists('original_amount', $data));
    }

    /** What the payer is charged: `round(total, 2)`. Store THIS on the order. */
    public function charge(): Money
    {
        return $this->total->chargeRounded();
    }

    /** OTP gateways have no link; Moamalat/MPGS redirect the customer here. */
    public function requiresRedirect(): bool
    {
        return $this->paymentLink !== null;
    }

    public function isExpiredAt(?\DateTimeInterface $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->expiredAt->getTimestamp() <= $now->getTimestamp();
    }

    /** Merchant keys inside `data` survive the server's merge; read yours back here. */
    public function dataValue(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
