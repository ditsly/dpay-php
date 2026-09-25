<?php

declare(strict_types=1);

namespace DPay\ReturnUrl;

/**
 * The merchant return-URL rules, exactly as the hosted pages apply them
 * (`platform/apps/checkout/lib/return-url.ts`):
 *
 *   1. separator = '&' when the URL already contains '?', else '?';
 *   2. key ORDER is session_id, status, payment_id — values UNENCODED
 *      (numeric ids and a fixed status word);
 *   3. absent values are omitted (never `payment_id=undefined`).
 *
 * The parser reads that trio (and the A34 `checkout_session_id`/`status`
 * pair) back tolerantly from a query string, a full URL, or `$_GET`.
 */
final class ReturnUrl
{
    /** @var list<string> */
    public const LEGACY_STATUSES = ['paid', 'failed', 'cancelled'];
    /** @var list<string> */
    public const CHECKOUT_STATUSES = ['paid', 'open', 'expired', 'cancelled'];

    /** @param string|array<string, mixed> $source a URL, a bare query string, or a query array such as $_GET */
    public static function parse(string|array $source): ReturnParams
    {
        $params = is_array($source) ? $source : self::queryOf($source);
        $sessionId = self::intParam($params, 'session_id');
        $paymentId = self::intParam($params, 'payment_id');
        $checkout = self::stringParam($params, 'checkout_session_id');
        $status = self::stringParam($params, 'status');
        if ($status !== null) {
            $status = strtolower(trim($status));
            $allowed = $checkout !== null ? self::CHECKOUT_STATUSES : self::LEGACY_STATUSES;
            if (!in_array($status, $allowed, true)) {
                $status = null;
            }
        }

        return new ReturnParams($sessionId, $status, $paymentId, $checkout);
    }

    /** Rebuild what the Moamalat/MPGS page would send (for tests and simulators). */
    public static function legacy(string $returnUrl, int|string $sessionId, string $status, int|string|null $paymentId = null): string
    {
        return self::append($returnUrl, [['session_id', $sessionId], ['status', $status], ['payment_id', $paymentId]]);
    }

    /**
     * What `/pay/{id}/return` sends (API.md §2.17): `checkout_session_id`,
     * `status` and, when paid, `payment_id` — in that order, unencoded.
     */
    public static function checkout(string $returnUrl, string $checkoutSessionId, string $status, int|string|null $paymentId = null): string
    {
        return self::append($returnUrl, [['checkout_session_id', $checkoutSessionId], ['status', $status], ['payment_id', $paymentId]]);
    }

    /** @param list<array{0: string, 1: int|string|null}> $params */
    public static function append(string $returnUrl, array $params): string
    {
        $pairs = [];
        foreach ($params as [$key, $value]) {
            if ($value === null || $value === '') {
                continue;
            }
            $pairs[] = $key.'='.$value;
        }
        if ($pairs === []) {
            return $returnUrl;
        }
        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        return $returnUrl.$separator.implode('&', $pairs);
    }

    /** @return array<string, mixed> */
    private static function queryOf(string $source): array
    {
        $query = $source;
        if (str_contains($source, '?')) {
            $query = substr($source, (int) strpos($source, '?') + 1);
        } elseif (str_contains($source, '://')) {
            return [];
        }
        $hash = strpos($query, '#');
        if ($hash !== false) {
            $query = substr($query, 0, $hash);
        }
        $out = [];
        parse_str($query, $out);
        $result = [];
        foreach ($out as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $params */
    private static function intParam(array $params, string $key): ?int
    {
        $v = $params[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^\d{1,18}$/', trim($v)) === 1) {
            return (int) trim($v);
        }

        return null;
    }

    /** @param array<string, mixed> $params */
    private static function stringParam(array $params, string $key): ?string
    {
        $v = $params[$key] ?? null;
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
        if (is_int($v)) {
            return (string) $v;
        }

        return null;
    }
}
