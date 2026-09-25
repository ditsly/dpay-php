<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/** The operation has no twin in this environment (e.g. `payments()` in the sandbox). */
class UnsupportedInEnvironmentException extends InvalidArgumentException
{
}
