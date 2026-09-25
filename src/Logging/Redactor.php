<?php

declare(strict_types=1);

namespace DPay\Logging;

/**
 * What may reach a log line. Tokens, secrets, OTPs, mobile numbers, card
 * identifiers and birth years are replaced before anything is logged; the
 * Authorization header is never logged at all.
 */
final class Redactor
{
    /** @var list<string> keys whose values are secrets or customer identifiers */
    public const SENSITIVE_KEYS = [
        'otp', 'customer_mobile', 'card_number', 'birth_year', 'moamalat_response',
        'authorization', 'x-dpay-signature', 'token', 'secret', 'password', 'pin',
        'phone', 'email', 'customer', 'payer_account', 'SecureHash',
    ];

    public const MASK = '[redacted]';

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function array(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $out[$key] = self::MASK;
                continue;
            }
            $out[$key] = is_array($value) ? self::array($value) : (is_string($value) ? self::string($value) : $value);
        }

        return $out;
    }

    /** Scrub token/secret shapes out of free text (exception messages, URLs). */
    public static function string(string $text): string
    {
        $patterns = [
            '/sb_tk_[A-Za-z0-9_\-]+/' => 'sb_tk_'.self::MASK,
            '/whsec_[0-9a-fA-F]+/' => 'whsec_'.self::MASK,
            '/\b\d+\|[A-Za-z0-9]{40}[0-9a-f]{8}\b/' => self::MASK,
            '/Bearer\s+\S+/i' => 'Bearer '.self::MASK,
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    public static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($lower === strtolower($sensitive)) {
                return true;
            }
        }

        return false;
    }
}
