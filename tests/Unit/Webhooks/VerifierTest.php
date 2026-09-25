<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Webhooks;

use DPay\Config\Environment;
use DPay\Exceptions\InvalidArgumentException;
use DPay\Exceptions\InvalidSignatureException;
use DPay\Exceptions\StaleTimestampException;
use DPay\Exceptions\WebhookEnvironmentMismatchException;
use DPay\Webhooks\Signature;
use DPay\Webhooks\Verifier;
use DPay\Webhooks\WebhookEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Golden vectors from the platform's `webhook-signature.spec.ts`, reproduced
 * bit-for-bit with PHP's own hash_hmac (the SDK's verifier IS the published
 * sample: `hash_hmac('sha256', $timestamp . '.' . $raw, $secret)` + hash_equals).
 */
final class VerifierTest extends TestCase
{
    private const BODY = '{"event":"payment.paid","live":true,"session_id":124,"status":"paid","amount":10.71,"pay_method":"moamalat","tx_id":"txn_ab","system_reference":null,"network_reference":null,"paid_through":null,"payer_account":"6394****","data":{"url":"https:\/\/x.ly\/a","note":"\u062f\u0641\u0639"},"created_at":"2026-08-29T10:00:00+00:00","paid_at":"2026-08-29T10:05:00+00:00"}';
    private const TS = '1756454400';
    private const SECRET = 'whsec_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const EXPECTED = '8164ad0a9014cf5d59cdfc5d6aef606146cddc7a61b0c18fcad87bdb1aabfea2';
    private const PIVOT_SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PIVOT_EXPECTED = '60bf4404fed25a3e34b0c6518abcfaa2e36d3eb74ebbde8ca87b7360aabb0202';

    private static function verifier(?Environment $env = Environment::Live, int $now = 1756454400 + 10): Verifier
    {
        return new Verifier(self::SECRET, $env, 300, static fn (): int => $now);
    }

    #[Test]
    public function reproducesTheGoldenVectors(): void
    {
        self::assertSame(self::EXPECTED, Signature::compute(self::TS, self::BODY, self::SECRET));
        self::assertSame(self::PIVOT_EXPECTED, Signature::compute(self::TS, self::BODY, self::PIVOT_SECRET));
        // The published PHP sample, verbatim, agrees.
        self::assertSame(self::EXPECTED, hash_hmac('sha256', self::TS.'.'.self::BODY, self::SECRET));
    }

