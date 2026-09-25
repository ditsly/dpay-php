<?php

declare(strict_types=1);

namespace DPay\Gateways;

/**
 * Per-gateway rules of the v1 open/verify contract, mirrored from
 * `platform/packages/contracts/src/gateways.ts` and the compat controller's
 * validation specs. These are the API's rules restated so the SDK can refuse
 * a bad input locally with the same message the API would send.
 */
final class Rules
{
    /** EDFali / Sadad `customer_mobile`: `regex:/^0?9[1-9]\d{7}$/`. */
    public const CUSTOMER_MOBILE_REGEX = '/^0?9[1-9]\d{7}$/';
    /** MobiCash `card_number`: exactly seven digits (A22). */
    public const MOBICASH_CARD_REGEX = '/^\d{7}$/';
    /** MITF `card_number`: 7 same-bank, 9 = 2-digit bank prefix + 7, 10 = Trade & Development Bank. */
    public const MITF_CARD_REGEX = '/^(\d{7}|\d{9}|\d{10})$/';

    /** MITF 2-digit bank card prefixes (GatewaySlug.php:63-70). */
    public const MITF_BANK_PREFIXES = [
        'masrefypay' => '11', // Jumhouria
        'yousrpay' => '33',   // NCB
        'saharapay' => '66',  // Sahara
    ];

    /** @var list<string> the three MITF bank gateways */
    public const MITF_GATEWAYS = ['masrefypay', 'yousrpay', 'saharapay'];

    /**
     * OTP gateways (GatewaySlug.php:45-48). On verify failure these sessions
     * STAY `pending` (retryable); non-OTP gateways go `failed`.
     *
     * @var list<string>
     */
    public const OTP_GATEWAYS = ['edfali', 'mobicash', 'masrefypay', 'yousrpay', 'saharapay', 'sadad'];

    /** @var list<string> */
    public const REDIRECT_GATEWAYS = ['moamalat', 'mpgs'];

    /** @var list<string> every slug the API knows (onepay is a retired legacy row) */
    public const ALL_GATEWAYS = ['edfali', 'sadad', 'mobicash', 'masrefypay', 'yousrpay', 'saharapay', 'moamalat', 'mpgs'];

    /** Live session expiry minutes by gateway; anything else uses the default. */
    public const SESSION_EXPIRY_MINUTES = ['moamalat' => 10, 'mpgs' => 30, 'sadad' => 10];
    public const DEFAULT_SESSION_EXPIRY_MINUTES = 15;
    /** The sandbox uses a flat 5-minute, lazily-evaluated expiry for every gateway. */
    public const SANDBOX_EXPIRY_MINUTES = 5;

    /** Wrong-OTP attempts before EDFali/Sadad lock the session (`failed`). */
    public const OTP_MAX_ATTEMPTS = 5;

    /**
     * OTP digit counts the API validates: EDFali `numeric|digits:4`, Sadad
     * `digits:6`. MobiCash and MITF accept any string — 6 is the usual hint.
     */
    public const OTP_LENGTH = ['edfali' => 4, 'sadad' => 6];
    public const OTP_LENGTH_HINT = 6;

    /** Sadad `category`: `nullable|integer|between:0,36`, default 20 (e-commerce). */
    public const SADAD_CATEGORY_MIN = 0;
    public const SADAD_CATEGORY_MAX = 36;
    public const SADAD_DEFAULT_CATEGORY = 20;
    public const SADAD_BIRTH_YEAR_MIN = 1900;

    /** `description` (MobiCash top-level) max length. */
    public const DESCRIPTION_MAX = 255;

    /**
     * Open fields the API requires per slug, in the order it validates them.
     *
     * @return list<string>
     */
    public static function requiredFields(string $slug): array
    {
        return match ($slug) {
            'edfali' => ['customer_mobile'],
            'sadad' => ['customer_mobile', 'birth_year'],
            'mobicash', 'masrefypay', 'yousrpay', 'saharapay' => ['card_number'],
            default => [],
        };
    }

    public static function isOtpGateway(string $slug): bool
    {
        return in_array($slug, self::OTP_GATEWAYS, true);
    }

    public static function isMitf(string $slug): bool
    {
        return in_array($slug, self::MITF_GATEWAYS, true);
    }

    public static function isKnown(string $slug): bool
    {
        return in_array($slug, self::ALL_GATEWAYS, true);
    }

    public static function expiryMinutes(string $slug): int
    {
        return self::SESSION_EXPIRY_MINUTES[$slug] ?? self::DEFAULT_SESSION_EXPIRY_MINUTES;
    }

    /** Exact OTP length when the API enforces one (EDFali 4, Sadad 6), else null (any non-empty code; hint 6 for the input). */
    public static function otpLength(string $slug): ?int
    {
        return self::OTP_LENGTH[$slug] ?? null;
    }

    /**
     * Verify auth: every non-Moamalat gateway needs the OWNING merchant's
     * bearer (else 403 `Not authorized`); Moamalat verify is posted by the
     * customer's browser from the LightBox page and must NOT be called by
     * the merchant; MPGS cannot be verified through the API at all.
     */
    public static function verifyRequiresBearer(string $slug): bool
    {
        return $slug !== 'moamalat';
    }

    public static function verifiableViaApi(string $slug): bool
    {
        return self::isOtpGateway($slug);
    }

    /**
     * Does the OTP look valid for `$slug` locally — the SAME rule
     * {@see \DPay\Requests\VerifyRequest::otp()} enforces: exactly N digits
     * where the API enforces a length (EDFali 4, Sadad 6), otherwise any
     * non-empty code (MobiCash `string`, MITF bare `required`).
     */
    public static function otpLooksValid(string $slug, string $otp): bool
    {
        $otp = Digits::clean($otp);
        if ($otp === '') {
            return false;
        }
        $exact = self::otpLength($slug);

        return $exact === null || preg_match('/^\d{'.$exact.'}$/', $otp) === 1;
    }
}
