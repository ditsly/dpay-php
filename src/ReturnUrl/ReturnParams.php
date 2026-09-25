<?php

declare(strict_types=1);

namespace DPay\ReturnUrl;

use DPay\Exceptions\InvalidArgumentException;

/**
 * What a hosted page appended to your return URL. A HINT only — always
 * re-read the session / checkout with your token before acting.
 *
 * Legacy per-method pages: `session_id`, `status` (paid|failed|cancelled),
 * `payment_id` (Moamalat success only; MPGS never sends it).
 * Hosted checkout (A34): `checkout_session_id`, `status` (paid|open|expired|cancelled).
 */
final class ReturnParams
{
    public function __construct(
        public readonly ?int $sessionId,
        public readonly ?string $status,
        public readonly ?int $paymentId,
        public readonly ?string $checkoutSessionId,
    ) {
    }

    public function isCheckout(): bool
    {
        return $this->checkoutSessionId !== null;
    }

    /**
     * The hosted checkout id, or an {@see InvalidArgumentException} when the
     * query carried none (a customer opening the return URL by hand, or a
     * legacy `session_id` trio landing on the wrong handler) — answer 400.
     */
    public function requireCheckoutId(): string
    {
        return $this->checkoutSessionId ?? throw new InvalidArgumentException(
            'لا يحمل رابط العودة معرّف جلسة دفع (checkout_session_id). / The return URL carries no checkout_session_id.',
        );
    }

    /** The page CLAIMS success — verify it. */
    public function hintsPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function hintsCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isEmpty(): bool
    {
        return $this->sessionId === null && $this->checkoutSessionId === null && $this->status === null;
    }
}
