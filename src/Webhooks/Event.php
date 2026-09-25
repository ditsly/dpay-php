<?php

declare(strict_types=1);

namespace DPay\Webhooks;

use DPay\Exceptions\UnexpectedResponseException;
use DPay\Models\Dates;
use DPay\Models\Read;
use DPay\Money\Money;

/**
 * A verified webhook payload.
 *
 * payment.* (one terminal event per session):
 *   event, live, session_id, status, amount (fee-inclusive 2dp charge),
 *   pay_method, tx_id, system_reference, network_reference, paid_through,
 *   payer_account (pre-masked), data (yours + server keys), created_at,
 *   paid_at (the transition moment — named paid_at for EVERY event).
 *
 * checkout.* (A34): event, live, checkout_session_id, reference, status,
 *   amount, currency, description, metadata, customer, payment|null,
 *   created_at, occurred_at — plus the unchanged payment.* per attempt with
 *   data.checkout_session_id.
 *
 * webhook.test: no session_id/live; must be acknowledged 2xx, never treated
 * as a payment.
 */
final class Event
{
    /** @param array<string, mixed> $payload */
    private function __construct(
        public readonly string $name,
        public readonly array $payload,
        public readonly string $rawBody,
        public readonly ?string $timestamp,
    ) {
    }

    public static function fromRawBody(string $rawBody, ?string $timestamp = null): self
    {
        try {
            $decoded = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UnexpectedResponseException('Webhook body is not JSON.', 200, null, $rawBody);
        }
        if (!is_array($decoded)) {
            throw new UnexpectedResponseException('Webhook body is not a JSON object.', 200, null, $rawBody);
        }
        $name = $decoded['event'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new UnexpectedResponseException('Webhook body has no "event".', 200, null, $rawBody);
        }
        $payload = [];
        foreach ($decoded as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return new self($name, $payload, $rawBody, $timestamp);
    }

    /** The typed event, or null for a name this SDK version does not know (still acknowledge it). */
    public function type(): ?WebhookEvent
    {
        return WebhookEvent::tryFrom($this->name);
    }

    public function is(WebhookEvent $event): bool
    {
        return $this->name === $event->value;
    }

    public function isTest(): bool
    {
        return $this->name === WebhookEvent::WebhookTest->value;
    }

    public function isPayment(): bool
    {
        return str_starts_with($this->name, 'payment.');
    }

    public function isCheckout(): bool
    {
        return str_starts_with($this->name, 'checkout.');
    }

    /** `live` flag; null on webhook.test (it carries none). */
    public function live(): ?bool
    {
        $v = $this->payload['live'] ?? null;

        return is_bool($v) ? $v : null;
    }

    public function sessionId(): ?int
    {
        return Read::intOrNull($this->payload, 'session_id');
    }

    /** `checkout_session_id` on checkout.* events, or `data.checkout_session_id` on a checkout attempt's payment.* event. */
    public function checkoutSessionId(): ?string
    {
        $top = Read::stringOrNull($this->payload, 'checkout_session_id');
        if ($top !== null) {
            return $top;
        }
        $data = $this->data();

        return isset($data['checkout_session_id']) && is_scalar($data['checkout_session_id']) ? (string) $data['checkout_session_id'] : null;
    }

    public function status(): ?string
    {
        return Read::stringOrNull($this->payload, 'status');
    }

    /** Fee-inclusive charge in LYD major units; int or float on the wire, a decimal string here. */
    public function amount(): ?Money
    {
        return Money::fromApi($this->payload['amount'] ?? null);
    }

    public function currency(): ?string
    {
        return Read::stringOrNull($this->payload, 'currency');
    }

    public function payMethod(): ?string
    {
        return Read::stringOrNull($this->payload, 'pay_method');
    }

    public function txId(): ?string
    {
        return Read::stringOrNull($this->payload, 'tx_id');
    }

    /**
     * The store's order number: `reference` on checkout.*, or `data.reference`
     * on a hosted-checkout attempt's payment.* event (a raw v1 session has none).
     */
    public function reference(): ?string
    {
        $top = Read::stringOrNull($this->payload, 'reference');
        if ($top !== null) {
            return $top;
        }
        $v = $this->data()['reference'] ?? null;

        return is_scalar($v) ? (string) $v : null;
    }

    /** @return array<string, mixed> `data` (payment.*) — never only your keys: the server merges its own */
    public function data(): array
    {
        return Read::arrayOrNull($this->payload, 'data') ?? [];
    }

    /** @return array<string, mixed> `metadata` (checkout.*) or `data.metadata` (a hosted-checkout attempt's payment.*) */
    public function metadata(): array
    {
        $top = Read::arrayOrNull($this->payload, 'metadata') ?? [];
        if ($top !== []) {
            return $top;
        }
        $nested = [];
        $data = $this->data();
        foreach (isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : [] as $key => $value) {
            $nested[(string) $key] = $value;
        }

        return $nested;
    }

    /** @return array<string, mixed>|null the winning attempt on checkout.completed */
    public function payment(): ?array
    {
        return Read::arrayOrNull($this->payload, 'payment');
    }

    public function createdAt(): ?\DateTimeImmutable
    {
        return Dates::parse($this->payload['created_at'] ?? null);
    }

    /** The transition moment (`paid_at` on payment.*, `occurred_at` on checkout.*). */
    public function occurredAt(): ?\DateTimeImmutable
    {
        return Dates::parse($this->payload['occurred_at'] ?? $this->payload['paid_at'] ?? null);
    }

    /**
     * The key to dedupe on — `{live}:{session_id|checkout_session_id}:{event}`.
     * The same event can legitimately arrive more than once (retries after a
     * late 2xx, "Send again", the Moamalat void quirk): fulfil once, 2xx always.
     */
    public function dedupeKey(): string
    {
        $live = $this->live();
        $subject = $this->isCheckout() ? ($this->checkoutSessionId() ?? '') : (string) ($this->sessionId() ?? '');
        if ($this->isTest()) {
            $subject = 'test:'.$this->scalar('webhook_id').':'.$this->scalar('timestamp');
        }

        return ($live === null ? 'any' : ($live ? 'live' : 'sandbox')).':'.$subject.':'.$this->name;
    }

    public function get(string $key): mixed
    {
        return $this->payload[$key] ?? null;
    }

    private function scalar(string $key): string
    {
        $v = $this->payload[$key] ?? null;

        return is_scalar($v) ? (string) $v : '';
    }
}
