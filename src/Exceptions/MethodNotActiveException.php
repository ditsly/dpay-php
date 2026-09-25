<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/** 400 `Payment method is not active` — inactive on the platform. */
class MethodNotActiveException extends PaymentRequestException
{
    public const MESSAGE = 'Payment method is not active';
}
