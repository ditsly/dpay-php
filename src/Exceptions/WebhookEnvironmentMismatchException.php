<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * A `live:false` event reached a verifier configured for live (or vice
 * versa). Acknowledge with 2xx and ignore it — never mark an order paid.
 */
class WebhookEnvironmentMismatchException extends WebhookException
{
}
