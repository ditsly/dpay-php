<?php

declare(strict_types=1);

/**
 * The raw per-method surface (developers building their own UI):
 * pay-methods → open (EDFali) → verify (OTP) → get. Run in the sandbox:
 *
 *   DPAY_API_TOKEN=sb_tk_… php examples/raw-otp/pay.php
 *
 * Verify is throttled 5/min per caller IP on the public route — never
 * auto-retry it; show the customer `retryAfter` instead.
 */

require __DIR__.'/../bootstrap.php';

use DPay\Exceptions\OtpRejectedException;
use DPay\Exceptions\RateLimitException;
use DPay\Exceptions\SessionLockedException;
use DPay\Idempotency\KeyFactory;
use DPay\Messages\Messages;
use DPay\Requests\OpenSessionRequest;

$dpay = dpay_client();

// 1. Which tiles to show, and the limits to check BEFORE opening.
foreach ($dpay->payMethods()->usable() as $method) {
    printf("%-12s fee %s%%  %d..%d LYD  otp %s\n", $method->slug, $method->feePercent, $method->minDeposit, $method->maxDeposit, $method->otpLength ?? '-');
}

// 2. Open: the customer entered a mobile number; the bank sends an OTP.
$keys = new KeyFactory('my-shop', getenv('DPAY_STORE_UID') ?: KeyFactory::generateStoreUid());
$opened = $dpay->paymentSessions()->open(
    OpenSessionRequest::edfali('75.50', '0912345678')->withData(['order_id' => 10483])->withDescription('Order #10483'),
    $keys->forOrder(10483, 1, 'edfali'),
);
printf(
    "session %d: amount %s + fee %s = total %s → charged %s, expires %s\n",
    $opened->sessionId,
    $opened->amount,
    $opened->feeAmount,
    $opened->total,
    $opened->charge(),
    $opened->expiredAt->format(DATE_ATOM),
);

// 3. Verify the OTP the customer typed (sandbox: 111111 pays, 000000 declines).
$otp = $dpay->isSandbox() ? $dpay->sandboxTools()->otpSuccess() : '1234';
try {
    $result = $dpay->paymentSessions()->verify($opened->sessionId, $otp);
    printf("paid: payment %d, tx %s, receipt %s\n", $result->paymentId, $result->txId, $result->receiptUrl ?? '-');
} catch (OtpRejectedException $e) {
    echo Messages::forException($e, 'ar')."\n";     // wrong code — the session is still pending, ask again
} catch (SessionLockedException $e) {
    echo Messages::forException($e, 'ar')."\n";     // 5 wrong codes — open a NEW session (attempt + 1)
} catch (RateLimitException $e) {
    echo Messages::forException($e, 'ar')."\n";     // wait $e->retryAfter seconds
}

// 4. The authoritative status (also what a reconciler polls).
$session = $dpay->paymentSessions()->get($opened->sessionId);
printf("status %s, charged %s\n", $session->status->value, $session->amount);
