<?php

declare(strict_types=1);

namespace DPay\Resources;

use DPay\Client;
use DPay\Exceptions\InvalidArgumentException;
use DPay\Exceptions\UnsupportedMethodException;
use DPay\Gateways\Gateway;
use DPay\Http\ApiRequest;
use DPay\Models\OpenedSession;
use DPay\Models\PaymentSession;
use DPay\Models\VerifyResult;
use DPay\Requests\OpenSessionRequest;
use DPay\Requests\VerifyRequest;

/**
 * The raw per-method surface: open → (OTP) verify → get. For developers
 * building their own payment UI. Store plugins should use hosted
 * {@see CheckoutSessions} instead and never touch OTPs.
 *
 * Throttles: open 20/min per merchant; verify 5/min keyed by the CALLER'S IP
 * (public route) — never auto-retry a verify, surface `retryAfter` instead.
 */
final class PaymentSessions
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * `POST /api/payment/sessions/open`.
     *
     * Pass a deterministic `$idempotencyKey` (see {@see \DPay\Idempotency\KeyFactory};
     * 1..64 characters — the platform column is `varchar(64)`) so a
     * double-click or a retried call replays the same session instead of
     * opening a second one. Since amendment A34 the sandbox honours the key
     * exactly like live, so the SDK sends it there too and retries a keyed
     * sandbox open the same way; {@see OpenedSession::$replayed} tells a
     * replay apart on both surfaces.
     */
    public function open(OpenSessionRequest $request, ?string $idempotencyKey = null): OpenedSession
    {
        $env = $this->client->environment();
        if (!$env->supportsSlug($request->gateway->value)) {
            throw new UnsupportedMethodException(UnsupportedMethodException::SANDBOX_PREFIX.$request->gateway->value, 422);
        }

        return $this->openRaw($request->toArray(), $idempotencyKey);
    }

    /**
     * Open with an untyped body — the request goes to the API exactly as
     * given (for advanced integrations and the contract tests). Prefer
     * {@see open()}.
     *
     * @param array<string, mixed> $body
     */
    public function openRaw(array $body, ?string $idempotencyKey = null): OpenedSession
    {
        $env = $this->client->environment();
        $headers = [];
        $retryable = false;
        if ($idempotencyKey !== null && $env->supportsIdempotency()) {
            self::assertIdempotencyKey($idempotencyKey);
            $headers['Idempotency-Key'] = $idempotencyKey;
            $retryable = true;
        }
        $json = $this->client->request(new ApiRequest('POST', $env->pathPrefix().'/payment/sessions/open', $body, [], $headers, $retryable));

        /** @var array<string, mixed> $json */
        return OpenedSession::fromArray($json, $env->isLive());
    }

    /**
     * `POST /api/payment/sessions/verify` with the customer's OTP. Each call
     * consumes an attempt: after 5 wrong codes EDFali/Sadad lock the session
     * ({@see \DPay\Exceptions\SessionLockedException}). Never retried.
     * Pass the session's `$gateway` (e.g. `$opened->payMethod`) to refuse a
     * wrongly-sized EDFali/Sadad code locally before an attempt is spent.
     */
    public function verify(int $sessionId, string $otp, Gateway|string|null $gateway = null): VerifyResult
    {
        return $this->verifyWith(VerifyRequest::otp($sessionId, $otp, $gateway));
    }

    public function verifyWith(VerifyRequest $request): VerifyResult
    {
        return $this->verifyRaw($request->toArray());
    }

    /** @param array<string, mixed> $body */
    public function verifyRaw(array $body): VerifyResult
    {
        $env = $this->client->environment();
        $json = $this->client->request(new ApiRequest('POST', $env->pathPrefix().'/payment/sessions/verify', $body, [], [], false));

        /** @var array<string, mixed> $json */
        return VerifyResult::fromArray($json);
    }

    /** `GET /api/payment/sessions/{id}` — the authoritative status; safe to poll (60/min pool). */
    public function get(int $sessionId): PaymentSession
    {
        if ($sessionId <= 0) {
            throw new InvalidArgumentException('session_id must be a positive integer.');
        }
        $env = $this->client->environment();
        $json = $this->client->request(new ApiRequest('GET', $env->pathPrefix().'/payment/sessions/'.$sessionId, retryable: true));

        /** @var array<string, mixed> $json */
        return PaymentSession::fromArray($json);
    }

    /** The hosted Mastercard page for a live MPGS session (absent from replays and reads). */
    public function mpgsPaymentLink(int $sessionId): string
    {
        return $this->client->config->baseUrl.'/mpgs-pay/'.$sessionId;
    }

    private static function assertIdempotencyKey(string $key): void
    {
        // Shared with v2: ≤ 64 characters (payment_sessions.idempotency_key is varchar(64);
        // the live open runs the bank leg BEFORE the insert, so a longer key would send the
        // customer an OTP and then answer 500).
        CheckoutSessions::assertIdempotencyKey($key);
    }
}
