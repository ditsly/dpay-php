<?php

declare(strict_types=1);

namespace DPay\Tests\Contract;

use DPay\Client;
use DPay\Exceptions\AmountOutOfRangeException;
use DPay\Exceptions\ApiException;
use DPay\Exceptions\AuthenticationException;
use DPay\Exceptions\CheckoutNotOpenException;
use DPay\Exceptions\CrossBankCardException;
use DPay\Exceptions\GatewayDeclinedException;
use DPay\Exceptions\GatewayRejectedException;
use DPay\Exceptions\IdempotencyException;
use DPay\Exceptions\MethodDisabledException;
use DPay\Exceptions\MethodNotActiveException;
use DPay\Exceptions\NotFoundException;
use DPay\Exceptions\OtpRejectedException;
use DPay\Exceptions\PermissionException;
use DPay\Exceptions\RateLimitException;
use DPay\Exceptions\SessionExpiredException;
use DPay\Exceptions\SessionLockedException;
use DPay\Exceptions\SessionNotPendingException;
use DPay\Exceptions\UnsupportedMethodException;
use DPay\Exceptions\ValidationException;
use DPay\Http\RetryPolicy;
use DPay\Models\CheckoutSession;
use DPay\Models\CheckoutSessionPage;
use DPay\Models\CheckoutStatus;
use DPay\Models\OpenedSession;
use DPay\Models\PaymentPage;
use DPay\Models\PaymentSession;
use DPay\Models\PayMethod;
use DPay\Models\SessionStatus;
use DPay\Models\VerifyResult;
use DPay\Tests\Support\Clients;
use DPay\Tests\Support\FixtureTransport;
use DPay\Tests\Support\Postman;
use DPay\Tests\Support\Sample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Replays EVERY request/response pair of the execution-generated Postman
 * collection through the SDK with a fixture transport and asserts that:
 *
 *   1. the SDK builds the same request the collection recorded (method,
 *      URL, bearer presence, Idempotency-Key, JSON body);
 *   2. the recorded answer decodes into the documented model — or the
 *      documented typed exception with the API's message intact.
 *
 * The expectation table below is exhaustive on purpose: when the platform
 * regenerates the collection with a sample this SDK cannot classify, the
 * suite FAILS ({@see PostmanContractTest::everySampleIsClassified()}), which
 * is the signal to teach the SDK the new shape before a merchant meets it.
 */
