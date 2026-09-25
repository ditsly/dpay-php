<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/** 409 `checkout_not_open` — cancelling a paid/expired/cancelled checkout session. */
class CheckoutNotOpenException extends ConflictException
{
}
