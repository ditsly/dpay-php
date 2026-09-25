<?php

declare(strict_types=1);

/**
 * Step 3 — the customer is back. The query string is a HINT; the API is the truth.
 *
 * GET https://shop.example.ly/dpay/return?order=10483&key=…&checkout_session_id=cs_…&status=paid&payment_id=5
 *
 * The order is looked up BY THE CHECKOUT ID the page names, from your own
 * store (where create.php persisted it) — never built from the query. An id
 * no order carries is a 404; a malformed id never reaches the API.
 */

require __DIR__.'/../bootstrap.php';

use DPay\Exceptions\InvalidArgumentException;
use DPay\Models\CheckoutStatus;
use DPay\ReturnUrl\ReturnUrl;

$dpay = dpay_client();

/**
 * Your order repository. Stand-in for a `SELECT … WHERE dpay_checkout_id = ?`:
 * the id was stored on the order when create.php created the checkout.
 *
 * @return array{id: int, total: string, currency: string, checkout_id: string, status: string}|null
 */
function findOrderByCheckoutId(string $checkoutId): ?array
{
    $orders = [
        ['id' => 10483, 'total' => '125.50', 'currency' => 'LYD', 'checkout_id' => getenv('EXAMPLE_CHECKOUT_ID') ?: 'cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', 'status' => 'pending_payment'],
    ];
    foreach ($orders as $order) {
        if ($order['checkout_id'] === $checkoutId) {
            return $order;
        }
    }

    return null;
}

$hint = ReturnUrl::parse($_GET);            // checkoutSessionId, status, paymentId — a hint only

if (!$hint->isCheckout()) {                 // opened by hand, or a legacy session_id trio on the wrong handler
    http_response_code(400);
    exit('missing checkout_session_id');
}

$order = findOrderByCheckoutId($hint->requireCheckoutId());
if ($order === null) {
    http_response_code(404);                // an id no order of ours carries: not ours to complete
    exit('unknown checkout');
}

try {
    $checkout = $dpay->checkoutSessions()->get($order['checkout_id']);   // the STORED id, not the query's
} catch (InvalidArgumentException $e) {
    http_response_code(400);                // cannot happen for an id we stored — defensive
    exit('malformed checkout id');
}

// The guard every transition runs: amount at 2dp, currency and reference must match THIS order.
if (!$checkout->matchesOrder($order['total'], $order['currency'], (string) $order['id'])) {
    // Do NOT complete the order — note it for review.
    exit('amount/currency/reference mismatch — order left pending, flagged');
}

// One idempotent transition: whichever of return / webhook / reconcile lands first wins.
switch ($checkout->status) {
    case CheckoutStatus::Paid:
        if ($order['status'] !== 'paid') {
            $paid = $checkout->payment;
            // $orders->markPaid($order['id'], $paid?->txId, $paid?->amountCharged);
            echo "order {$order['id']} paid {$paid?->amountCharged?->value} via {$paid?->payMethod}, tx {$paid?->txId}, receipt {$paid?->receiptUrl}\n";
        }
        break;
    case CheckoutStatus::Open:
        echo "payment not completed — try again: {$checkout->url}\n";
        break;
    case CheckoutStatus::Expired:
        echo "expired — offer a new checkout (attempt + 1)\n";
        break;
    case CheckoutStatus::Cancelled:
        echo "cancelled by the store\n";
        break;
}
