<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Resources;

use DPay\Exceptions\GatewayDeclinedException;
use DPay\Exceptions\InvalidArgumentException;
use DPay\Exceptions\UnsupportedInEnvironmentException;
use DPay\Exceptions\UnsupportedMethodException;
use DPay\Models\SessionStatus;
use DPay\Requests\OpenSessionRequest;
use DPay\Requests\PaymentsFilter;
use DPay\Tests\Support\Clients;
use DPay\Tests\Support\FixtureTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class V1ResourcesTest extends TestCase
{
    #[Test]
    public function payMethodsReadBothShapesAndFilterUsableOnes(): void
    {
        $live = (new FixtureTransport())->enqueueJson(200, ['data' => [
            ['id' => 1, 'name' => 'Edfali', 'slug' => 'edfali', 'tag' => 'edfali', 'icon' => 'edfali.svg', 'logo_url' => 'https://dpay.ly/x.svg', 'currency' => 'LYD', 'fee' => 1, 'min_deposit' => 5, 'max_deposit' => 60000, 'active' => true, 'configured' => true, 'enabled' => true, 'cross_bank_enabled' => false, 'otp_length' => 4],
            ['id' => 7, 'name' => 'Mastercard', 'slug' => 'mpgs', 'tag' => 'mpgs', 'icon' => 'm.svg', 'logo_url' => 'https://dpay.ly/m.svg', 'currency' => 'USD', 'fee' => 2.5, 'min_deposit' => 5, 'max_deposit' => 60000, 'active' => true, 'configured' => false, 'enabled' => true, 'cross_bank_enabled' => false, 'otp_length' => null],
        ]]);
        $methods = Clients::live($live)->payMethods()->list();
        self::assertCount(2, $methods);
        self::assertSame('1', $methods[0]->feePercent);
        self::assertSame(4, $methods[0]->otpLength);
        self::assertTrue($methods[0]->isUsable());
        self::assertFalse($methods[1]->isUsable(), 'not configured');
        self::assertTrue($methods[1]->chargesUsd());
        self::assertNull($methods[1]->otpLength);
        self::assertTrue($methods[0]->allowsAmount(\DPay\Money\Money::of('5')));
        self::assertFalse($methods[0]->allowsAmount(\DPay\Money\Money::of('4.99')));
        self::assertFalse($methods[0]->allowsAmount(\DPay\Money\Money::of('60000.01')));

        $sandbox = (new FixtureTransport())->enqueueJson(200, [
            ['name' => 'Edfali', 'active' => true, 'tag' => 'edfali', 'fee' => 1, 'min_deposit' => 5, 'max_deposit' => 60000, 'enabled' => true],
            ['name' => 'Sadad', 'active' => true, 'tag' => 'sadad', 'fee' => 1, 'min_deposit' => 5, 'max_deposit' => 60000, 'enabled' => true],
            ['name' => 'OnePay', 'active' => false, 'tag' => 'onepay', 'fee' => 1, 'min_deposit' => 5, 'max_deposit' => 60000, 'enabled' => false],
        ]);
        $usable = Clients::sandbox($sandbox)->payMethods()->usable();
        self::assertCount(1, $usable, 'sadad is not simulated; onepay is inactive');
        self::assertSame('edfali', $usable[0]->slug);
        self::assertNull($usable[0]->configured);
        self::assertSame(4, $usable[0]->otpLength, 'falls back to the Rules table when the API omits otp_length');
    }

    #[Test]
    public function openEchoesTheRequestAndDetectsReplays(): void
    {
        $first = ['message' => 'Payment session created successfully', 'session_id' => 9, 'status' => 'pending', 'amount' => 100, 'currency' => 'LYD', 'fee' => 2.5, 'fee_amount' => 2.5, 'total' => 102.5, 'pay_method' => 'moamalat', 'expired_at' => '2026-09-18T10:15:00.000000Z', 'data' => ['description' => 'Order #10486'], 'payment_link' => 'https://pg.dits.ly/moamalat-pay/9'];
        $replay = ['message' => 'Payment session created successfully', 'session_id' => 9, 'status' => 'pending', 'amount' => 100, 'fee' => 2.5, 'fee_amount' => 2.5, 'total' => 102.5, 'pay_method' => 'moamalat', 'expired_at' => '2026-09-18T10:15:00.000000Z', 'data' => ['fee_amount' => 2.5, 'description' => 'Order #10486', 'fee_percent' => 2.5, 'original_amount' => 100], 'payment_link' => 'https://pg.dits.ly/moamalat-pay/9'];
        $t = (new FixtureTransport())->enqueueJson(200, $first)->enqueueJson(200, $replay);
        $sessions = Clients::live($t)->paymentSessions();
        $req = OpenSessionRequest::moamalat('100')->withDescription('Order #10486');

        $a = $sessions->open($req, 'order-10486');
        self::assertSame(['pay_method' => 'moamalat', 'amount' => '100', 'data' => ['description' => 'Order #10486']], $t->lastJson());
        self::assertFalse($a->replayed);
        self::assertSame('LYD', $a->currency);
        self::assertSame('102.5', $a->total->value);
        self::assertSame('102.5', $a->charge()->value);
        self::assertTrue($a->requiresRedirect());
        self::assertSame('2026-09-18T10:15:00+00:00', $a->expiredAt->format(DATE_ATOM));
        self::assertTrue($a->isExpiredAt(new \DateTimeImmutable('2026-09-18T10:15:00Z')));
        self::assertFalse($a->isExpiredAt(new \DateTimeImmutable('2026-09-18T10:14:59Z')));

        $b = $sessions->open($req, 'order-10486');
        self::assertTrue($b->replayed, 'no currency on a live replay');
        self::assertSame(100, $b->dataValue('original_amount'));
        self::assertSame('https://dpay.ly/mpgs-pay/9', $sessions->mpgsPaymentLink(9));
    }

    #[Test]
    public function sandboxOpenHonoursTheKeyAndDetectsItsReplay(): void
    {
        // A34: the sandbox replay is byte-shaped like a fresh open; only the STORED data (server keys) and a settled status tell it apart.
        $fresh = ['message' => 'Payment session created successfully', 'session_id' => 14, 'status' => 'pending', 'amount' => 10.5, 'fee' => 1, 'fee_amount' => 0.11, 'total' => 10.61, 'pay_method' => 'edfali', 'expired_at' => '2026-09-18T10:15:00.000000Z', 'data' => ['order_id' => 'SBX-1'], 'sandbox' => true];
        $replay = $fresh;
        $replay['data'] = ['order_id' => 'SBX-1', 'fee_amount' => 0.11, 'fee_percent' => 1, 'original_amount' => 10.5];
        $settled = $fresh;
        $settled['status'] = 'paid';
        $t = (new FixtureTransport())->enqueueJson(200, $fresh)->enqueueJson(200, $replay)->enqueueJson(200, $settled);
        $sessions = Clients::sandbox($t)->paymentSessions();
        $req = OpenSessionRequest::edfali('10.50', '0912345678')->withData(['order_id' => 'SBX-1']);

        $a = $sessions->open($req, 'order-sbx-1');
        self::assertSame('order-sbx-1', $t->last()['headers']['Idempotency-Key'] ?? null, 'the key IS sent to the sandbox');
        self::assertFalse($a->replayed);
        self::assertTrue($a->sandbox);
        self::assertSame('0.11', $a->feeAmount->value);

        $b = $sessions->open($req, 'order-sbx-1');
        self::assertTrue($b->replayed, 'stored data carries the server keys');
        self::assertSame(14, $b->sessionId);
        self::assertSame(10.5, $b->dataValue('original_amount'));

        $c = $sessions->open($req, 'order-sbx-1');
        self::assertTrue($c->replayed, 'a settled session can only be a replay');
        self::assertSame(SessionStatus::Paid, $c->status);
    }

    #[Test]
    public function idempotencyKeysAreCappedAt64CharactersOnBothSurfaces(): void
    {
        // payment_sessions.idempotency_key is varchar(64) and the live open runs the bank leg BEFORE the insert.
        $t = new FixtureTransport();
        $sessions = Clients::live($t)->paymentSessions();
        $req = OpenSessionRequest::edfali('10', '0912345678');
        try {
            $sessions->open($req, str_repeat('k', 65));
            self::fail('a 65-character key must be refused before any request is sent');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('64', $e->getMessage());
            self::assertSame(0, $t->count());
        }
        $t->enqueueJson(200, ['message' => 'ok', 'session_id' => 1, 'status' => 'pending', 'amount' => 10, 'currency' => 'LYD', 'fee' => 1, 'fee_amount' => 0.1, 'total' => 10.1, 'pay_method' => 'edfali', 'expired_at' => '2026-09-18T10:15:00.000000Z', 'data' => null]);
        $sessions->open($req, str_repeat('k', 64));
        self::assertSame(str_repeat('k', 64), $t->last()['headers']['Idempotency-Key']);
    }

    #[Test]
    public function alreadyVerifiedWithoutAPaymentRowIsStillSuccess(): void
    {
        // spec 01 §3.4: `payment_id` is null-safe on the duplicate-verify body (a paid session with no Payment row).
        $t = (new FixtureTransport())->enqueueJson(200, ['message' => 'Payment already verified', 'payment_id' => null, 'status' => 'paid', 'amount' => 76.26, 'pay_method' => 'edfali', 'tx_id' => null, 'currency' => 'LYD']);
        $r = Clients::live($t)->paymentSessions()->verify(2, '1234', 'edfali');
        self::assertTrue($r->alreadyVerified());
        self::assertTrue($r->isPaid());
        self::assertNull($r->paymentId);
        self::assertNull($r->payment);
        self::assertSame('76.26', $r->amount->value);
    }

    #[Test]
    public function sandboxOpenRefusesHiddenSlugsBeforeSendingAndParsesStringAmounts(): void
    {
        $t = new FixtureTransport();
        try {
            Clients::sandbox($t)->paymentSessions()->open(OpenSessionRequest::mpgs('10'));
            self::fail('expected refusal');
        } catch (UnsupportedMethodException $e) {
            self::assertSame('Unsupported payment method: mpgs', $e->getMessage());
            self::assertSame(0, $t->count());
        }

        $t->enqueueJson(200, ['session_id' => 14, 'status' => 'paid', 'amount' => '10.61', 'pay_method' => 'edfali', 'tx_id' => 'sb_txn_x', 'expired_at' => '2026-09-18T10:15:00.000000Z', 'data' => ['fee_amount' => 0.11, 'fee_percent' => 1, 'original_amount' => 10.5], 'sandbox' => true]);
        $s = Clients::sandbox($t)->paymentSessions()->get(14);
        self::assertSame('https://dpay.ly/api/sandbox/payment/sessions/14', $t->last()['url']);
        self::assertTrue($s->isPaid());
        self::assertTrue($s->sandbox);
        self::assertSame('10.61', $s->amount->value);
        self::assertSame('10.5', $s->originalAmount()?->value);
        self::assertSame(SessionStatus::Paid, $s->status);
    }

    #[Test]
    public function sandboxToolsSimulateOutcomes(): void
    {
        $t = (new FixtureTransport())
            ->enqueueJson(200, ['message' => 'Payment verified successfully', 'payment_id' => 6, 'status' => 'paid', 'amount' => '10.61', 'pay_method' => 'edfali', 'tx_id' => 'sb_txn_x', 'sandbox' => true])
            ->enqueueJson(400, ['message' => 'Sandbox: gateway declined the transaction (simulated final failure)', 'status' => 'failed', 'session_id' => 18, 'sandbox' => true]);
        $client = Clients::sandbox($t);
        $paid = $client->sandboxTools()->simulatePaid(14);
        self::assertSame(['session_id' => 14, 'otp' => '111111'], $t->lastJson());
        self::assertTrue($paid->isPaid());
        self::assertFalse($paid->alreadyVerified());
        self::assertNull($paid->payment);
        try {
            $client->sandboxTools()->simulateDeclined(18);
        } catch (GatewayDeclinedException $e) {
            self::assertSame(18, $e->sessionId);
            self::assertSame(['session_id' => 18, 'otp' => '000000'], $t->lastJson());
        }
        $this->expectException(UnsupportedInEnvironmentException::class);
        Clients::live(new FixtureTransport())->sandboxTools()->simulatePaid(1);
    }

    #[Test]
    public function paymentsListAndFilterAreLiveOnly(): void
    {
        $page = ['current_page' => 1, 'data' => [['id' => 5, 'user_id' => 9001, 'company_id' => 8001, 'payment_session_id' => 1, 'amount' => 256.25, 'currency' => 'LYD', 'status' => 'completed', 'created_at' => '2026-09-18T10:00:00.000000Z', 'updated_at' => '2026-09-18T10:05:00.000000Z']], 'first_page_url' => 'x', 'from' => 1, 'last_page' => 3, 'last_page_url' => 'x', 'links' => [], 'next_page_url' => 'https://dpay.ly/api/payments?page=2', 'path' => 'x', 'per_page' => 1, 'prev_page_url' => null, 'to' => 1, 'total' => 3];
        $t = (new FixtureTransport())->enqueueJson(200, $page)->enqueueJson(200, $page);
        $client = Clients::live($t);
        $p = $client->payments()->list(1, 1);
        self::assertSame('https://dpay.ly/api/payments?page=1&per_page=1', $t->last()['url']);
        self::assertTrue($p->hasMore());
        self::assertSame(3, $p->total);
        self::assertSame('256.25', $p->data[0]->amount->value);
        self::assertSame('2026-09-18T10:00:00+00:00', $p->data[0]->createdAt?->format(DATE_ATOM));

        $client->payments()->filter(new PaymentsFilter('2020-01-01', '2099-12-31', 'all'));
        self::assertSame(['from' => '2020-01-01', 'to' => '2099-12-31', 'type' => 'all'], $t->lastJson());

        try {
            new PaymentsFilter('2026-12-31', '2026-01-01');
            self::fail('expected refusal');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(UnsupportedInEnvironmentException::class);
        Clients::sandbox(new FixtureTransport())->payments()->list();
    }

    #[Test]
    public function sessionIdsMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Clients::live(new FixtureTransport())->paymentSessions()->get(0);
    }
}
