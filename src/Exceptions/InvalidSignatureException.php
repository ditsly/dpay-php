<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/** X-DPAY-Signature is missing or does not match any configured secret. */
class InvalidSignatureException extends WebhookException
{
}
