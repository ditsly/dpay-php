<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Resources;

use DPay\Exceptions\CheckoutNotOpenException;
use DPay\Exceptions\IdempotencyException;
use DPay\Exceptions\InvalidArgumentException;
use DPay\Exceptions\NotFoundException;
use DPay\Exceptions\ValidationException;
use DPay\Models\CheckoutStatus;
use DPay\Models\SessionStatus;
use DPay\Money\Money;
use DPay\Requests\CreateCheckoutSessionRequest;
use DPay\Tests\Support\Clients;
use DPay\Tests\Support\FixtureTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Shapes from API.md §2.17 (amendment A34). */
final class CheckoutSessionsTest extends TestCase
{
    private const ID = 'cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2';

    /** @return array<string, mixed> */
    private static function object(array $overrides = []): array
    {
        return array_merge([
            'id' => self::ID,
            'url' => 'https://dpay.ly/pay/'.self::ID,
            'status' => 'open', 'live' => true,
            'amount' => '125.50', 'currency' => 'LYD',
            'description' => 'Order #10483', 'reference' => '10483',
            'return_url' => 'https://shop.ly/dpay/return?order=10483&key=wc_order_k9',
            'cancel_url' => 'https://shop.ly/cart',
            'metadata' => ['order_id' => 10483, 'platform' => 'woocommerce', 'plugin_version' => '1.0.0'],
            'customer' => ['name' => 'سالم علي', 'email' => null, 'phone' => '0912345678'],
            'allowed_methods' => null, 'locale' => 'ar',
            'expires_at' => '2026-09-21T11:00:00.000Z',
            'paid_at' => null, 'cancelled_at' => null,
            'created_at' => '2026-09-21T10:00:00.000Z', 'updated_at' => '2026-09-21T10:00:00.000Z',
            'payment' => null,
            'attempts' => [],
        ], $overrides);
    }

    private static function request(): CreateCheckoutSessionRequest
    {
        return CreateCheckoutSessionRequest::of('125.5', 'https://shop.ly/dpay/return?order=10483&key=wc_order_k9')
            ->reference('10483')
            ->description('Order #10483')
            ->cancelUrl('https://shop.ly/cart')
            ->metadata(['order_id' => 10483, 'platform' => 'woocommerce', 'plugin_version' => '1.0.0'])
            ->customer(name: 'سالم علي', phone: '0912345678')
            ->locale('ar');
    }

    #[Test]
    public function createsWithAStrictBodyAndAnIdempotencyKey(): void
    {
        $t = (new FixtureTransport())->enqueueJson(201, ['data' => self::object()]);
        $cs = Clients::live($t)->checkoutSessions()->create(self::request(), 'k-10483-1');

        $req = $t->last();
        self::assertSame('POST', $req['method']);
        self::assertSame('https://dpay.ly/api/v2/checkout-sessions', $req['url']);
        self::assertSame('k-10483-1', $req['headers']['Idempotency-Key']);
        self::assertSame([
            'amount' => '125.50', 'currency' => 'LYD',
            'return_url' => 'https://shop.ly/dpay/return?order=10483&key=wc_order_k9',
            'reference' => '10483', 'description' => 'Order #10483', 'cancel_url' => 'https://shop.ly/cart',
            'metadata' => ['order_id' => 10483, 'platform' => 'woocommerce', 'plugin_version' => '1.0.0'],
            'customer' => ['name' => 'سالم علي', 'phone' => '0912345678'],
            'locale' => 'ar',
        ], $t->lastJson());

        self::assertSame(self::ID, $cs->id);
        self::assertSame('https://dpay.ly/pay/'.self::ID, $cs->url);
        self::assertSame(CheckoutStatus::Open, $cs->status);
        self::assertTrue($cs->isOpen());
        self::assertTrue($cs->live);
        self::assertSame('125.5', $cs->amount->value);
        self::assertSame('125.50', $cs->amount->format(2));
        self::assertSame('10483', $cs->reference);
        self::assertSame('https://shop.ly/cart', $cs->cancelUrl);
        self::assertSame(10483, $cs->metadataValue('order_id'));
        self::assertSame('سالم علي', $cs->customer?->name);
        self::assertSame('2026-09-21T11:00:00+00:00', $cs->expiresAt?->format(DATE_ATOM));
        self::assertNull($cs->payment);
        self::assertSame([], $cs->attempts);
        self::assertFalse($cs->idempotentReplay);
        self::assertTrue($cs->matchesOrder('125.50', 'LYD', '10483'));
        self::assertTrue($cs->matchesOrder(Money::of('125.5')));
        self::assertFalse($cs->matchesOrder('125.51'));
        self::assertFalse($cs->matchesOrder('125.50', 'USD'));
        self::assertFalse($cs->matchesOrder('125.50', 'LYD', '10484'));
    }

