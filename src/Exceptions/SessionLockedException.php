<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * 422 `Too many OTP attempts. Payment session locked.` — the 5th wrong OTP
 * on EDFali/Sadad. The session is now `failed` (payment.failed fired);
 * open a NEW session for the next attempt.
 */
class SessionLockedException extends PaymentRequestException
{
    public const MESSAGE = 'Too many OTP attempts. Payment session locked.';
}
