<?php

declare(strict_types=1);

namespace DPay\Money;

/**
 * The legacy fee pipeline (architecture amendment A4), reproduced exactly:
 *
 *   fee     = round((amount × fee%) / 100, 3)   — live open response `fee_amount`
 *   total   = round(amount + fee, 3)            — live open response `total`
 *   charge  = round(total, 2)                   — what is collected and what
 *                                                 GET / verify / webhooks report
 *   fee2dp  = round((amount × fee%) / 100, 2)   — sandbox fee and the
 *                                                 idempotent-replay fallback
 *
 * The multiply/divide/add happen in IEEE doubles because that is what the
 * legacy PHP did; only the rounding is exact. Use this to PREVIEW what the
 * API will answer — never to decide what was charged: read `amount` back.
 */
final class FeeMath
{
    public static function fee(Money $amount, string|float|int $feePercent): Money
    {
        return Money::of(PhpRound::format(($amount->toFloat() * (float) $feePercent) / 100, 3));
    }

    public static function fee2dp(Money $amount, string|float|int $feePercent): Money
    {
        return Money::of(PhpRound::format(($amount->toFloat() * (float) $feePercent) / 100, 2));
    }

    public static function total(Money $amount, Money $fee): Money
    {
        return Money::of(PhpRound::format($amount->toFloat() + $fee->toFloat(), 3));
    }

    /** The stored charge for a live session at `$feePercent`. */
    public static function charge(Money $amount, string|float|int $feePercent): Money
    {
        return self::total($amount, self::fee($amount, $feePercent))->chargeRounded();
    }

    /**
     * Sandbox: 2dp fee and an UNROUNDED total (`amount + fee`), which the
     * sandbox then stores at 2dp.
     */
    public static function sandboxTotal(Money $amount, string|float|int $feePercent): Money
    {
        $fee = self::fee2dp($amount, $feePercent);

        return Money::of(PhpRound::shortest($amount->toFloat() + $fee->toFloat()));
    }

    /** Moamalat wire units — dirham = LYD × 1000. Internal to DPay; exposed for reconciliation only. */
    public static function toDirhams(Money $charge): int
    {
        return (int) PhpRound::round($charge->chargeRounded()->toFloat() * 1000, 0);
    }
}
