<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * Every exception the SDK throws implements this interface, so a single
 * `catch (DPayException $e)` covers transport failures, API refusals,
 * webhook verification failures and client-side misuse alike.
 */
interface DPayException extends \Throwable
{
}
