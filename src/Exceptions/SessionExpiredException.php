<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/** 400 `Payment session has expired` — open a new attempt. */
class SessionExpiredException extends PaymentRequestException
{
    public const MESSAGE = 'Payment session has expired';
}
