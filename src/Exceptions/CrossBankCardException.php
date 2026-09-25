<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * 422 `This card belongs to a different bank. Enable OnePay on this gateway
 * to accept cross-bank payments.` — a MITF card whose 2-digit prefix is not
 * the gateway's bank, without the merchant's OnePay option.
 */
class CrossBankCardException extends PaymentRequestException
{
    public const MESSAGE = 'This card belongs to a different bank. Enable OnePay on this gateway to accept cross-bank payments.';
}
