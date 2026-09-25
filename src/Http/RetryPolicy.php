<?php

declare(strict_types=1);

namespace DPay\Http;

/**
 * Retry ONLY what is safe to repeat: GETs and an `open` (live or, since
 * amendment A34, sandbox) that carries an Idempotency-Key (the same key is
 * reused across the SDK's own retries), on 429, 503 and connection
 * failures. Never an OTP verify — every attempt consumes an OTP — and never
 * an open without a key (a repeat could open a second session).
 *
 * Backoff: `Retry-After` when the server sends one (capped), else
 * exponential with full jitter from `$baseDelay`.
 */
final class RetryPolicy
{
    /** @var list<int> */
    public const RETRYABLE_STATUSES = [429, 503];

    /** @param callable(float): void|null $sleeper seconds → void; injectable for tests */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly float $baseDelay = 0.5,
        public readonly float $maxDelay = 10.0,
        public readonly bool $retryConnectionFailures = true,
        private $sleeper = null,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    public function shouldRetryStatus(int $status, int $attempt): bool
    {
        return $attempt < $this->maxAttempts && in_array($status, self::RETRYABLE_STATUSES, true);
    }

    public function shouldRetryConnectionFailure(int $attempt): bool
    {
        return $this->retryConnectionFailures && $attempt < $this->maxAttempts;
    }

    /** Seconds to wait before attempt `$attempt + 1`. */
    public function delay(int $attempt, ?int $retryAfter): float
    {
        if ($retryAfter !== null) {
            return (float) min($retryAfter, $this->maxDelay);
        }
        $cap = min($this->maxDelay, $this->baseDelay * (2 ** max(0, $attempt - 1)));

        return $cap * (mt_rand(0, 1000) / 1000);
    }

    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }
        usleep((int) ($seconds * 1_000_000));
    }
}
