<?php

declare(strict_types=1);

namespace DPay\Idempotency;

use DPay\Exceptions\InvalidArgumentException;

/**
 * Deterministic Idempotency-Keys.
 *
 * `Idempotency-Key = base64url(sha256("{platform}:{store_uid}:{order_id}:{attempt}[:{pay_method}]"))`
 *
 * Deterministic, so a double-click or a retried HTTP call REPLAYS the same
 * session instead of opening a second one; a new attempt (after expiry,
 * lockout or a method change) yields a new key. `store_uid` is a random
 * per-install value ({@see KeyFactory::generateStoreUid()}) so two stores of
 * one merchant never collide — keys are unique per environment across ALL
 * merchants; on the legacy v1 open a collision is NOT distinguishable from
 * any other failure (both answer the redacted 500 `Payment processing
 * failed. Please try again.` — a {@see \DPay\Exceptions\ServerException}),
 * which is one more reason for a random per-install uid. Regenerate the
 * store uid when a site is cloned (staging from a production backup).
 *
 * 43 characters: inside the 64-character limit both surfaces enforce
 * (v2 validates the header; v1 stores it in a `varchar(64)` column).
 */
final class KeyFactory
{
    public function __construct(
        private readonly string $platform,
        private readonly string $storeUid,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,31}$/', $platform) !== 1) {
            throw new InvalidArgumentException('platform must be a short lower-case slug (e.g. "woocommerce", "laravel").');
        }
        if (strlen($storeUid) < 16) {
            throw new InvalidArgumentException('store_uid must be at least 16 characters (use KeyFactory::generateStoreUid()).');
        }
    }

    /** 32 hex chars from 16 random bytes — generate once at install time and persist it. */
    public static function generateStoreUid(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** A random UUIDv4 for one-off calls with no order to key on. */
    public static function random(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function forOrder(string|int $orderId, int $attempt = 1, ?string $payMethod = null): string
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('attempt starts at 1.');
        }
        $material = $this->platform.':'.$this->storeUid.':'.$orderId.':'.$attempt;
        if ($payMethod !== null && $payMethod !== '') {
            $material .= ':'.$payMethod;
        }

        return self::base64url(hash('sha256', $material, true));
    }

    /** Hosted checkout: one key per (order, attempt). */
    public function forCheckout(string|int $orderId, int $attempt = 1): string
    {
        return $this->forOrder($orderId, $attempt, 'checkout');
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
