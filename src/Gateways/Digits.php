<?php

declare(strict_types=1);

namespace DPay\Gateways;

/**
 * Fold Arabic-Indic (٠١٢٣٤٥٦٧٨٩) and Eastern Arabic-Indic / Persian
 * (۰۱۲۳۴۵۶۷۸۹) digits to ASCII, as the API does on input, so a mobile number
 * or OTP typed on an Arabic keyboard validates the same on both sides.
 */
final class Digits
{
    private const ARABIC_INDIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    private const PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    private const ASCII = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function fold(string $value): string
    {
        return str_replace(self::PERSIAN, self::ASCII, str_replace(self::ARABIC_INDIC, self::ASCII, $value));
    }

    /** Fold, then drop spaces, dashes and dots (what people type between digit groups). */
    public static function clean(string $value): string
    {
        return (string) preg_replace('/[\s\-.]/u', '', self::fold(trim($value)));
    }
}
