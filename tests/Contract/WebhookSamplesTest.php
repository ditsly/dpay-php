<?php

declare(strict_types=1);

namespace DPay\Tests\Contract;

use DPay\Config\Environment;
use DPay\Exceptions\InvalidSignatureException;
use DPay\Exceptions\WebhookEnvironmentMismatchException;
use DPay\Money\Money;
use DPay\Tests\Support\Postman;
use DPay\Tests\Support\Sample;
use DPay\Webhooks\Signature;
use DPay\Webhooks\Verifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The outbound webhook samples of the collection: sign each recorded body
 * exactly as the platform does and prove the SDK's verifier accepts it,
 * refuses a tampered byte, and reads the documented fields.
 */
final class WebhookSamplesTest extends TestCase
{
    /** @return iterable<string, array{Sample}> */
    public static function webhookSamples(): iterable
    {
        $count = 0;
        foreach (Postman::samples() as $sample) {
            if (!$sample->isWebhook()) {
                continue;
            }
            $count++;
            yield $sample->name => [$sample];
        }
        self::assertGreaterThanOrEqual(8, $count, 'the collection documents the payment.* and the checkout.* outbound webhooks');
    }

    #[Test]
    #[DataProvider('webhookSamples')]
    public function verifiesEachRecordedDelivery(Sample $sample): void
    {
        $raw = $sample->body;
        self::assertIsString($raw);
        $headers = $sample->headers;
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertSame('DPAY-Webhooks/1.0', $headers['User-Agent']);
        self::assertSame(strlen($raw), (int) $headers['content-length'], 'ASCII-only wire: content-length equals strlen');

        $ts = $headers['X-DPAY-Timestamp'];
        $secret = Postman::VARIABLES['webhook_secret'];
        $signature = Signature::compute($ts, $raw, $secret);
        $decoded = $sample->bodyJson();
        $live = $decoded['live'];
        self::assertIsBool($live);
        $status = $decoded['status'];
        self::assertIsString($status);
        $amount = $decoded['amount'];
        self::assertTrue(is_int($amount) || is_float($amount));
        $env = $live ? Environment::Live : Environment::Sandbox;

        $verifier = new Verifier($secret, $env, 300, static fn (): int => (int) $ts);
        $event = $verifier->verify($raw, ['X-DPAY-Timestamp' => $ts, 'X-DPAY-Signature' => $signature, 'X-DPAY-Event' => $headers['X-DPAY-Event']]);

        self::assertSame($headers['X-DPAY-Event'], $event->name);
        self::assertSame($status, $event->status());
        self::assertSame(Money::of($amount)->value, $event->amount()?->value);
        self::assertSame($live, $event->live());
        self::assertNotNull($event->occurredAt());
        self::assertNotNull($event->createdAt());
        self::assertSame($live ? 'live' : 'sandbox', explode(':', $event->dedupeKey())[0]);

        if ($event->isCheckout()) {
            // checkout.* (A34): checkout_session_id, reference, currency, metadata, customer, payment|null, occurred_at.
            self::assertTrue(str_starts_with($event->name, 'checkout.'));
            self::assertNull($event->sessionId(), 'checkout.* carries no v1 session_id at the top level');
            self::assertSame($decoded['checkout_session_id'], $event->checkoutSessionId());
            self::assertStringStartsWith($live ? 'cs_01' : 'cs_test_', (string) $event->checkoutSessionId());
            self::assertSame($decoded['reference'], $event->reference());
            self::assertSame('LYD', $event->currency());
            self::assertSame($decoded['metadata'], $event->metadata());
            self::assertSame($decoded['occurred_at'], $event->occurredAt()->format(DATE_ATOM));
            self::assertSame(':'.$event->checkoutSessionId().':'.$event->name, substr($event->dedupeKey(), strlen($live ? 'live' : 'sandbox')));
            if ($event->name === 'checkout.completed') {
                self::assertSame('paid', $status);
                self::assertIsArray($decoded['payment']);
                self::assertSame($decoded['payment'], $event->payment());
                self::assertSame($decoded['payment']['session_id'], $event->payment()['session_id'] ?? null);
                self::assertSame(Money::of($decoded['payment']['amount_charged'])->value, Money::fromApi($event->payment()['amount_charged'] ?? null)?->value);
            } else {
                self::assertContains($event->name, ['checkout.expired', 'checkout.cancelled']);
                self::assertNull($event->payment(), $event->name.' carries payment: null');
                self::assertNull($decoded['payment']);
            }
        } else {
            // payment.* — one terminal event per v1 session (also each attempt of a hosted checkout).
            self::assertTrue($event->isPayment());
            self::assertSame($decoded['session_id'], $event->sessionId());
            self::assertIsArray($decoded['data']);
            self::assertSame($decoded['data']['original_amount'], $event->data()['original_amount']);
            self::assertSame($decoded['pay_method'], $event->payMethod());
            self::assertSame($decoded['tx_id'], $event->txId());
            if (isset($decoded['data']['checkout_session_id'])) {
                // The hosted-checkout attempt: the checkout id, reference and metadata sit under `data`.
                self::assertSame($decoded['data']['checkout_session_id'], $event->checkoutSessionId());
                self::assertSame($decoded['data']['reference'], $event->reference(), 'reference falls back to data.reference');
                self::assertSame($decoded['data']['metadata'], $event->metadata(), 'metadata falls back to data.metadata');
            } else {
                self::assertNull($event->checkoutSessionId());
                self::assertNull($event->reference());
                self::assertSame([], $event->metadata());
            }
        }

        // The wrong environment is refused; a tampered body is refused.
        $other = new Verifier($secret, $live ? Environment::Sandbox : Environment::Live, 300, static fn (): int => (int) $ts);
        try {
            $other->verifyParts($raw, $ts, $signature);
            self::fail('environment mismatch must be refused');
        } catch (WebhookEnvironmentMismatchException) {
        }
        $tampered = str_replace('"status":"'.$status.'"', '"status":"paid"', $raw);
        if ($tampered === $raw) {
            $tampered = $raw.' ';
        }
        $this->expectException(InvalidSignatureException::class);
        $verifier->verifyParts($tampered, $ts, $signature);
    }
}