final class PostmanContractTest extends TestCase
{
    /**
     * `"{folder path}/{request name} → {response name}"` ⇒ expectation.
     * `model` = the class a 2xx must decode into; `exception` = the class an
     * error must map to. `check` runs extra assertions on the result.
     *
     * @return array<string, array{model?: string, exception?: class-string<ApiException>, check?: \Closure(mixed): void}>
     */
    private static function expectations(): array
    {
        $paid = static function (mixed $r): void {
            self::assertInstanceOf(VerifyResult::class, $r);
            self::assertSame(SessionStatus::Paid, $r->status);
            self::assertFalse($r->alreadyVerified());
            self::assertNotNull($r->payment);
        };
        $sandboxPaid = static function (mixed $r): void {
            self::assertInstanceOf(VerifyResult::class, $r);
            self::assertTrue($r->isPaid());
            self::assertTrue($r->sandbox);
            self::assertNull($r->payment, 'sandbox verify has no nested payment');
        };
        $opened = static fn (?string $link = null, ?string $currency = 'LYD', bool $sandbox = false): \Closure => static function (mixed $r) use ($link, $currency, $sandbox): void {
            self::assertInstanceOf(OpenedSession::class, $r);
            self::assertSame(SessionStatus::Pending, $r->status);
            self::assertSame($link, $r->paymentLink);
            self::assertSame($currency, $r->currency);
            self::assertSame($sandbox, $r->sandbox);
            self::assertFalse($r->replayed);
        };
        $validation = static fn (?array $fields = null): \Closure => static function (mixed $e) use ($fields): void {
            self::assertInstanceOf(ValidationException::class, $e);
            if ($fields !== null) {
                self::assertSame($fields, $e->fields());
            }
        };
        $limit = static fn (string $limit): \Closure => static function (mixed $e) use ($limit): void {
            self::assertInstanceOf(AmountOutOfRangeException::class, $e);
            self::assertSame($limit, $e->limit);
        };
        $payMethods = static fn (int $count): \Closure => static function (mixed $r) use ($count): void {
            self::assertIsArray($r);
            self::assertCount($count, $r);
            self::assertContainsOnlyInstancesOf(PayMethod::class, $r);
        };
        $checkout = static fn (CheckoutStatus $status, string $amount, string $reference, bool $live = true, bool $replay = false): \Closure => static function (mixed $r) use ($status, $amount, $reference, $live, $replay): void {
            self::assertInstanceOf(CheckoutSession::class, $r);
            self::assertSame($status, $r->status);
            self::assertSame($amount, $r->amount->format(2), 'the v2 amount as the API wrote it, at 2dp');
            self::assertSame('LYD', $r->currency);
            self::assertSame($reference, $r->reference);
            self::assertSame($live, $r->live);
            self::assertSame($replay, $r->idempotentReplay);
            self::assertStringStartsWith($live ? 'cs_01' : 'cs_test_', $r->id);
            self::assertSame(($live ? 'https://dpay.ly/pay/' : 'https://dpay.ly/sandbox/pay/').$r->id, $r->url);
            self::assertNotNull($r->createdAt, 'v2 timestamps decode');
            self::assertNotNull($r->expiresAt);
            self::assertTrue($r->matchesOrder($amount, 'LYD', $reference), 'the transition guard accepts the recorded order');
        };
        $notOpen = static fn (string $checkoutStatus): \Closure => static function (mixed $e) use ($checkoutStatus): void {
            self::assertInstanceOf(CheckoutNotOpenException::class, $e);
            self::assertSame('checkout_not_open', $e->errorCode());
            self::assertSame($checkoutStatus, $e->body['checkout_status'] ?? null);
        };

        return [
            '/Health/Health → 200 — healthy' => ['model' => 'array', 'check' => static function (mixed $r): void {
                self::assertIsArray($r);
                self::assertSame('ok', $r['status']);
            }],

            '/Pay methods/List pay methods → 200 — the catalogue for this merchant' => ['model' => 'list', 'check' => static function (mixed $r) use ($payMethods): void {
                $payMethods(8)($r);
                self::assertIsArray($r);
                $mpgs = $r[6];
                self::assertInstanceOf(PayMethod::class, $mpgs);
                self::assertSame('USD', $mpgs->currency);
                self::assertSame('2.5', $mpgs->feePercent);
                self::assertNull($mpgs->otpLength, 'A34: otp_length is null for hosted-page methods');
                $edfali = $r[0];
                self::assertInstanceOf(PayMethod::class, $edfali);
                self::assertSame(4, $edfali->otpLength, 'A34: otp_length published per method');
            }],
            '/Pay methods/List pay methods → 401 — unknown bearer' => ['exception' => AuthenticationException::class],
            '/Pay methods/List pay methods — no bearer → 401 — unauthenticated' => ['exception' => AuthenticationException::class],
            '/Pay methods/List pay methods — token without the ability → 403 — insufficient token ability' => ['exception' => PermissionException::class, 'check' => static function (mixed $e): void {
                self::assertInstanceOf(PermissionException::class, $e);
                self::assertSame('insufficient_token_ability', $e->errorCode());
            }],

            '/Payment sessions/Open a session/Open — Moamalat (card) → 200 — session opened' => ['model' => OpenedSession::class, 'check' => $opened('https://pg.dits.ly/moamalat-pay/1')],
            '/Payment sessions/Open a session/Open — EDFali (wallet OTP) → 200 — OTP sent to the customer' => ['model' => OpenedSession::class, 'check' => static function (mixed $r) use ($opened): void {
                $opened()($r);
                self::assertInstanceOf(OpenedSession::class, $r);
                self::assertSame('75.5', $r->amount->value);
                self::assertSame('0.755', $r->feeAmount->value);
                self::assertSame('76.255', $r->total->value);
                self::assertSame('76.26', $r->charge()->value, 'the stored charge every later read reports');
            }],
            '/Payment sessions/Open a session/Open — MobiCash (card OTP) → 200 — OTP sent to the customer' => ['model' => OpenedSession::class, 'check' => $opened()],
            '/Payment sessions/Open a session/Open — MasrefyPay (MITF card OTP) → 200 — OTP sent to the customer' => ['model' => OpenedSession::class, 'check' => $opened()],
            '/Payment sessions/Open a session/Open — YousrPay (MITF card OTP) → 200 — OTP sent to the customer' => ['model' => OpenedSession::class, 'check' => $opened()],
            '/Payment sessions/Open a session/Open — SaharaPay (MITF card OTP) → 200 — OTP sent to the customer' => ['model' => OpenedSession::class, 'check' => $opened()],
            '/Payment sessions/Open a session/Open — Sadad (mobile OTP) → 200 — OTP sent to the customer' => ['model' => OpenedSession::class, 'check' => $opened()],
            '/Payment sessions/Open a session/Open — Mastercard (MPGS, hosted card page) → 200 — session opened' => ['model' => OpenedSession::class, 'check' => $opened('https://dpay.ly/mpgs-pay/8', 'USD')],
            '/Payment sessions/Open a session/Open — with an Idempotency-Key → 200 — first call opens the session' => ['model' => OpenedSession::class, 'check' => $opened('https://pg.dits.ly/moamalat-pay/9')],
            '/Payment sessions/Open a session/Open — with an Idempotency-Key → 200 — replay with the same key returns the same session' => ['model' => OpenedSession::class, 'check' => static function (mixed $r): void {
                self::assertInstanceOf(OpenedSession::class, $r);
                self::assertTrue($r->replayed);
                self::assertNull($r->currency);
                self::assertSame(100, $r->dataValue('original_amount'));
                self::assertSame('https://pg.dits.ly/moamalat-pay/9', $r->paymentLink);
            }],
            '/Payment sessions/Open a session/Open — for an invoice you do not own → 403 — invoice not found' => ['exception' => PermissionException::class],

            '/Payment sessions/Open — validation and refusals/Missing required fields → 422 — required' => ['exception' => ValidationException::class, 'check' => $validation(['pay_method', 'amount'])],
            '/Payment sessions/Open — validation and refusals/Amount is not numeric → 422 — numeric' => ['exception' => ValidationException::class, 'check' => $validation(['amount'])],
            '/Payment sessions/Open — validation and refusals/Amount below 0.01 → 422 — min' => ['exception' => ValidationException::class, 'check' => $validation(['amount'])],
            '/Payment sessions/Open — validation and refusals/Data is not an object → 422 — array' => ['exception' => ValidationException::class, 'check' => $validation(['data'])],
            '/Payment sessions/Open — validation and refusals/Unknown pay_method → 422 — exists' => ['exception' => ValidationException::class, 'check' => static function (mixed $e): void {
                self::assertInstanceOf(ValidationException::class, $e);
                self::assertSame('The selected pay method is invalid.', $e->first('pay_method'));
            }],
            '/Payment sessions/Open — validation and refusals/EDFali — invalid customer_mobile → 422 — regex' => ['exception' => ValidationException::class, 'check' => $validation(['customer_mobile'])],
            '/Payment sessions/Open — validation and refusals/Sadad — birth_year is not 4 digits → 422 — digits' => ['exception' => ValidationException::class, 'check' => $validation(['birth_year'])],
            '/Payment sessions/Open — validation and refusals/Sadad — category out of range → 422 — between' => ['exception' => ValidationException::class, 'check' => $validation(['category'])],
            '/Payment sessions/Open — validation and refusals/Sadad — category is not an integer → 422 — integer' => ['exception' => ValidationException::class, 'check' => $validation(['category'])],
            '/Payment sessions/Open — validation and refusals/MobiCash — card_number is not a string → 422 — string' => ['exception' => ValidationException::class, 'check' => $validation(['card_number'])],
            '/Payment sessions/Open — validation and refusals/MobiCash — description too long → 422 — max' => ['exception' => ValidationException::class, 'check' => $validation(['description'])],
            '/Payment sessions/Open — validation and refusals/MITF — cross-bank card without OnePay → 422 — cross-bank refused' => ['exception' => CrossBankCardException::class],
            '/Payment sessions/Open — validation and refusals/Gateway inactive on the platform → 400 — payment method is not active' => ['exception' => MethodNotActiveException::class],
            '/Payment sessions/Open — validation and refusals/Gateway disabled by the merchant → 400 — currently disabled' => ['exception' => MethodDisabledException::class],
            '/Payment sessions/Open — validation and refusals/Amount below the minimum deposit → 400 — below the minimum' => ['exception' => AmountOutOfRangeException::class, 'check' => $limit('5')],
            '/Payment sessions/Open — validation and refusals/Amount above the maximum deposit → 400 — above the maximum' => ['exception' => AmountOutOfRangeException::class, 'check' => $limit('60000')],

            '/Payment sessions/Read a session/Get a session → 200 — Moamalat session (with payment_link)' => ['model' => PaymentSession::class, 'check' => static function (mixed $r): void {
                self::assertInstanceOf(PaymentSession::class, $r);
                self::assertSame('256.25', $r->amount->value);
                self::assertSame('250', $r->originalAmount()?->value);
                self::assertSame('https://pg.dits.ly/moamalat-pay/1', $r->paymentLink);
            }],
            '/Payment sessions/Read a session/Get a session → 200 — OTP gateway session (no payment_link)' => ['model' => PaymentSession::class, 'check' => static function (mixed $r): void {
                self::assertInstanceOf(PaymentSession::class, $r);
                self::assertSame('76.26', $r->amount->value, '2dp charge, not the 3dp total');
                self::assertNull($r->paymentLink);
            }],
            '/Payment sessions/Read a session/Get a session → 404 — unknown session' => ['exception' => NotFoundException::class],
            '/Payment sessions/Read a session/Get a session — owned by another merchant → 403 — not authorized' => ['exception' => PermissionException::class],

            '/Payment sessions/Verify/Verify — EDFali OTP → 200 — paid' => ['model' => VerifyResult::class, 'check' => static function (mixed $r) use ($paid): void {
                $paid($r);
                self::assertInstanceOf(VerifyResult::class, $r);
                self::assertSame('76.26', $r->amount->value);
                self::assertSame('https://dpay.ly/receipt/2/6f0a2b9c4d8e1f3a5b7c9d0e2f4a6b8c', $r->receiptUrl);
                self::assertSame('completed', $r->payment->status);
            }],
            '/Payment sessions/Verify/Verify — EDFali OTP → 200 — already verified' => ['model' => VerifyResult::class, 'check' => static function (mixed $r): void {
                self::assertInstanceOf(VerifyResult::class, $r);
                self::assertTrue($r->alreadyVerified());
                self::assertTrue($r->isPaid());
                self::assertNull($r->payment);
            }],
            '/Payment sessions/Verify/Verify — EDFali wrong OTP, then lock-out → 422 — OTP rejected by the bank' => ['exception' => OtpRejectedException::class],
            '/Payment sessions/Verify/Verify — EDFali wrong OTP, then lock-out → 422 — the 5th failed attempt locks the session' => ['exception' => SessionLockedException::class],
            '/Payment sessions/Verify/Verify — Sadad OTP → 200 — paid' => ['model' => VerifyResult::class, 'check' => static function (mixed $r) use ($paid): void {
                $paid($r);
                self::assertInstanceOf(VerifyResult::class, $r);
                self::assertNotNull($r->payment);
                self::assertSame('0912****', $r->payment->payerAccount);
                self::assertSame('2455121', $r->txId);
            }],
            '/Payment sessions/Verify/Verify — MobiCash OTP → 200 — paid' => ['model' => VerifyResult::class, 'check' => $paid],
            '/Payment sessions/Verify/Verify — MITF OTP (MasrefyPay / YousrPay / SaharaPay) → 200 — paid' => ['model' => VerifyResult::class, 'check' => $paid],
            '/Payment sessions/Verify/Verify — Moamalat LightBox callback (public) → 200 — paid' => ['model' => VerifyResult::class, 'check' => static function (mixed $r) use ($paid): void {
                $paid($r);
                self::assertInstanceOf(VerifyResult::class, $r);
                self::assertNotNull($r->payment);
                self::assertSame('6394****', $r->payment->payerAccount);
                self::assertSame('Card', $r->payment->paidThrough);
            }],
            '/Payment sessions/Verify/Verify — Moamalat callback with a foreign reference → 422 — reference mismatch' => ['exception' => GatewayRejectedException::class],
            '/Payment sessions/Verify/Verify — session is not pending → 400 — not pending' => ['exception' => SessionNotPendingException::class],
            '/Payment sessions/Verify/Verify — session has expired → 400 — expired' => ['exception' => SessionExpiredException::class],
            '/Payment sessions/Verify/Verify — OTP gateway without a bearer → 403 — not authorized' => ['exception' => PermissionException::class],
            '/Payment sessions/Verify/Verify — unknown session → 422 — selected session id is invalid' => ['exception' => ValidationException::class, 'check' => $validation(['session_id'])],
            '/Payment sessions/Verify/Verify — Mastercard session → 400 — unsupported payment method' => ['exception' => UnsupportedMethodException::class],
            '/Payment sessions/Verify/Verify — rate limited → 429 — too many attempts' => ['exception' => RateLimitException::class, 'check' => static function (mixed $e): void {
                self::assertInstanceOf(RateLimitException::class, $e);
                self::assertSame(60, $e->retryAfter);
            }],

            '/Payments/List payments → 200 — first page' => ['model' => PaymentPage::class, 'check' => static function (mixed $r): void {
                self::assertInstanceOf(PaymentPage::class, $r);
                self::assertSame(5, $r->total);
                self::assertFalse($r->hasMore());
                self::assertSame('256.25', $r->data[0]->amount->value);
            }],
            '/Payments/Filter payments → 200 — filtered page' => ['model' => PaymentPage::class],
            '/Payments/Filter payments — invalid range → 422 — validation' => ['exception' => ValidationException::class, 'check' => $validation(['to', 'type'])],

            '/Checkout Sessions/Create a checkout session → 201 — created' => ['model' => CheckoutSession::class, 'check' => static function (mixed $r) use ($checkout): void {
                $checkout(CheckoutStatus::Open, '125.50', '10483')($r);
                self::assertInstanceOf(CheckoutSession::class, $r);
                self::assertTrue($r->isOpen());
                self::assertNull($r->payment);
                self::assertSame([], $r->attempts);
                self::assertSame(['order_id' => 10483, 'platform' => 'woocommerce', 'plugin_version' => '1.0.0'], $r->metadata);
                self::assertSame('سالم علي', $r->customer?->name);
                self::assertSame('https://shop.example.ly/cart', $r->cancelUrl);
            }],
            '/Checkout Sessions/Create a checkout session → 200 — replay with the same Idempotency-Key returns the same session' => ['model' => CheckoutSession::class, 'check' => $checkout(CheckoutStatus::Open, '125.50', '10483', replay: true)],
            '/Checkout Sessions/Create a checkout session → 401 — unknown bearer' => ['exception' => AuthenticationException::class, 'check' => static function (mixed $e): void {
                self::assertInstanceOf(AuthenticationException::class, $e);
                self::assertSame('/api/v2/problems/unauthenticated', $e->problemType);
            }],
            '/Checkout Sessions/Create — same Idempotency-Key, different body → 409 — idempotency key reused' => ['exception' => IdempotencyException::class, 'check' => static function (mixed $e): void {
                self::assertInstanceOf(IdempotencyException::class, $e);
                self::assertSame('idempotency_key_reused', $e->errorCode());
            }],
            '/Checkout Sessions/Create — amount with a third decimal → 422 — validation failed' => ['exception' => ValidationException::class, 'check' => static function (mixed $e) use ($validation): void {
                $validation(['amount'])($e);
                self::assertInstanceOf(ValidationException::class, $e);
                self::assertSame('The amount may have at most two decimal places.', $e->first('amount'));
            }],

            '/Checkout Sessions/Get a checkout session → 200 — open, no attempts yet' => ['model' => CheckoutSession::class, 'check' => $checkout(CheckoutStatus::Open, '125.50', '10483')],
            '/Checkout Sessions/Get a checkout session → 200 — paid, with the winning payment and the attempts' => ['model' => CheckoutSession::class, 'check' => static function (mixed $r) use ($checkout): void {
                $checkout(CheckoutStatus::Paid, '125.50', '10483')($r);
                self::assertInstanceOf(CheckoutSession::class, $r);
                self::assertTrue($r->isPaid());
                self::assertNotNull($r->paidAt);
                self::assertNotNull($r->payment);
                self::assertSame(22, $r->payment->sessionId);
                self::assertSame('edfali', $r->payment->payMethod);
                self::assertSame('126.76', $r->payment->amountCharged->value, 'what the payer was debited: 2dp');
                self::assertSame('1.255', $r->payment->feeAmount?->value, '3dp live fee');
                self::assertSame('https://dpay.ly/receipt/22/6f0a2b9c4d8e1f3a5b7c9d0e2f4a6b8c', $r->payment->receiptUrl);
                self::assertCount(1, $r->attempts);
                self::assertSame(SessionStatus::Paid, $r->attempts[0]->status);
            }],
            '/Checkout Sessions/Get a checkout session → 200 — expired on read (lazy expiry)' => ['model' => CheckoutSession::class, 'check' => static function (mixed $r) use ($checkout): void {
                $checkout(CheckoutStatus::Expired, '40.00', '10490')($r);
                self::assertInstanceOf(CheckoutSession::class, $r);
                self::assertTrue($r->status->isTerminal());
                self::assertNull($r->payment);
            }],
            '/Checkout Sessions/Get a checkout session → 404 — unknown id' => ['exception' => NotFoundException::class, 'check' => static function (mixed $e): void {
                self::assertInstanceOf(NotFoundException::class, $e);
                self::assertSame('/api/v2/problems/not-found', $e->problemType);
            }],

            '/Checkout Sessions/List checkout sessions → 200 — first page' => ['model' => CheckoutSessionPage::class, 'check' => static function (mixed $r): void {
                self::assertInstanceOf(CheckoutSessionPage::class, $r);
                self::assertCount(2, $r->data);
                self::assertSame(10, $r->limit);
                self::assertFalse($r->hasMore);
                self::assertNull($r->nextCursor);
                self::assertSame(CheckoutStatus::Expired, $r->data[0]->status, 'newest first');
                self::assertSame(CheckoutStatus::Paid, $r->data[1]->status);
                self::assertSame([], $r->data[1]->attempts, 'list rows carry no attempts');
                self::assertSame('126.76', $r->data[1]->payment?->amountCharged->value);
            }],

            '/Checkout Sessions/Cancel a checkout session → 200 — cancelled' => ['model' => CheckoutSession::class, 'check' => static function (mixed $r) use ($checkout): void {
                $checkout(CheckoutStatus::Cancelled, '60.00', '10491')($r);
                self::assertInstanceOf(CheckoutSession::class, $r);
                self::assertNotNull($r->cancelledAt);
                self::assertNull($r->paidAt);
            }],
            '/Checkout Sessions/Cancel a checkout session → 409 — not open (already cancelled)' => ['exception' => CheckoutNotOpenException::class, 'check' => $notOpen('cancelled')],
            '/Checkout Sessions/Cancel a checkout session → 409 — not open (already paid)' => ['exception' => CheckoutNotOpenException::class, 'check' => $notOpen('paid')],

            '/Checkout Sessions/Sandbox — create a checkout session → 201 — created (sandbox)' => ['model' => CheckoutSession::class, 'check' => static function (mixed $r) use ($checkout): void {
                $checkout(CheckoutStatus::Open, '10.50', 'TEST-1', live: false)($r);
                self::assertInstanceOf(CheckoutSession::class, $r);
                self::assertSame(['order_id' => 'test-1'], $r->metadata);
            }],
            '/Checkout Sessions/Sandbox — get a checkout session → 200 — paid (sandbox)' => ['model' => CheckoutSession::class, 'check' => static function (mixed $r) use ($checkout): void {
                $checkout(CheckoutStatus::Paid, '10.50', 'TEST-1', live: false)($r);
                self::assertInstanceOf(CheckoutSession::class, $r);
                self::assertNotNull($r->payment);
                self::assertSame('10.61', $r->payment->amountCharged->value);
                self::assertSame('0.11', $r->payment->feeAmount?->value, '2dp sandbox fee');
                self::assertNull($r->payment->receiptUrl, 'no receipt in the sandbox');
            }],
            '/Checkout Sessions/Sandbox — get a checkout session → 404 — a live id is not found with a sandbox token' => ['exception' => NotFoundException::class],
            '/Checkout Sessions/Sandbox — get a checkout session → 404 — a sandbox id is not found with a live token' => ['exception' => NotFoundException::class],

            '/Sandbox/Sandbox — open EDFali → 200 — sandbox session opened' => ['model' => OpenedSession::class, 'check' => static function (mixed $r) use ($opened): void {
                $opened(null, null, true)($r);
                self::assertInstanceOf(OpenedSession::class, $r);
                self::assertSame('0.11', $r->feeAmount->value, '2dp sandbox fee');
                self::assertSame('10.61', $r->total->value);
            }],
            '/Sandbox/Sandbox — open EDFali → 401 — unknown sandbox token' => ['exception' => AuthenticationException::class],
            '/Sandbox/Sandbox — open with an Idempotency-Key → 200 — first call opens the session' => ['model' => OpenedSession::class, 'check' => static function (mixed $r): void {
                self::assertInstanceOf(OpenedSession::class, $r);
                self::assertTrue($r->sandbox);
                self::assertFalse($r->replayed, 'first call: the request data is echoed verbatim, no stored markers');
                self::assertSame('Order #10486', $r->dataValue('description'));
            }],
            '/Sandbox/Sandbox — open with an Idempotency-Key → 200 — replay with the same key returns the same session' => ['model' => OpenedSession::class, 'check' => static function (mixed $r): void {
                self::assertInstanceOf(OpenedSession::class, $r);
                self::assertTrue($r->sandbox);
                self::assertTrue($r->replayed, 'sandbox replay: the stored data carries fee_percent/original_amount (A34)');
                self::assertSame(15, $r->sessionId);
            }],
            '/Sandbox/Sandbox — open Moamalat → 200 — sandbox session opened' => ['model' => OpenedSession::class, 'check' => $opened('https://pg.dits.ly/sandbox/moamalat-pay/16/1b2c3d4e5f60718293a4b5c6d7e8f901', null, true)],
            '/Sandbox/Sandbox — open MobiCash → 200 — sandbox session opened' => ['model' => OpenedSession::class, 'check' => $opened(null, null, true)],
            '/Sandbox/Sandbox — open MasrefyPay with a cross-bank card → 200 — sandbox session opened' => ['model' => OpenedSession::class, 'check' => $opened(null, null, true)],
            '/Sandbox/Sandbox — open Sadad (unsupported) → 422 — unsupported in the sandbox' => ['exception' => UnsupportedMethodException::class],
            '/Sandbox/Sandbox — open with an unknown pay_method → 422 — exists' => ['exception' => ValidationException::class, 'check' => $validation(['pay_method'])],
            '/Sandbox/Sandbox — open without a token → 401 — sandbox token required' => ['exception' => AuthenticationException::class],
            '/Sandbox/Sandbox — verify with 111111 → 200 — paid' => ['model' => VerifyResult::class, 'check' => static function (mixed $r) use ($sandboxPaid): void {
                $sandboxPaid($r);
                self::assertInstanceOf(VerifyResult::class, $r);
                self::assertSame('10.61', $r->amount->value, 'decimal-string amount');
            }],
            '/Sandbox/Sandbox — verify with 111111 → 200 — already verified' => ['model' => VerifyResult::class, 'check' => static function (mixed $r): void {
                self::assertInstanceOf(VerifyResult::class, $r);
                self::assertTrue($r->alreadyVerified());
            }],
            '/Sandbox/Sandbox — verify with 111111 → 401 — unknown sandbox token' => ['exception' => AuthenticationException::class],
            '/Sandbox/Sandbox — verify with 000000 → 400 — simulated final failure' => ['exception' => GatewayDeclinedException::class, 'check' => static function (mixed $e): void {
                self::assertInstanceOf(GatewayDeclinedException::class, $e);
                self::assertSame(19, $e->sessionId);
            }],
            '/Sandbox/Sandbox — verify with any other OTP → 422 — invalid OTP' => ['exception' => OtpRejectedException::class],
            '/Sandbox/Sandbox — verify an unknown session → 404 — payment session not found' => ['exception' => NotFoundException::class],
            '/Sandbox/Sandbox — verify without a token → 401 — sandbox API token required' => ['exception' => AuthenticationException::class],
            "/Sandbox/Sandbox — verify with another merchant's token → 403 — not authorized" => ['exception' => PermissionException::class],
            '/Sandbox/Sandbox — verify Moamalat without a token or nonce → 403 — owning token or nonce required' => ['exception' => PermissionException::class],
            '/Sandbox/Sandbox — verify Moamalat with moamalat_response → 200 — paid' => ['model' => VerifyResult::class, 'check' => $sandboxPaid],
            '/Sandbox/Sandbox — verify Moamalat with a foreign reference → 422 — reference mismatch' => ['exception' => GatewayRejectedException::class],
            '/Sandbox/Sandbox — get a session → 200 — paid session' => ['model' => PaymentSession::class, 'check' => static function (mixed $r): void {
                self::assertInstanceOf(PaymentSession::class, $r);
                self::assertTrue($r->isPaid());
                self::assertSame('10.61', $r->amount->value);
            }],
            '/Sandbox/Sandbox — get a session → 200 — expired on read (lazy expiry)' => ['model' => PaymentSession::class, 'check' => static function (mixed $r): void {
                self::assertInstanceOf(PaymentSession::class, $r);
                self::assertSame(SessionStatus::Expired, $r->status);
            }],
            '/Sandbox/Sandbox — list pay methods → 200 — bare array' => ['model' => 'list', 'check' => static function (mixed $r) use ($payMethods): void {
                $payMethods(9)($r);
                self::assertIsArray($r);
                $onepay = $r[8];
                self::assertInstanceOf(PayMethod::class, $onepay);
                self::assertSame('onepay', $onepay->slug);
                self::assertFalse($onepay->isUsable());
            }],
        ];
    }

