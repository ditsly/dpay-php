<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * 422 — the bank rejected the OTP and the session is STILL pending: the
 * customer may try again (`EDFali PIN is not correct`, `OTP verification
 * failed.`, the sandbox `Invalid OTP. …` hint).
 */
class OtpRejectedException extends PaymentRequestException
{
    public const EDFALI_PIN = 'EDFali PIN is not correct';
    public const GENERIC = 'OTP verification failed.';
    public const SANDBOX = 'Invalid OTP. In sandbox mode, use 111111 for success or 000000 to simulate final failure.';
}