    #[Test]
    public function replayIsFlaggedThroughMeta(): void
    {
        $t = (new FixtureTransport())->enqueueJson(200, ['data' => self::object(), 'meta' => ['idempotent_replay' => true]]);
        $cs = Clients::live($t)->checkoutSessions()->create(self::request(), 'k-10483-1');
        self::assertTrue($cs->idempotentReplay);
    }

    #[Test]
    public function keyReuseWithADifferentBodyIs409(): void
    {
        $t = (new FixtureTransport())->enqueueJson(409, ['type' => '/api/v2/problems/conflict', 'title' => 'Conflict', 'status' => 409, 'detail' => 'Idempotency-Key was already used with a different body.', 'code' => 'idempotency_key_reused']);
        $this->expectException(IdempotencyException::class);
        Clients::live($t)->checkoutSessions()->create(self::request(), 'k-10483-1');
    }

    #[Test]
    public function aThirdDecimalIsRefusedLocallyAndByTheApi(): void
    {
        try {
            CreateCheckoutSessionRequest::of('125.505', 'https://shop.ly/r');
            self::fail('expected refusal');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('2 decimal', $e->getMessage());
        }
        $t = (new FixtureTransport())->enqueueJson(422, ['type' => '/api/v2/problems/validation-error', 'title' => 'Validation failed', 'status' => 422, 'detail' => 'The request failed validation.', 'errors' => ['amount' => ['amount must have at most 2 decimal places']]]);
        try {
            Clients::live($t)->checkoutSessions()->create(self::request());
            self::fail('expected 422');
        } catch (ValidationException $e) {
            self::assertTrue($e->has('amount'));
        }
    }