    /** @return iterable<string, array{Sample}> */
    public static function apiSamples(): iterable
    {
        foreach (Postman::samples() as $sample) {
            if ($sample->isWebhook()) {
                continue;
            }
            yield $sample->key() => [$sample];
        }
    }

    #[Test]
    public function everySampleIsClassified(): void
    {
        $known = self::expectations();
        $missing = [];
        $seen = [];
        foreach (self::apiSamples() as [$sample]) {
            $key = $sample->key();
            $seen[$key] = true;
            if (!isset($known[$key])) {
                $missing[] = $key;
            }
        }
        self::assertSame([], $missing, "The Postman collection has samples this SDK cannot classify — teach the SDK the new shape:\n - ".implode("\n - ", $missing));
        $stale = array_diff(array_keys($known), array_keys($seen));
        self::assertSame([], array_values($stale), "Expectations without a sample (renamed or removed in the collection):\n - ".implode("\n - ", $stale));
    }

    #[Test]
    #[DataProvider('apiSamples')]
    public function replaysTheRecordedExchange(Sample $sample): void
    {
        $expectation = self::expectations()[$sample->key()] ?? null;
        self::assertNotNull($expectation, 'unclassified sample — see everySampleIsClassified()');

        $transport = (new FixtureTransport())->enqueue($sample->responseCode, $sample->responseBody, $sample->responseHeaders);
        $client = $sample->isSandbox()
            ? Client::sandbox(Clients::SANDBOX_TOKEN, ['transport' => $transport, 'retry' => RetryPolicy::none()])
            : Client::live(Clients::LIVE_TOKEN, ['transport' => $transport, 'retry' => RetryPolicy::none()]);

        $result = null;
        $thrown = null;
        try {
            $result = self::invoke($client, $sample);
        } catch (ApiException $e) {
            $thrown = $e;
        }

        // 1. The SDK sent what the collection recorded.
        $sent = $transport->last();
        self::assertSame($sample->method, $sent['method']);
        self::assertSame(self::normalizeUrl($sample->url), self::normalizeUrl($sent['url']));
        if ($sample->urlPath() === '/api/health') {
            self::assertArrayNotHasKey('Authorization', $sent['headers'], 'health is unauthenticated');
        } else {
            self::assertArrayHasKey('Authorization', $sent['headers'], 'every other SDK call carries the bearer');
        }
        if (isset($sample->headers['Idempotency-Key'])) {
            self::assertSame($sample->headers['Idempotency-Key'], $sent['headers']['Idempotency-Key'] ?? null);
        }
        if ($sample->body !== null) {
            // Key order is not part of the JSON contract: compare canonical (key-sorted) forms.
            self::assertSame(self::canonical($sample->bodyJson()), self::canonical($transport->lastJson()), 'request body');
            self::assertSame('application/json', $sent['headers']['Content-Type'] ?? null);
        }

        // 2. The recorded answer became the documented model or exception.
        if (isset($expectation['exception'])) {
            self::assertNotNull($thrown, 'expected '.$expectation['exception'].' but the SDK returned '.get_debug_type($result));
            self::assertInstanceOf($expectation['exception'], $thrown);
            self::assertSame($sample->responseCode, $thrown->status);
            $recorded = $sample->responseJson();
            // legacy `{message}` or RFC 7807 `{detail}` (falling back to `title`): the wording survives byte for byte.
            $wording = $recorded['message'] ?? $recorded['detail'] ?? $recorded['title'] ?? null;
            self::assertSame($wording, $thrown->getMessage(), 'the API message survives byte for byte');
            if (isset($recorded['type']) && is_string($recorded['type'])) {
                self::assertSame($recorded['type'], $thrown->problemType, 'RFC 7807 type is exposed');
            }
            self::assertSame($recorded, $thrown->body);
            $subject = $thrown;
        } else {
            self::assertNull($thrown, 'unexpected '.($thrown === null ? '' : $thrown::class.': '.$thrown->getMessage()));
            $model = $expectation['model'] ?? null;
            self::assertNotNull($model);
            if ($model === 'array' || $model === 'list') {
                self::assertIsArray($result);
            } else {
                self::assertTrue(class_exists($model));
                self::assertInstanceOf($model, $result);
            }
            $subject = $result;
        }
        if (isset($expectation['check'])) {
            ($expectation['check'])($subject);
        }
    }

