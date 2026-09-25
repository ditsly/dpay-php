<?php

declare(strict_types=1);

/**
 * Step 4 — the signed webhook. Verify over the RAW bytes, dedupe, answer 2xx fast.
 *
 * Register https://shop.example.ly/dpay/webhook in the dashboard (Build → Webhooks),
 * subscribe to checkout.* and payment.*, and keep the whsec_… secret in DPAY_WEBHOOK_SECRET.
 */

require __DIR__.'/../../vendor/autoload.php';

use DPay\Config\Environment;
use DPay\Exceptions\WebhookEnvironmentMismatchException;
use DPay\Exceptions\WebhookException;
use DPay\Webhooks\Verifier;
use DPay\Webhooks\WebhookEvent;

$verifier = new Verifier(
    (string) getenv('DPAY_WEBHOOK_SECRET'),   // or [$newSecret, $oldSecret] during a rotation
    Environment::fromToken((string) getenv('DPAY_API_TOKEN')),
);

try {
    $event = $verifier->verifyGlobals();      // php://input + $_SERVER['HTTP_X_DPAY_*']
} catch (WebhookEnvironmentMismatchException) {
    http_response_code(200);                  // a sandbox event on a live store (or vice versa): acknowledge, ignore
    exit('{"ok":true,"ignored":"environment"}');
} catch (WebhookException $e) {
    http_response_code(401);                  // bad/missing signature, stale timestamp: never 2xx
    exit('{"ok":false,"error":"'.$e->getMessage().'"}');
}

if ($event->isTest()) {
    http_response_code(200);
    exit('{"ok":true,"test":true}');
}

// Dedupe on (live, id, event): the same event can legitimately arrive twice.
function seenBefore(string $dedupeKey): bool
{
    // e.g. INSERT IGNORE INTO dpay_webhook_events (dedupe_key) VALUES (?) — true when the row already existed
    return false;
}

if (seenBefore($event->dedupeKey())) {
    http_response_code(200);
    exit('{"ok":true,"duplicate":true}');
}

if ($event->is(WebhookEvent::CheckoutCompleted)) {
    // Same transition as return.php: look the order up by $event->checkoutSessionId() (never by metadata alone),
    // compare amount/currency/reference, then complete it with $event->payment()['tx_id'].
    $payment = $event->payment();
    echo "checkout {$event->checkoutSessionId()} paid: ".json_encode($payment)."\n";
} elseif ($event->is(WebhookEvent::CheckoutExpired)) {
    // Free the order for another attempt; the store's pending-order policy decides.
} elseif ($event->isPayment()) {
    // payment.* per attempt — informational for hosted checkout (data.checkout_session_id names the checkout).
}

http_response_code(200);
echo '{"ok":true,"order":'.json_encode($event->reference()).'}';
