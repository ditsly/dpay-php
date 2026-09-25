<?php

declare(strict_types=1);

namespace DPay\Models;

use DPay\Exceptions\UnexpectedResponseException;
use DPay\Money\Money;

/** Tolerant readers for response arrays: unknown keys are ignored, documented keys are typed. */
final class Read
{
    /** @param array<string, mixed> $a */
    public static function int(array $a, string $key): int
    {
        $v = $a[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^-?\d+$/', $v) === 1) {
            return (int) $v;
        }
        if (is_float($v) && floor($v) === $v) {
            return (int) $v;
        }
        throw new UnexpectedResponseException(sprintf('Expected integer "%s" in the API response.', $key), 200, $a);
    }

    /** @param array<string, mixed> $a */
    public static function intOrNull(array $a, string $key): ?int
    {
        return ($a[$key] ?? null) === null ? null : self::int($a, $key);
    }

    /** @param array<string, mixed> $a */
    public static function string(array $a, string $key): string
    {
        $v = $a[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        throw new UnexpectedResponseException(sprintf('Expected string "%s" in the API response.', $key), 200, $a);
    }

    /** @param array<string, mixed> $a */
    public static function stringOrNull(array $a, string $key): ?string
    {
        $v = $a[$key] ?? null;

        return $v === null ? null : self::string($a, $key);
    }

    /** @param array<string, mixed> $a */
    public static function bool(array $a, string $key, bool $default = false): bool
    {
        $v = $a[$key] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if ($v === null) {
            return $default;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes'], true);
        }

        return $default;
    }

    /** @param array<string, mixed> $a */
    public static function money(array $a, string $key): Money
    {
        $m = Money::fromApi($a[$key] ?? null);
        if ($m === null) {
            throw new UnexpectedResponseException(sprintf('Expected amount "%s" in the API response.', $key), 200, $a);
        }

        return $m;
    }

    /** @param array<string, mixed> $a */
    public static function moneyOrNull(array $a, string $key): ?Money
    {
        return Money::fromApi($a[$key] ?? null);
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>|null
     */
    public static function arrayOrNull(array $a, string $key): ?array
    {
        $v = $a[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_array($v)) {
            throw new UnexpectedResponseException(sprintf('Expected object "%s" in the API response.', $key), 200, $a);
        }

        /** @var array<string, mixed> $v */
        return $v;
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    public static function array(array $a, string $key): array
    {
        return self::arrayOrNull($a, $key) ?? throw new UnexpectedResponseException(sprintf('Missing object "%s" in the API response.', $key), 200, $a);
    }
}
