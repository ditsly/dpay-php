<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/** Client-side misuse: a value the SDK refuses before any request is sent. */
class InvalidArgumentException extends \InvalidArgumentException implements DPayException
{
}
