<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Http;

use DPay\Exceptions\InvalidArgumentException;
use DPay\Http\PsrTransport;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The no-redirect / TLS-verify guarantee holds for an INJECTED Guzzle client
 * too: the transport reads its config and refuses a downgrade. Any other
 * PSR-18 client passes through unread and reports `isHardened() === null`.
 */
final class PsrTransportTest extends TestCase
{
    #[Test]
    public function theSdkBuiltGuzzleIsHardened(): void
    {
        $transport = PsrTransport::discover();
        self::assertSame(Guzzle::class, $transport->clientClass());
        self::assertTrue($transport->isHardened());
    }

    #[Test]
    public function refusesAnInjectedGuzzleThatFollowsRedirects(): void
    {
        $factory = new HttpFactory();
        try {
            new PsrTransport(new Guzzle(), $factory, $factory); // Guzzle's default: allow_redirects ON
            self::fail('a redirect-following client must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('allow_redirects', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('allow_redirects');
        new PsrTransport(new Guzzle(['allow_redirects' => ['max' => 5], 'verify' => true]), $factory, $factory);
    }

    #[Test]
    public function refusesAnInjectedGuzzleWithoutTlsVerification(): void
    {
        $factory = new HttpFactory();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('verify');
        new PsrTransport(new Guzzle(['allow_redirects' => false, 'verify' => false]), $factory, $factory);
    }

    #[Test]
    public function acceptsAHardenedGuzzleAndSurfacesARedirectAsAResponse(): void
    {
        $factory = new HttpFactory();
        $mock = new MockHandler([new Response(302, ['Location' => 'https://elsewhere.example'], '')]);
        $guzzle = new Guzzle(['handler' => HandlerStack::create($mock), 'allow_redirects' => false, 'verify' => true, 'http_errors' => false]);
        $transport = new PsrTransport($guzzle, $factory, $factory);
        self::assertTrue($transport->isHardened());

        $response = $transport->send('POST', 'https://dpay.ly/api/payment/sessions/open', ['Content-Type' => 'application/json'], '{"otp":"1234"}', 5.0);
        self::assertSame(302, $response->status, 'the redirect is handed back, never followed');
        self::assertTrue($response->isRedirect());
        self::assertCount(0, $mock, 'exactly one request was sent: the queue is drained');
    }

    #[Test]
    public function aNonGuzzleClientPassesThroughUnreadAndReportsUnknownHardening(): void
    {
        $factory = new HttpFactory();
        $client = new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], '{"status":"ok"}');
            }
        };
        $transport = new PsrTransport($client, $factory, $factory);
        self::assertNull($transport->isHardened(), 'the SDK cannot see a foreign client\'s settings');
        self::assertSame($client::class, $transport->clientClass());
        self::assertSame(200, $transport->send('GET', 'https://dpay.ly/api/health', [], null, 5.0)->status);
    }
}
