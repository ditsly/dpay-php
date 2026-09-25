<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * 422 with a provider message and no field map that is none of the
 * classified refusals: e.g. `The customer is not found in the bank`,
 * `The required amount is out of your limits`, `Payment verification
 * failed: reference mismatch`. The message is the provider's own wording.
 */
class GatewayRejectedException extends PaymentRequestException
{
}