    private static function invoke(Client $client, Sample $sample): mixed
    {
        $path = $sample->urlPath();
        $body = $sample->bodyJson();
        $idempotencyKey = $sample->headers['Idempotency-Key'] ?? null;
        $sessions = $client->paymentSessions();

        $checkouts = $client->checkoutSessions();
        parse_str((string) parse_url($sample->url, PHP_URL_QUERY), $query);

        return match (true) {
            $path === '/api/health' => $client->health(),
            str_ends_with($path, '/pay-methods') => $client->payMethods()->list(),
            str_ends_with($path, '/payment/sessions/open') => $sessions->openRaw($body, $idempotencyKey),
            str_ends_with($path, '/payment/sessions/verify') => $sessions->verifyRaw($body),
            preg_match('#/payment/sessions/(\d+)$#', $path, $m) === 1 => $sessions->get((int) $m[1]),
            $path === '/api/payments' => $client->payments()->list(),
            $path === '/api/payments/filter' => $client->payments()->filterRaw($body),
            // A34 hosted checkout — one path for both environments; the token decides.
            $path === '/api/v2/checkout-sessions' && $sample->method === 'POST' => $checkouts->createRaw($body, $idempotencyKey),
            $path === '/api/v2/checkout-sessions' && $sample->method === 'GET' => $checkouts->list(limit: (int) ($query['limit'] ?? 25)),
            preg_match('#^/api/v2/checkout-sessions/([^/]+)/cancel$#', $path, $m) === 1 => $checkouts->cancel($m[1]),
            preg_match('#^/api/v2/checkout-sessions/([^/]+)$#', $path, $m) === 1 => $checkouts->get($m[1]),
            default => self::fail('No SDK operation for '.$sample->method.' '.$path),
        };
    }

    /**
     * Recursively key-sorted copy, so two encodings of the same JSON object
     * compare equal whatever order their keys were written in. Lists keep
     * their order (it IS significant there).
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = is_array($v) ? self::canonical($v) : $v;
        }
        if (!array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    private static function normalizeUrl(string $url): string
    {
        // The SDK always sends ?page=1 on the payments list; the collection omits it.
        return (string) preg_replace('/\?page=1$/', '', $url);
    }
}
