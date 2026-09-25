<?php

declare(strict_types=1);

namespace DPay\Config;

use DPay\Exceptions\InvalidArgumentException;
use DPay\Gateways\Rules;

/**
 * LIVE or SANDBOX — and every difference between the two, in one place.
 *
 * The environment is decided by the token: an integration token
 * (`{id}|{40 alnum}{8 hex}`) is live; a `sb_tk_…` token is sandbox and works
 * ONLY on `/api/sandbox/*` (a live token there, or a sandbox token on a live
 * path, is a 401).
 */
enum Environment: string
{
    case Live = 'live';
    case Sandbox = 'sandbox';

    public const SANDBOX_TOKEN_PREFIX = 'sb_tk_';
    public const SANDBOX_OTP_SUCCESS = '111111';
    public const SANDBOX_OTP_FAILURE = '000000';

    public static function fromToken(string $token): self
    {
        return str_starts_with($token, self::SANDBOX_TOKEN_PREFIX) ? self::Sandbox : self::Live;
    }

    /** Refuse a token of the wrong kind before a single request is sent. */
    public function assertTokenMatches(string $token): void
    {
        $actual = self::fromToken($token);
        if ($actual !== $this) {
            throw new InvalidArgumentException(sprintf(
                'A %s token was given to a %s client (%s tokens start with "%s").',
                $actual->value,
                $this->value,
                self::Sandbox->value,
                self::SANDBOX_TOKEN_PREFIX,
            ));
        }
    }

    public function isLive(): bool
    {
        return $this === self::Live;
    }

    public function isSandbox(): bool
    {
        return $this === self::Sandbox;
    }

    /** v1 path prefix: `/api` or `/api/sandbox`. */
    public function pathPrefix(): string
    {
        return $this->isLive() ? '/api' : '/api/sandbox';
    }

    /**
     * `Idempotency-Key` on open. Live has always honoured it; the sandbox
     * honours it since amendment A34 (before that it was ignored — a retried
     * sandbox open on an older API could open a second session).
     */
    public function supportsIdempotency(): bool
    {
        return true;
    }

    /**
     * Slugs the sandbox does not simulate — opening one there is a 422
     * `Unsupported payment method: {slug}`.
     *
     * @return list<string>
     */
    public function hiddenSlugs(): array
    {
        return $this->isLive() ? [] : ['sadad', 'mpgs'];
    }

    public function supportsSlug(string $slug): bool
    {
        return !in_array($slug, $this->hiddenSlugs(), true);
    }

    /** Session expiry in minutes: per gateway live, a flat 5 in the sandbox. */
    public function expiryMinutes(string $slug): int
    {
        return $this->isLive() ? Rules::expiryMinutes($slug) : Rules::SANDBOX_EXPIRY_MINUTES;
    }

    /**
     * Sandbox verify/get answer `amount` as a decimal STRING ("10.61");
     * live answers a JSON number. {@see \DPay\Money\Money::fromApi()} reads both.
     */
    public function amountsAreStrings(): bool
    {
        return $this->isSandbox();
    }

    /** Sandbox open/verify/get bodies carry `sandbox: true` and no `currency`. */
    public function bodiesCarryCurrency(): bool
    {
        return $this->isLive();
    }

    /** `GET /api/sandbox/pay-methods` is a BARE array; live is `{data: [...]}`. */
    public function payMethodsAreEnveloped(): bool
    {
        return $this->isLive();
    }

    /** Sandbox fee is 2dp and the total unrounded (legacy quirk); live is 3dp/3dp. */
    public function feeDecimals(): int
    {
        return $this->isLive() ? 3 : 2;
    }

    /** `payment_link` in the sandbox exists for Moamalat only, and is tokenised. */
    public function hostsMpgsPage(): bool
    {
        return $this->isLive();
    }

    /** Webhook `live` flag expected for this environment. */
    public function webhookLiveFlag(): bool
    {
        return $this->isLive();
    }

    /**
     * Magic OTPs — sandbox only; null in live.
     *
     * @return array{success: string, failure: string}|null
     */
    public function magicOtps(): ?array
    {
        return $this->isSandbox()
            ? ['success' => self::SANDBOX_OTP_SUCCESS, 'failure' => self::SANDBOX_OTP_FAILURE]
            : null;
    }

    /** Checkout-session ids: `cs_…` live, `cs_test_…` sandbox. */
    public function checkoutIdPrefix(): string
    {
        return $this->isLive() ? 'cs_' : 'cs_test_';
    }

    /**
     * Throttle sizes per minute, for pacing (open, verify, default pool).
     *
     * @return array{open: int, verify: int, pool: int}
     */
    public function throttles(): array
    {
        return $this->isLive()
            ? ['open' => 20, 'verify' => 5, 'pool' => 60]
            : ['open' => 20, 'verify' => 30, 'pool' => 60];
    }
}
