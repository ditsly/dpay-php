<?php

declare(strict_types=1);

namespace DPay\Money;

use DPay\Exceptions\InvalidArgumentException;

/**
 * A decimal amount as a STRING, never a float. Amounts in LYD major units.
 *
 *   - API responses carry `amount` as a JSON number (`76.26`, `40`) on the
 *     live surface and as a decimal string (`"10.61"`) in the sandbox and on
 *     v2; {@see Money::fromApi()} accepts all three without ever going
 *     through a float when a string is available.
 *   - Merchant amounts come in as strings (`'125.50'`) — pass floats only
 *     when you have nothing else; they are converted at their shortest
 *     round-trip decimal, exactly as the API would have serialised them.
 *
 * Fee math (fee, total, charge) lives in {@see FeeMath}; the one rule every
 * integrator needs is {@see Money::chargeRounded()}: the 2dp figure the
 * gateway collects and every later read reports.
 */
final class Money implements \JsonSerializable, \Stringable
{
    private const PATTERN = '/^-?(?:\d+)(?:\.\d+)?$/';

    /** @param string $value canonical decimal, no trailing zeros, no exponent */
    private function __construct(public readonly string $value)
    {
    }

    /** `Money::of('125.50')`, `Money::of(40)`, `Money::of(76.255)`. */
    public static function of(string|int|float $value): self
    {
        if (is_int($value)) {
            return new self((string) $value);
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Money cannot be built from a non-finite float.');
            }

            return new self(self::canonical(PhpRound::shortest($value)));
        }
        $trimmed = trim($value);
        if (preg_match(self::PATTERN, $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a decimal amount.', $value));
        }

        return new self(self::canonical($trimmed));
    }

    /** Decode an `amount` as the API sends it: number, decimal string or null. */
    public static function fromApi(mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return self::of($value);
        }
        throw new InvalidArgumentException('Unreadable amount in the API response.');
    }

    public static function zero(): self
    {
        return new self('0');
    }

    /** The stored charge rule: `round($total, 2)` — what the payer is charged. */
    public function chargeRounded(): self
    {
        return $this->round(2);
    }

    /** Round half away from zero at `$precision` (legacy `round()` semantics). */
    public function round(int $precision): self
    {
        return new self(self::canonical(PhpRound::format($this->toFloat(), $precision)));
    }

    /** Fixed decimals for display and for comparing at a scale: `'125.50'`. */
    public function format(int $decimals): string
    {
        return PhpRound::format($this->toFloat(), $decimals);
    }

    /** True when both sides agree once rounded to `$decimals` (default: the 2dp charge). */
    public function equalsAt(self $other, int $decimals = 2): bool
    {
        return $this->format($decimals) === $other->format($decimals);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /** -1, 0, 1 — exact decimal comparison, no float involved. */
    public function compareTo(self $other): int
    {
        [$aInt, $aFrac, $aNeg] = self::split($this->value);
        [$bInt, $bFrac, $bNeg] = self::split($other->value);
        if ($aNeg !== $bNeg) {
            return $aNeg ? -1 : 1;
        }
        $scale = max(strlen($aFrac), strlen($bFrac));
        $a = ltrim($aInt.str_pad($aFrac, $scale, '0'), '0');
        $b = ltrim($bInt.str_pad($bFrac, $scale, '0'), '0');
        $cmp = strlen($a) <=> strlen($b);
        if ($cmp === 0) {
            $cmp = strcmp($a, $b) <=> 0;
        }

        return $aNeg ? -$cmp : $cmp;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isPositive(): bool
    {
        return $this->compareTo(self::zero()) > 0;
    }

    /** Number of decimal places in the canonical form (`'125.50'` → 1, `'40'` → 0). */
    public function scale(): int
    {
        $dot = strpos($this->value, '.');

        return $dot === false ? 0 : strlen($this->value) - $dot - 1;
    }

    /** The IEEE double the legacy pipeline would have computed with. */
    public function toFloat(): float
    {
        return (float) $this->value;
    }

    /** What to put on the wire: the canonical decimal string. */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /** "076.2500" → "76.25"; "40.000" → "40"; "-0" → "0". */
    private static function canonical(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        if ($negative) {
            $decimal = substr($decimal, 1);
        }
        $dot = strpos($decimal, '.');
        $int = $dot === false ? $decimal : substr($decimal, 0, $dot);
        $frac = $dot === false ? '' : rtrim(substr($decimal, $dot + 1), '0');
        $int = ltrim($int, '0');
        if ($int === '') {
            $int = '0';
        }
        $out = $frac === '' ? $int : $int.'.'.$frac;
        if ($out === '0') {
            return '0';
        }

        return $negative ? '-'.$out : $out;
    }

    /** @return array{string, string, bool} */
    private static function split(string $canonical): array
    {
        $negative = str_starts_with($canonical, '-');
        if ($negative) {
            $canonical = substr($canonical, 1);
        }
        $dot = strpos($canonical, '.');

        return [
            $dot === false ? $canonical : substr($canonical, 0, $dot),
            $dot === false ? '' : substr($canonical, $dot + 1),
            $negative,
        ];
    }
}
