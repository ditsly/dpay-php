<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/** X-DPAY-Timestamp is missing, not numeric, or outside the tolerance window. */
class StaleTimestampException extends WebhookException
{
}
