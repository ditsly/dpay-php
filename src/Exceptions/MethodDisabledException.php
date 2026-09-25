<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/** 400 `This payment method is currently disabled` — disabled by the merchant. */
class MethodDisabledException extends PaymentRequestException
{
    public const MESSAGE = 'This payment method is currently disabled';
}
