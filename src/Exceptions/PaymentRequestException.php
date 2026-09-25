<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * A deliberate refusal authored by the payment controllers: a 400/422 whose
 * body is `{"message": "..."}` with no field map. Subclasses are keyed on the
 * exact legacy strings (`payment-sessions.controller.ts`).
 */
class PaymentRequestException extends ApiException
{
}
