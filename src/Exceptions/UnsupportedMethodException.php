<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * 400 `Unsupported payment method` (verify on an MPGS session — it completes
 * on its hosted page only) and the sandbox 422
 * `Unsupported payment method: {slug}` (sadad/mpgs are not simulated).
 */
class UnsupportedMethodException extends PaymentRequestException
{
    public const VERIFY_MESSAGE = 'Unsupported payment method';
    public const SANDBOX_PREFIX = 'Unsupported payment method: ';
}
