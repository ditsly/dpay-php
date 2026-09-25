<?php

declare(strict_types=1);

namespace DPay\Webhooks;

/**
 * `X-DPAY-Signature = hex(HMAC-SHA256(key = secret INCLUDING "whsec_",
 * msg = "{X-DPAY-Timestamp}.{raw body bytes}"))`.
 *
 * Byte-for-byte the published sample (`platform/apps/website/lib/samples.ts`,
 * VERIFY_SIGNATURE_PHP):
 *
 *     $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
 *     hash_equals($expected, $signature)
 */
final class Signature
{
    public const HEADER = 'X-DPAY-Signature';
    public const TIMESTAMP_HEADER = 'X-DPAY-Timestamp';
    public const EVENT_HEADER = 'X-DPAY-Event';
    public const SECRET_REGEX = '/^whsec_[0-9a-f]{64}$/';

    public static function compute(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /** Constant-time comparison; the server sends lowercase hex, so we lowercase the header only. */
    public static function matches(string $expected, string $given): bool
    {
        return hash_equals($expected, strtolower(trim($given)));
    }

    public static function isWellFormedSecret(string $secret): bool
    {
        return preg_match(self::SECRET_REGEX, $secret) === 1;
    }
}
