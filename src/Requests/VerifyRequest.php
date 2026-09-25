<?php

declare(strict_types=1);

namespace DPay\Requests;

use DPay\Exceptions\InvalidArgumentException;
use DPay\Gateways\Digits;
use DPay\Gateways\Gateway;
use DPay\Gateways\Rules;

/**
 * The body of `POST /api/payment/sessions/verify`: an OTP for the OTP
 * gateways, or the Moamalat LightBox callback payload (posted by the
 * customer's browser from the hosted page — merchants do not build this).
 */
final class VerifyRequest
{
    /** @param array<string, mixed> $body */
    private function __construct(public readonly int $sessionId, private readonly array $body)
    {
    }

    /**
     * Arabic-Indic digits are folded and separators dropped; the OTP is sent
     * as a JSON string. The SDK refuses locally ONLY what the API refuses:
     * EDFali `digits:4` and Sadad `digits:6` when `$gateway` is given;
     * MobiCash (`string`) and the MITF banks (bare `required`) take any
     * non-empty code — the bank decides, not the SDK.
     */
    public static function otp(int $sessionId, string $otp, Gateway|string|null $gateway = null): self
    {
        $clean = Digits::clean($otp);
        if ($clean === '') {
            throw new InvalidArgumentException('The otp field is required.');
        }
        $slug = $gateway instanceof Gateway ? $gateway->value : $gateway;
        $exact = $slug === null ? null : Rules::otpLength($slug);
        if ($exact !== null && preg_match('/^\d{'.$exact.'}$/', $clean) !== 1) {
            throw new InvalidArgumentException(sprintf('The otp field must be %d digits.', $exact));
        }

        return new self($sessionId, ['otp' => $clean]);
    }

    /** @param array<string, mixed> $moamalatResponse the LightBox `moamalat_response` object, verbatim */
    public static function moamalat(int $sessionId, array $moamalatResponse): self
    {
        if (!isset($moamalatResponse['MerchantReference'])) {
            throw new InvalidArgumentException('moamalat_response.MerchantReference is required.');
        }

        return new self($sessionId, ['moamalat_response' => $moamalatResponse]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['session_id' => $this->sessionId] + $this->body;
    }
}
