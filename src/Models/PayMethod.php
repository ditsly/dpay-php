<?php

declare(strict_types=1);

namespace DPay\Models;

use DPay\Gateways\Rules;
use DPay\Money\Money;

/**
 * One row of `GET /api/pay-methods` (live, enveloped) or
 * `GET /api/sandbox/pay-methods` (bare array with fewer keys). Skip a method
 * unless {@see PayMethod::isUsable()}: `configured` and `enabled` reflect the
 * merchant's own setup, `active` the platform's.
 */
final class PayMethod
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $feePercent,
        public readonly int $minDeposit,
        public readonly int $maxDeposit,
        public readonly bool $active,
        public readonly bool $enabled,
        public readonly ?bool $configured,
        public readonly ?bool $crossBankEnabled,
        public readonly ?string $currency,
        public readonly ?int $id,
        public readonly ?string $icon,
        public readonly ?string $logoUrl,
        public readonly ?int $otpLength,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $slug = isset($a['slug']) && is_string($a['slug']) ? $a['slug'] : Read::string($a, 'tag');

        return new self(
            slug: $slug,
            name: Read::string($a, 'name'),
            feePercent: Read::money($a, 'fee')->value,
            minDeposit: Read::int($a, 'min_deposit'),
            maxDeposit: Read::int($a, 'max_deposit'),
            active: Read::bool($a, 'active'),
            enabled: Read::bool($a, 'enabled'),
            configured: array_key_exists('configured', $a) ? Read::bool($a, 'configured') : null,
            crossBankEnabled: array_key_exists('cross_bank_enabled', $a) ? Read::bool($a, 'cross_bank_enabled') : null,
            currency: Read::stringOrNull($a, 'currency'),
            id: Read::intOrNull($a, 'id'),
            icon: Read::stringOrNull($a, 'icon'),
            logoUrl: Read::stringOrNull($a, 'logo_url'),
            // A34: `otp_length` (4/6 for OTP methods, null otherwise); absent on older APIs → the Rules table.
            otpLength: array_key_exists('otp_length', $a) ? Read::intOrNull($a, 'otp_length') : Rules::otpLength($slug),
            raw: $a,
        );
    }

    /** Platform-active, merchant-configured (when the shape says) and merchant-enabled. */
    public function isUsable(): bool
    {
        return $this->active && $this->enabled && $this->configured !== false;
    }

    /** The RAW amount must sit inside `[min_deposit, max_deposit]` (integers) or open is a 400. */
    public function allowsAmount(Money $amount): bool
    {
        return !$amount->isLessThan(Money::of($this->minDeposit)) && !$amount->isGreaterThan(Money::of($this->maxDeposit));
    }

    /** Charges in USD on the raw API path (Mastercard) — see the README before offering it. */
    public function chargesUsd(): bool
    {
        return $this->currency === 'USD';
    }
}