    #[Test]
    public function localValidationCoversEveryDocumentedLimit(): void
    {
        $base = fn (): CreateCheckoutSessionRequest => CreateCheckoutSessionRequest::of('10', 'https://shop.ly/r');
        foreach ([
            fn () => CreateCheckoutSessionRequest::of('0.001', 'https://shop.ly/r'),
            fn () => CreateCheckoutSessionRequest::of('10', 'http://shop.ly/r'),
            fn () => $base()->cancelUrl('ftp://x'),
            fn () => $base()->description(str_repeat('x', 256)),
            fn () => $base()->reference(str_repeat('x', 65)),
            fn () => $base()->metadata(array_fill_keys(array_map(static fn (int $i): string => 'k'.$i, range(1, 51)), 1)),
            fn () => $base()->metadata(['k' => str_repeat('x', 501)]),
            fn () => $base()->customer(email: 'not-an-email'),
            fn () => $base()->allowedMethods(['paypal']),
            fn () => $base()->expiresInMinutes(4),
            fn () => $base()->expiresInMinutes(1441),
            fn () => $base()->locale('fr'),
        ] as $i => $case) {
            try {
                $case();
                self::fail('case '.$i.' should have been refused');
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        $ok = $base()->allowedMethods(['edfali', 'moamalat'])->expiresInMinutes(5)->metadata([])->toArray();
        self::assertSame(['edfali', 'moamalat'], $ok['allowed_methods']);
        self::assertSame(5, $ok['expires_in_minutes']);
        self::assertSame('{}', json_encode($ok['metadata']), 'empty metadata is an object, not a list');
        self::assertSame('http://localhost:3000/r', CreateCheckoutSessionRequest::of('10', 'http://localhost:3000/r')->toArray()['return_url'], 'http only on localhost');
    }

    #[Test]
    public function getReadsThePaidStateWithPaymentAndAttempts(): void
    {
        $paid = self::object([
            'status' => 'paid', 'paid_at' => '2026-09-21T10:04:12.000Z',
            'payment' => ['session_id' => 812, 'pay_method' => 'edfali', 'tx_id' => 'txn_abc', 'amount_charged' => '126.76', 'fee_amount' => '1.255', 'fee_percent' => '1.000', 'paid_at' => '2026-09-21T10:04:12.000Z', 'receipt_url' => 'https://dpay.ly/receipt/812/tok'],
            'attempts' => [
                ['session_id' => 812, 'pay_method' => 'edfali', 'status' => 'paid', 'created_at' => '2026-09-21T10:03:00.000Z'],
                ['session_id' => 811, 'pay_method' => 'moamalat', 'status' => 'expired', 'created_at' => '2026-09-21T10:01:00.000Z'],
            ],
        ]);
        $t = (new FixtureTransport())->enqueueJson(200, ['data' => $paid]);
        $cs = Clients::live($t)->checkoutSessions()->get(self::ID);
        self::assertSame('GET', $t->last()['method']);
        self::assertSame('https://dpay.ly/api/v2/checkout-sessions/'.self::ID, $t->last()['url']);
        self::assertTrue($cs->isPaid());
        self::assertNotNull($cs->payment);
        self::assertSame(812, $cs->payment->sessionId);
        self::assertSame('126.76', $cs->payment->amountCharged->value);
        self::assertSame('1.255', $cs->payment->feeAmount?->value);
        self::assertSame('1', $cs->payment->feePercent);
        self::assertSame('txn_abc', $cs->payment->txId);
        self::assertSame('https://dpay.ly/receipt/812/tok', $cs->payment->receiptUrl);
        self::assertCount(2, $cs->attempts);
        self::assertSame(SessionStatus::Expired, $cs->attempts[1]->status);
        self::assertSame('moamalat', $cs->attempts[1]->payMethod);
    }

    #[Test]
    public function unknownOrCrossEnvironmentIdsAre404(): void
    {
        $t = (new FixtureTransport())->enqueueJson(404, ['type' => '/api/v2/problems/not-found', 'title' => 'Not found', 'status' => 404, 'detail' => 'Checkout session not found.']);
        $this->expectException(NotFoundException::class);
        Clients::sandbox($t)->checkoutSessions()->get(self::ID);
    }

    #[Test]
    public function idsAreValidatedBeforeAnyRequest(): void
    {
        $t = new FixtureTransport();
        $this->expectException(InvalidArgumentException::class);
        Clients::live($t)->checkoutSessions()->get('../etc');
    }

    #[Test]
    public function listsWithFiltersAndCursor(): void
    {
        $t = (new FixtureTransport())->enqueueJson(200, ['data' => [self::object(), self::object(['id' => 'cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z3'])], 'meta' => ['limit' => 2, 'has_more' => true, 'next_cursor' => 'aWQ6MTIz']]);
        $page = Clients::live($t)->checkoutSessions()->list(CheckoutStatus::Open, '10483', null, 2);
        self::assertSame('https://dpay.ly/api/v2/checkout-sessions?limit=2&status=open&reference=10483', $t->last()['url']);
        self::assertCount(2, $page->data);
        self::assertTrue($page->hasMore);
        self::assertSame('aWQ6MTIz', $page->nextCursor);
        self::assertSame(2, $page->limit);
    }

    #[Test]
    public function cancelsAndMaps409WhenNotOpen(): void
    {
        $t = (new FixtureTransport())->enqueueJson(200, ['data' => self::object(['status' => 'cancelled', 'cancelled_at' => '2026-09-21T10:30:00.000Z'])]);
        $cs = Clients::live($t)->checkoutSessions()->cancel(self::ID);
        self::assertSame('POST', $t->last()['method']);
        self::assertSame('https://dpay.ly/api/v2/checkout-sessions/'.self::ID.'/cancel', $t->last()['url']);
        self::assertNull($t->last()['body']);
        self::assertSame(CheckoutStatus::Cancelled, $cs->status);
        self::assertSame('2026-09-21T10:30:00+00:00', $cs->cancelledAt?->format(DATE_ATOM));

        $t2 = (new FixtureTransport())->enqueueJson(409, ['type' => '/api/v2/problems/conflict', 'title' => 'Conflict', 'status' => 409, 'detail' => 'Checkout session is not open.', 'code' => 'checkout_not_open', 'checkout_status' => 'paid']);
        try {
            Clients::live($t2)->checkoutSessions()->cancel(self::ID);
            self::fail('expected 409');
        } catch (CheckoutNotOpenException $e) {
            self::assertSame('paid', $e->body['checkout_status']);
        }
    }

    #[Test]
    public function sandboxTokenUsesTheSamePathsAndReadsTestIds(): void
    {
        $t = (new FixtureTransport())->enqueueJson(201, ['data' => self::object(['id' => 'cs_test_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', 'url' => 'https://dpay.ly/sandbox/pay/cs_test_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', 'live' => false])]);
        $cs = Clients::sandbox($t)->checkoutSessions()->create(self::request());
        self::assertSame('https://dpay.ly/api/v2/checkout-sessions', $t->last()['url']);
        self::assertSame('Bearer '.Clients::SANDBOX_TOKEN, $t->last()['headers']['Authorization']);
        self::assertArrayNotHasKey('Idempotency-Key', $t->last()['headers']);
        self::assertTrue($cs->isSandbox());
        self::assertStringStartsWith('cs_test_', $cs->id);
    }
}
