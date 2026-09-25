<?php

declare(strict_types=1);

/**
 * Step 1 — create a hosted checkout session and redirect the customer.
 *
 * Run: DPAY_API_TOKEN=... [DPAY_BASE_URL=https://next.dpay.ly] php examples/hosted-checkout/create.php
 */

require __DIR__.'/../bootstrap.php';

use DPay\Exceptions\DPayException;
use DPay\Idempotency\KeyFactory;
use DPay\Messages\Messages;
use DPay\Requests\CreateCheckoutSessionRequest;

$dpay = dpay_client(); // DPAY_API_TOKEN (+ DPAY_BASE_URL / DPAY_ALLOW_HTTP_LOCALHOST) — see examples/bootstrap.php

// One random store uid per install, persisted once (e.g. in your settings table).
$keys = new KeyFactory('my-shop', getenv('DPAY_STORE_UID') ?: KeyFactory::generateStoreUid());

$orderId = 10483;
$attempt = 1; // bump it when the customer retries after an expiry

try {
    $checkout = $dpay->checkoutSessions()->create(
        CreateCheckoutSessionRequest::of('125.50', 'https://shop.example.ly/dpay/return?order='.$orderId.'&key=wc_order_k9')
            ->reference((string) $orderId)
            ->description('Order #'.$orderId)
            ->cancelUrl('https://shop.example.ly/cart')
            ->metadata(['order_id' => $orderId, 'platform' => 'my-shop', 'plugin_version' => '1.0.0'])
            ->customer(name: 'سالم علي', phone: '0912345678')
            ->locale('ar'),
        $keys->forCheckout($orderId, $attempt),
    );
} catch (DPayException $e) {
    // $e->getMessage() is the API's own wording; Messages::forException() says it in Arabic.
    fwrite(STDERR, Messages::forException($e, 'ar')."\n".$e->getMessage()."\n");
    exit(1);
}

// Persist id, url and expiresAt on the order, mark it "pending payment"…
echo "checkout {$checkout->id} ({$checkout->status->value}) expires {$checkout->expiresAt?->format(DATE_ATOM)}\n";
// …then redirect (303) the customer:
echo "Location: {$checkout->url}\n";