    #[Test]
    public function theSecretNeverLeaksThroughDumpsOrSerialization(): void
    {
        $verifier = self::verifier();
        self::assertStringNotContainsString(self::SECRET, print_r($verifier, true));
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($verifier));
        ob_start();
        var_dump($verifier);
        self::assertStringNotContainsString(self::SECRET, (string) ob_get_clean());
        $this->expectException(\LogicException::class);
        serialize($verifier);
    }

    #[Test]
    public function verifiesHeadersInAnyCaseAndReturnsTheEvent(): void
    {
        $event = self::verifier()->verify(self::BODY, ['x-dpay-timestamp' => self::TS, 'X-DPAY-Signature' => [self::EXPECTED], 'X-DPAY-Event' => 'payment.paid']);
        self::assertTrue($event->is(WebhookEvent::PaymentPaid));
        self::assertTrue($event->live());
        self::assertSame(124, $event->sessionId());
        self::assertSame('10.71', $event->amount()?->value);
        self::assertSame('moamalat', $event->payMethod());
        self::assertSame('دفع', $event->data()['note']);
        self::assertSame('live:124:payment.paid', $event->dedupeKey());
        self::assertSame('2026-08-29T10:05:00+00:00', $event->occurredAt()?->format(DATE_ATOM));
        self::assertSame(self::TS, $event->timestamp);
    }

    #[Test]
    public function verifiesFromServerGlobals(): void
    {
        $event = self::verifier()->verifyGlobals(['HTTP_X_DPAY_TIMESTAMP' => self::TS, 'HTTP_X_DPAY_SIGNATURE' => self::EXPECTED], self::BODY);
        self::assertSame('payment.paid', $event->name);
    }

    #[Test]
    public function refusesABadSignature(): void
    {
        $this->expectException(InvalidSignatureException::class);
        self::verifier()->verifyParts(self::BODY, self::TS, str_repeat('0', 64));
    }

    #[Test]
    public function refusesAMissingSignature(): void
    {
        $this->expectException(InvalidSignatureException::class);
        self::verifier()->verifyParts(self::BODY, self::TS, null);
    }

    #[Test]
    public function refusesAReencodedBody(): void
    {
        $reencoded = (string) json_encode(json_decode(self::BODY, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertNotSame(self::BODY, $reencoded);
        $this->expectException(InvalidSignatureException::class);
        self::verifier()->verifyParts($reencoded, self::TS, self::EXPECTED);
    }

    #[Test]
    public function refusesAStaleTimestamp(): void
    {
        $this->expectException(StaleTimestampException::class);
        self::verifier(now: 1756454400 + 301)->verifyParts(self::BODY, self::TS, self::EXPECTED);
    }

    #[Test]
    public function acceptsTheEdgeOfTheWindowBothWays(): void
    {
        self::assertSame('payment.paid', self::verifier(now: 1756454400 + 300)->verifyParts(self::BODY, self::TS, self::EXPECTED)->name);
        self::assertSame('payment.paid', self::verifier(now: 1756454400 - 300)->verifyParts(self::BODY, self::TS, self::EXPECTED)->name);
    }

    #[Test]
    public function refusesAMissingOrMalformedTimestamp(): void
    {
        try {
            self::verifier()->verifyParts(self::BODY, null, self::EXPECTED);
            self::fail('expected refusal');
        } catch (StaleTimestampException) {
        }
        $this->expectException(StaleTimestampException::class);
        self::verifier()->verifyParts(self::BODY, '2026-08-29T10:00:00Z', self::EXPECTED);
    }

    #[Test]
    public function refusesTheWrongEnvironmentButAcceptsBothWhenUnconfigured(): void
    {
        try {
            self::verifier(Environment::Sandbox)->verifyParts(self::BODY, self::TS, self::EXPECTED);
            self::fail('expected refusal');
        } catch (WebhookEnvironmentMismatchException $e) {
            self::assertStringContainsString('live: true', $e->getMessage());
        }
        self::assertSame('payment.paid', self::verifier(null)->verifyParts(self::BODY, self::TS, self::EXPECTED)->name);
    }

    #[Test]
    public function acceptsARotationGraceWindowOfSeveralSecrets(): void
    {
        $new = 'whsec_'.str_repeat('f', 64);
        $v = new Verifier([$new, self::SECRET], Environment::Live, 300, static fn (): int => 1756454400);
        self::assertSame('payment.paid', $v->verifyParts(self::BODY, self::TS, self::EXPECTED)->name);
        $sigNew = Signature::compute(self::TS, self::BODY, $new);
        self::assertSame('payment.paid', $v->verifyParts(self::BODY, self::TS, strtoupper($sigNew))->name, 'case-insensitive hex');
    }

    #[Test]
    public function requiresASecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Verifier(['', ' ']);
    }

    #[Test]
    public function webhookTestHasNoLiveFlagAndIsNeverAPayment(): void
    {
        $body = '{"event":"webhook.test","test":true,"merchant_id":9001,"merchant_email":"m@example.com","webhook_id":3,"webhook_label":"Production","timestamp":"2026-08-29T10:00:00+00:00","message":"This is a test event from the DPAY dashboard. If you received this, your webhook is configured correctly."}';
        $sig = Signature::compute(self::TS, $body, self::SECRET);
        $event = self::verifier(Environment::Sandbox)->verifyParts($body, self::TS, $sig);
        self::assertTrue($event->isTest());
        self::assertFalse($event->isPayment());
        self::assertNull($event->live());
        self::assertNull($event->sessionId());
        self::assertSame('any:test:3:2026-08-29T10:00:00+00:00:webhook.test', $event->dedupeKey());
    }

    #[Test]
    public function checkoutEventsExposeTheCheckoutFields(): void
    {
        $body = '{"event":"checkout.completed","live":true,"checkout_session_id":"cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2","reference":"10483","status":"paid","amount":125.5,"currency":"LYD","description":"Order #10483","metadata":{"order_id":10483},"customer":{"name":"x","email":null,"phone":"0912345678"},"payment":{"session_id":812,"pay_method":"edfali","tx_id":"txn_a","amount_charged":126.76,"fee_amount":1.255,"paid_at":"2026-09-21T10:04:12+00:00","receipt_url":"https:\/\/dpay.ly\/receipt\/812\/x"},"created_at":"2026-09-21T10:00:00+00:00","occurred_at":"2026-09-21T10:04:12+00:00"}';
        $event = self::verifier()->verifyParts($body, self::TS, Signature::compute(self::TS, $body, self::SECRET));
        self::assertTrue($event->isCheckout());
        self::assertTrue($event->is(WebhookEvent::CheckoutCompleted));
        self::assertSame('cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', $event->checkoutSessionId());
        self::assertSame('10483', $event->reference());
        self::assertSame('125.5', $event->amount()?->value);
        self::assertSame('LYD', $event->currency());
        self::assertSame(10483, $event->metadata()['order_id']);
        self::assertSame(126.76, $event->payment()['amount_charged'] ?? null);
        self::assertSame('live:cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2:checkout.completed', $event->dedupeKey());
        self::assertSame('2026-09-21T10:04:12+00:00', $event->occurredAt()?->format(DATE_ATOM));

        // An attempt's payment.* event names its checkout through data.
        $attempt = '{"event":"payment.paid","live":true,"session_id":812,"status":"paid","amount":126.76,"pay_method":"edfali","tx_id":"txn_a","system_reference":null,"network_reference":null,"paid_through":null,"payer_account":null,"data":{"checkout_session_id":"cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2","reference":"10483"},"created_at":"2026-09-21T10:00:00+00:00","paid_at":"2026-09-21T10:04:12+00:00"}';
        $e2 = self::verifier()->verifyParts($attempt, self::TS, Signature::compute(self::TS, $attempt, self::SECRET));
        self::assertSame('cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', $e2->checkoutSessionId());
        self::assertSame(812, $e2->sessionId());
    }

    #[Test]
    public function unknownEventNamesAreStillDelivered(): void
    {
        $body = '{"event":"payment.something_new","live":false,"session_id":1}';
        $event = self::verifier(Environment::Sandbox)->verifyParts($body, self::TS, Signature::compute(self::TS, $body, self::SECRET));
        self::assertNull($event->type());
        self::assertSame('sandbox:1:payment.something_new', $event->dedupeKey());
    }
}
