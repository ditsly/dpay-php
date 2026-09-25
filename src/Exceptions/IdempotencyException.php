<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * The Idempotency-Key was reused with a different body — v2's 409
 * `idempotency_key_reused`, the one answer that proves a reuse. The legacy
 * v1 open never says so: its `Payment processing failed. Please try again.`
 * 500 is the redacted answer for ANY open failure and stays a
 * {@see ServerException} (see {@see ServerException::mayBeIdempotencyCollision()}).
 */
class IdempotencyException extends ConflictException
{
}
