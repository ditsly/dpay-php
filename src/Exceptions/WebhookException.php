<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/** Base of every webhook verification refusal. Answer 400/401, never 2xx. */
class WebhookException extends \RuntimeException implements DPayException
{
}
