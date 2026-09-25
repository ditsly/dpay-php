<?php

declare(strict_types=1);

namespace DPay\Gateways;

/** The gateway slugs a merchant can open a session on (`pay_method`). */
enum Gateway: string
{
    case Edfali = 'edfali';
    case Sadad = 'sadad';
    case MobiCash = 'mobicash';
    case MasrefyPay = 'masrefypay';
    case YousrPay = 'yousrpay';
    case SaharaPay = 'saharapay';
    case Moamalat = 'moamalat';
    case Mpgs = 'mpgs';

    public function isMitf(): bool
    {
        return in_array($this, [self::MasrefyPay, self::YousrPay, self::SaharaPay], true);
    }

    /** OTP gateways: the customer confirms with a code sent by the bank. */
    public function isOtp(): bool
    {
        return Rules::isOtpGateway($this->value);
    }

    /** Redirect gateways: the customer pays on a hosted page (`payment_link`). */
    public function isRedirect(): bool
    {
        return !$this->isOtp();
    }
}
