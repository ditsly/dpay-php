<?php

declare(strict_types=1);

/**
 * Every 5 minutes: re-read every pending order's checkout. This is the
 * settlement path for stores whose webhook URL DPay cannot reach
 * (HTTP-only or private hosts). Keep it under ~30 reads per run.
 */

require __DIR__.'/../bootstrap.php';

use DPay\Exceptions\NotFoundException;

$dpay = dpay_client();

/** @return list<array{order_id: int, checkout_id: string, total: string}> pending orders ≤ 24 h old, from your DB */
function pendingOrders(): array
{
    return [['order_id' => 10483, 'checkout_id' => 'cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', 'total' => '125.50']];
}

$pending = pendingOrders();

foreach (array_slice($pending, 0, 30) as $row) {
    try {
        $checkout = $dpay->checkoutSessions()->get($row['checkout_id']);
    } catch (NotFoundException) {
        continue; // wrong environment or a deleted row — leave the order alone
    }
    if (!$checkout->matchesOrder($row['total'], 'LYD', (string) $row['order_id'])) {
        continue; // flag, never complete
    }
    // transition($row, $checkout) — the same idempotent function return.php and webhook.php call.
    echo "{$row['order_id']}: {$checkout->status->value}\n";
}
