<?php

declare(strict_types=1);

namespace DPay\Resources;

use DPay\Client;
use DPay\Config\Environment;
use DPay\Exceptions\UnsupportedInEnvironmentException;
use DPay\Models\VerifyResult;

/**
 * Test-mode helpers. A sandbox client's `paymentSessions()` / `payMethods()`
 * already talk to `/api/sandbox/*`; this resource adds what only exists in
 * the sandbox: the magic OTPs and the simulated outcomes.
 *
 *   111111 → paid; 000000 → 400 simulated final failure (session `failed`);
 *   anything else → 422 `Invalid OTP…` (session still pending).
 *
 * Sandbox facts the SDK already encodes ({@see Environment}): `sb_tk_` tokens,
 * no sadad/mpgs, flat 5-minute lazy expiry, decimal-string amounts,
 * `sandbox: true` and no `currency`, Moamalat's tokenised simulator link,
 * webhooks with `live: false`, and no Idempotency-Key.
 */
final class Sandbox
{
    public function __construct(private readonly Client $client)
    {
    }

    public function otpSuccess(): string
    {
        return Environment::SANDBOX_OTP_SUCCESS;
    }

    public function otpFailure(): string
    {
        return Environment::SANDBOX_OTP_FAILURE;
    }

    /** Settle a sandbox session with the magic success OTP. */
    public function simulatePaid(int $sessionId): VerifyResult
    {
        $this->assertSandbox();

        return $this->client->paymentSessions()->verify($sessionId, Environment::SANDBOX_OTP_SUCCESS);
    }

    /**
     * Fail a sandbox session for good with the magic failure OTP. The API
     * answers 400 — this throws {@see \DPay\Exceptions\GatewayDeclinedException}
     * exactly as a real final decline would.
     */
    public function simulateDeclined(int $sessionId): never
    {
        $this->assertSandbox();
        $this->client->paymentSessions()->verify($sessionId, Environment::SANDBOX_OTP_FAILURE);
        throw new \LogicException('The sandbox answered 200 to the failure OTP — unreachable by contract.');
    }

    private function assertSandbox(): void
    {
        if (!$this->client->isSandbox()) {
            throw new UnsupportedInEnvironmentException('Sandbox helpers need a sandbox client (Client::sandbox($sbToken)).');
        }
    }
}
