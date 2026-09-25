<?php

declare(strict_types=1);

namespace DPay\Money;

/**
 * The legacy money rounding, byte-for-byte with the platform's ONE
 * implementation (`platform/packages/core/src/php-round.ts`):
 *
 *   1. take the double at its SHORTEST round-trip decimal representation;
 *   2. round THAT decimal half-away-from-zero at the requested precision.
 *
 * This is what PHP >= 8.4 `round()` does. PHP 8.1–8.3 `round()` uses a
 * different "pre-rounding" algorithm that agrees on ordinary money but not
 * on every edge, so the SDK never calls the native `round()` for money:
 * this port gives the same answer on every PHP the SDK supports, and the
 * golden vectors generated from the legacy formulas prove it.
 */
final class PhpRound
{
    /** `round($value, $precision)` with the legacy semantics, as a float. */
    public static function round(float $value, int $precision = 0): float
    {
        if (!is_finite($value) || $value === 0.0) {
            return $value;
        }
        [$digits, $point] = self::roundAbs(abs($value), $precision);
        $rounded = self::rebuild($digits, $point);

        return $value < 0 ? -$rounded : $rounded;
    }

    /**
     * `number_format($value, $decimals, '.', '')` with the legacy rounding:
     * a fixed-decimal string, never a float. "-0.00" is never produced.
     */
    public static function format(float $value, int $decimals): string
    {
        if (!is_finite($value)) {
            return (string) $value;
        }
        $negative = $value < 0;
        [$digits, $point] = self::roundAbs(abs($value), $decimals);
        $fixed = self::fixedString($digits, $point, $decimals);

        return $negative && preg_match('/[1-9]/', $fixed) === 1 ? '-'.$fixed : $fixed;
    }

    /**
     * The shortest decimal string that round-trips to exactly `$value`
     * (what JavaScript's `String(number)` and PHP's `var_export()` with
     * `serialize_precision=-1` emit) — computed without depending on any
     * ini setting: the first precision that survives a float round-trip.
     */
    public static function shortest(float $value): string
    {
        if (!is_finite($value)) {
            return (string) $value;
        }
        if ($value === 0.0) {
            return '0';
        }
        for ($p = 0; $p <= 17; $p++) {
            $candidate = sprintf('%.'.$p.'e', $value);
            if ((float) $candidate === $value) {
                return self::plain($candidate);
            }
        }

        return self::plain(sprintf('%.17e', $value));
    }

    /**
     * @return array{string, int} digits (with a leading guard zero) and the decimal-point index
     */
    private static function roundAbs(float $abs, int $precision): array
    {
        [$digits, $point] = self::decompose($abs);
        // A leading guard digit gives a carry out of the top digit somewhere
        // to land, so the increment loop can never run off the front.
        $digits = '0'.$digits;
        $point++;

        $cut = $point + $precision;
        $length = strlen($digits);
        if ($cut >= $length) {
            return [$digits, $point]; // already exact at this precision
        }
        if ($cut <= 0) {
            return ['0', 1]; // everything is dropped
        }

        $kept = substr($digits, 0, $cut);
        if (ord($digits[$cut]) < 0x35 /* '5' */) {
            return [$kept, $point];
        }

        $carried = $kept;
        for ($i = strlen($carried) - 1; $i >= 0; $i--) {
            if ($carried[$i] === '9') {
                $carried[$i] = '0';
                continue;
            }
            $carried[$i] = chr(ord($carried[$i]) + 1);
            break;
        }

        return [$carried, $point];
    }

    /**
     * Shortest decimal of a positive finite float as bare digits plus the
     * index of the decimal point (<= 0: leading zeros implied; > length:
     * trailing zeros implied).
     *
     * @return array{string, int}
     */
    private static function decompose(float $abs): array
    {
        // sprintf('%.{p}e') is correctly rounded (zend_dtoa), so the first
        // precision that round-trips is the shortest representation.
        $sci = null;
        for ($p = 0; $p <= 17; $p++) {
            $candidate = sprintf('%.'.$p.'e', $abs);
            if ((float) $candidate === $abs) {
                $sci = $candidate;
                break;
            }
        }
        $sci ??= sprintf('%.17e', $abs);

        [$mantissa, $exponent] = explode('e', $sci, 2);
        $exponent = (int) $exponent;
        $dot = strpos($mantissa, '.');
        $intPart = $dot === false ? $mantissa : substr($mantissa, 0, $dot);
        $fracPart = $dot === false ? '' : substr($mantissa, $dot + 1);
        // Strip the trailing zeros sprintf may keep ("1.50e+0" never happens
        // at the shortest precision, but be exact about it anyway).
        $fracPart = rtrim($fracPart, '0');

        return [$intPart.$fracPart, strlen($intPart) + $exponent];
    }

    /** Rebuild a float from digits + point through one correctly-rounded parse. */
    private static function rebuild(string $digits, int $point): float
    {
        return (float) ($digits.'e'.($point - strlen($digits)));
    }

    /** Render digits + point as plain fixed-point with exactly `$decimals` places. */
    private static function fixedString(string $digits, int $point, int $decimals): string
    {
        $d = $digits;
        $p = $point;
        if ($p <= 0) {
            $d = str_repeat('0', 1 - $p).$d;
            $p = 1;
        }
        if ($p > strlen($d)) {
            $d = str_pad($d, $p, '0');
        }
        $intPart = substr($d, 0, $p);
        $intPart = ltrim($intPart, '0');
        if ($intPart === '') {
            $intPart = '0';
        }
        $fracPart = substr($d, $p);
        $fracPart = substr($fracPart, 0, $decimals);
        $fracPart = str_pad($fracPart, $decimals, '0');

        return $decimals > 0 ? $intPart.'.'.$fracPart : $intPart;
    }

    /** "7.6255e+1" → "76.255"; "5e-3" → "0.005". */
    private static function plain(string $sci): string
    {
        $negative = str_starts_with($sci, '-');
        if ($negative) {
            $sci = substr($sci, 1);
        }
        [$mantissa, $exponent] = explode('e', $sci, 2);
        $exponent = (int) $exponent;
        $dot = strpos($mantissa, '.');
        $intPart = $dot === false ? $mantissa : substr($mantissa, 0, $dot);
        $fracPart = $dot === false ? '' : substr($mantissa, $dot + 1);
        $digits = $intPart.$fracPart;
        $point = strlen($intPart) + $exponent;
        if ($point <= 0) {
            $digits = str_repeat('0', 1 - $point).$digits;
            $point = 1;
        }
        if ($point > strlen($digits)) {
            $digits = str_pad($digits, $point, '0');
        }
        $int = ltrim(substr($digits, 0, $point), '0');
        $frac = rtrim(substr($digits, $point), '0');
        $out = ($int === '' ? '0' : $int).($frac === '' ? '' : '.'.$frac);

        return $negative ? '-'.$out : $out;
    }
}
