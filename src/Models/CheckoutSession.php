<?php

declare(strict_types=1);

namespace DPay\Models;

use DPay\Money\Money;

/**
 * A hosted checkout session (`/api/v2/checkout-sessions`, amendment A34):
 * a merchant-priced intent for a decimal LYD amount, payable once by any
 * method the merchant offers, on the hosted page at `url`.
 *
 * `amount` is the merchant's figure BEFORE the per-method fee; when paid,
 * `payment->amountCharged` is what the payer was debited.
 */
final class CheckoutSession
{
    /**
     * @param array<string, mixed>  $metadata
     * @param list<string>|null     $allowedMethods
     * @param list<CheckoutAttempt> $attempts       absent on list rows → []
     * @param array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly CheckoutStatus $status,
        public readonly bool $live,
        public readonly Money $amount,
        public readonly string $currency,
        public readonly ?string $description,
        public readonly ?string $reference,
        public readonly string $returnUrl,
        public readonly ?string $cancelUrl,
        public readonly array $metadata,
        public readonly ?CheckoutCustomer $customer,
        public readonly ?array $allowedMethods,
        public readonly ?string $locale,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $paidAt,
        public readonly ?\DateTimeImmutable $cancelledAt,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt,
        public readonly ?CheckoutPayment $payment,
        public readonly array $attempts,
        public readonly bool $idempotentReplay,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a, bool $idempotentReplay = false): self
    {
        $customer = Read::arrayOrNull($a, 'customer');
        $payment = Read::arrayOrNull($a, 'payment');
        $attempts = [];
        foreach ((isset($a['attempts']) && is_array($a['attempts'])) ? $a['attempts'] : [] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $attempts[] = CheckoutAttempt::fromArray($row);
            }
        }
        $allowed = null;
        if (isset($a['allowed_methods']) && is_array($a['allowed_methods'])) {
            $allowed = [];
            foreach ($a['allowed_methods'] as $slug) {
                if (is_string($slug)) {
                    $allowed[] = $slug;
                }
            }
        }

        return new self(
            id: Read::string($a, 'id'),
            url: Read::string($a, 'url'),
            status: CheckoutStatus::fromApi(Read::string($a, 'status')),
            live: Read::bool($a, 'live', true),
            amount: Read::money($a, 'amount'),
            currency: Read::stringOrNull($a, 'currency') ?? 'LYD',
            description: Read::stringOrNull($a, 'description'),
            reference: Read::stringOrNull($a, 'reference'),
            returnUrl: Read::string($a, 'return_url'),
            cancelUrl: Read::stringOrNull($a, 'cancel_url'),
            metadata: Read::arrayOrNull($a, 'metadata') ?? [],
            customer: $customer === null ? null : CheckoutCustomer::fromArray($customer),
            allowedMethods: $allowed,
            locale: Read::stringOrNull($a, 'locale'),
            expiresAt: Dates::parse($a['expires_at'] ?? null),
            paidAt: Dates::parse($a['paid_at'] ?? null),
            cancelledAt: Dates::parse($a['cancelled_at'] ?? null),
            createdAt: Dates::parse($a['created_at'] ?? null),
            updatedAt: Dates::parse($a['updated_at'] ?? null),
            payment: $payment === null ? null : CheckoutPayment::fromArray($payment),
            attempts: $attempts,
            idempotentReplay: $idempotentReplay,
            raw: $a,
        );
    }

    public function isOpen(): bool
    {
        return $this->status === CheckoutStatus::Open;
    }

    public function isPaid(): bool
    {
        return $this->status === CheckoutStatus::Paid;
    }

    public function isSandbox(): bool
    {
        return !$this->live;
    }

    /**
     * The guard every store transition runs before marking an order paid:
     * amount (at 2dp), currency and reference must match what the order
     * expects. On a mismatch do NOT complete — note it for review.
     */
    public function matchesOrder(Money|string|int|float $amount, string $currency = 'LYD', ?string $reference = null): bool
    {
        $expected = $amount instanceof Money ? $amount : Money::of($amount);
        if (!$this->amount->equalsAt($expected, 2)) {
            return false;
        }
        if (strtoupper($this->currency) !== strtoupper($currency)) {
            return false;
        }
        if ($reference !== null && $this->reference !== $reference) {
            return false;
        }

        return true;
    }

    public function metadataValue(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
    }
}
