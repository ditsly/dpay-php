<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/** 400 `Payment session is not pending` — read the session and act on its status. */
class SessionNotPendingException extends PaymentRequestException
{
    public const MESSAGE = 'Payment session is not pending';
}
