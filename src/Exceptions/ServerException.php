<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * HTTP 5xx (503 is {@see ProviderUnavailableException}). The API said
 * nothing specific: do not assume the session was, or was not, opened —
 * read it back (or re-send the SAME Idempotency-Key, which replays it if
 * it was) before opening another one.
 */
class ServerException extends ApiException
{
    /** The compat controller's redacted 500 for every unclassified open failure. */
    public const LEGACY_OPEN_MESSAGE = 'Payment processing failed. Please try again.';

    /**
     * True for the legacy open 500 `Payment processing failed. Please try
     * again.` — the redacted answer for EVERY unclassified open failure, of
     * which a cross-merchant Idempotency-Key collision is only one possible
     * cause. A hint for logs and support, never a reason to mint a fresh key
     * or to look up "the existing session": treat it as an outage first.
     */
    public function mayBeIdempotencyCollision(): bool
    {
        return $this->status === 500 && $this->getMessage() === self::LEGACY_OPEN_MESSAGE;
    }
}
