<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/** The request never produced an HTTP response (DNS, TLS, timeout, refused). */
class ConnectionException extends \RuntimeException implements DPayException
{
}
