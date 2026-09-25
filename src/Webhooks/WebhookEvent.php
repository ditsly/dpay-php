<?php

declare(strict_types=1);

namespace DPay\Webhooks;

/** The event catalogue. `checkout.*` events belong to hosted checkout sessions (A34). */
enum WebhookEvent: string
{
    case PaymentPaid = 'payment.paid';
    case PaymentFailed = 'payment.failed';
    case PaymentExpired = 'payment.expired';
    case PaymentRefunded = 'payment.refunded';
    case PaymentVoided = 'payment.voided';
    case CheckoutCompleted = 'checkout.completed';
    case CheckoutExpired = 'checkout.expired';
    case CheckoutCancelled = 'checkout.cancelled';
    case WebhookTest = 'webhook.test';

    public function isPayment(): bool
    {
        return str_starts_with($this->value, 'payment.');
    }

    public function isCheckout(): bool
    {
        return str_starts_with($this->value, 'checkout.');
    }
}
