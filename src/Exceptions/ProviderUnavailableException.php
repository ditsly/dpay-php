<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * HTTP 503 — the bank/provider behind the gateway is unreachable or the
 * circuit breaker is open. The session (if any) is still pending; retry in
 * a few minutes.
 */
class ProviderUnavailableException extends ApiException
{
    public const LEGACY_MESSAGE = 'The payment provider is temporarily unavailable. Please try again in a few minutes.';
}
