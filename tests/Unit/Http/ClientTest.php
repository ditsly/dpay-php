<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Http;

use DPay\Client;
use DPay\Exceptions\ConnectionException;
use DPay\Exceptions\RateLimitException;
use DPay\Exceptions\UnexpectedResponseException;
use DPay\Http\ApiRequest;
use DPay\Http\RetryPolicy;
use DPay\Requests\OpenSessionRequest;
use DPay\Tests\Support\Clients;
use DPay\Tests\Support\FixtureTransport;
use DPay\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ClientTest extends TestCase
{
    #[Test]
    public function sendsBearerJsonAndUserAgentToTheDpayHost(): void
    {
        $t = (new FixtureTransport())->enqueueJson(200, ['data' => []]);
        $client = Clients::live($t);
        $client->payMethods()->list();

        $req = $t->last();
        self::assertSame('GET', $req['method']);
        self::assertSame('https://dpay.ly/api/pay-methods', $req['url']);
        self::assertSame('Bearer '.Clients::LIVE_TOKEN, $req['headers']['Authorization']);
        self::assertSame('application/json', $req['headers']['Accept']);
        self::assertSame(Version::userAgent(), $req['headers']['User-Agent']);
        self::assertStringStartsWith('dpay-php/1.', $req['headers']['User-Agent']);
        self::assertSame(15.0, $req['timeout']);
        self::assertNull($req['body']);
    }

    #[Test]
    public function sandboxClientTalksToTheSandboxPaths(): void
    {
        $t = (new FixtureTransport())->enqueueJson(200, []);
        Clients::sandbox($t)->payMethods()->list();
        self::assertSame('https://dpay.ly/api/sandbox/pay-methods', $t->last()['url']);
        self::assertSame('Bearer '.Clients::SANDBOX_TOKEN, $t->last()['headers']['Authorization']);
    }

    #[Test]
    public function customBaseUrlIsHonoured(): void
    {
        $t = (new FixtureTransport())->enqueueJson(200, ['data' => []]);
        Client::live(Clients::LIVE_TOKEN, ['transport' => $t, 'base_url' => 'https://next.dpay.ly/'])->payMethods()->list();
        self::assertSame('https://next.dpay.ly/api/pay-methods', $t->last()['url']);
    }

    #[Test]
    public function neverFollowsRedirects(): void
    {
        $t = (new FixtureTransport())->enqueue(302, '', ['location' => 'https://elsewhere.example']);
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('redirect');
        Clients::live($t)->payMethods()->list();
    }

    #[Test]
    public function nonJsonSuccessIsUnexpected(): void
    {
        $t = (new FixtureTransport())->enqueue(200, '<html>', ['content-type' => 'text/html']);
        $this->expectException(UnexpectedResponseException::class);
        Clients::live($t)->payMethods()->list();
    }

    #[Test]
    public function retriesGetsOn503And429HonouringRetryAfter(): void
    {
        $slept = [];
        $retry = new RetryPolicy(maxAttempts: 3, sleeper: static function (float $s) use (&$slept): void {
            $slept[] = $s;
        });
        $t = (new FixtureTransport())
            ->enqueueJson(503, ['message' => 'The payment provider is temporarily unavailable. Please try again in a few minutes.'])
            ->enqueueJson(429, ['message' => 'Too Many Attempts.', 'status' => 429], ['retry-after' => '2'])
            ->enqueueJson(200, ['session_id' => 2, 'status' => 'pending', 'amount' => 76.26, 'pay_method' => 'edfali', 'tx_id' => 'txn_x', 'expired_at' => '2026-09-18T10:15:00.000000Z', 'data' => null]);
        $session = Clients::live($t, $retry)->paymentSessions()->get(2);
        self::assertSame(2, $session->sessionId);
        self::assertSame(3, $t->count());
        self::assertCount(2, $slept);
        self::assertSame(2.0, $slept[1], 'Retry-After wins');
    }

    #[Test]
    public function givesUpAfterMaxAttemptsWithTheTypedException(): void
    {
        $retry = new RetryPolicy(maxAttempts: 2, sleeper: static function (float $s): void {
        });
        $t = (new FixtureTransport())
            ->enqueueJson(429, ['message' => 'Too Many Attempts.', 'status' => 429], ['retry-after' => '60'])
            ->enqueueJson(429, ['message' => 'Too Many Attempts.', 'status' => 429], ['retry-after' => '60']);
        try {
            Clients::live($t, $retry)->paymentSessions()->get(2);
            self::fail('expected 429');
        } catch (RateLimitException $e) {
            self::assertSame(60, $e->retryAfter);
            self::assertSame(2, $t->count());
        }
    }

    #[Test]
    public function neverRetriesAVerify(): void
    {
        $retry = new RetryPolicy(maxAttempts: 3, sleeper: static function (float $s): void {
        });
        $t = (new FixtureTransport())->enqueueJson(429, ['message' => 'Too Many Attempts.', 'status' => 429], ['retry-after' => '60']);
        try {
            Clients::live($t, $retry)->paymentSessions()->verify(2, '1234');
            self::fail('expected 429');
        } catch (RateLimitException) {
            self::assertSame(1, $t->count(), 'an OTP verify is sent exactly once');
        }
    }

    #[Test]
    public function retriesAnOpenOnlyWithAnIdempotencyKeyAndReusesIt(): void
    {
        $retry = new RetryPolicy(maxAttempts: 3, sleeper: static function (float $s): void {
        });
        $body = ['message' => 'Payment session created successfully', 'session_id' => 9, 'status' => 'pending', 'amount' => 100, 'currency' => 'LYD', 'fee' => 2.5, 'fee_amount' => 2.5, 'total' => 102.5, 'pay_method' => 'moamalat', 'expired_at' => '2026-09-18T10:15:00.000000Z', 'data' => null, 'payment_link' => 'https://pg.dits.ly/moamalat-pay/9'];

        $t = (new FixtureTransport())->enqueueFailure()->enqueueJson(200, $body);
        $opened = Clients::live($t, $retry)->paymentSessions()->open(OpenSessionRequest::moamalat('100'), 'order-10486');
        self::assertSame(9, $opened->sessionId);
        self::assertSame(2, $t->count());
        self::assertSame('order-10486', $t->requests[0]['headers']['Idempotency-Key']);
        self::assertSame('order-10486', $t->requests[1]['headers']['Idempotency-Key']);

        $t2 = (new FixtureTransport())->enqueueFailure();
        $this->expectException(ConnectionException::class);
        Clients::live($t2, $retry)->paymentSessions()->open(OpenSessionRequest::moamalat('100'));
    }

    #[Test]
    public function logsWithRedactionAndNeverTheBearer(): void
    {
        $lines = [];
        $logger = new class ($lines) extends AbstractLogger {
            /** @param list<array<string, mixed>> $lines */
            public function __construct(private array &$lines)
            {
            }

            // An untyped $message: the one signature valid against psr/log 1.1, 2 and 3 (the SDK allows all three).
            /** @param string|\Stringable $message */
            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }

            public function count(): int
            {
                return count($this->lines);
            }
        };
        $t = (new FixtureTransport())->enqueueJson(422, ['message' => 'EDFali PIN is not correct'], ['x-request-id' => 'req-1']);
        $client = Client::live(Clients::LIVE_TOKEN, ['transport' => $t, 'logger' => $logger, 'retry' => RetryPolicy::none()]);
        try {
            $client->paymentSessions()->verify(2, '9999');
        } catch (\DPay\Exceptions\OtpRejectedException) {
        }
        self::assertCount(1, $lines);
        self::assertSame(1, $logger->count());
        $encoded = json_encode($lines);
        self::assertIsString($encoded);
        self::assertStringNotContainsString(Clients::LIVE_TOKEN, $encoded);
        self::assertStringNotContainsString('9999', $encoded);
        self::assertSame('[redacted]', $lines[0]['context']['body']['otp']);
        self::assertSame('req-1', $lines[0]['context']['request_id']);
        self::assertSame(422, $lines[0]['context']['status']);
    }

    #[Test]
    public function queryStringsAreEncoded(): void
    {
        $t = (new FixtureTransport())->enqueueJson(200, ['data' => [], 'meta' => ['limit' => 25, 'has_more' => false, 'next_cursor' => null]]);
        Clients::live($t)->checkoutSessions()->list(reference: 'ORD 1&2', cursor: 'aWQ6MTIz', limit: 10);
        self::assertSame('https://dpay.ly/api/v2/checkout-sessions?limit=10&reference=ORD%201%262&cursor=aWQ6MTIz', $t->last()['url']);
    }

    #[Test]
    public function apiRequestIsImmutable(): void
    {
        $r = new ApiRequest('GET', '/api/x');
        $r2 = $r->withHeader('X', '1')->withRetryable(true);
        self::assertSame([], $r->headers);
        self::assertSame(['X' => '1'], $r2->headers);
        self::assertTrue($r2->retryable);
    }
}
