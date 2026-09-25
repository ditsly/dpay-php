<?php

declare(strict_types=1);

namespace DPay\Http;

use DPay\Exceptions\ConnectionException;
use DPay\Exceptions\InvalidArgumentException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 transport. Inject your own client (Guzzle, Symfony, Laravel's) or
 * let {@see PsrTransport::discover()} find one.
 *
 * When Guzzle is installed and nothing is injected, the SDK builds it with
 * TLS verification ON, redirects OFF, `http_errors` off and the configured
 * timeouts — the settings a merchant token deserves. An INJECTED Guzzle
 * client is checked for the same two settings and refused when it would
 * follow redirects or skip TLS verification (a 307/308 re-sends the POST
 * body — customer_mobile, card_number, otp — wherever `Location` points).
 * With any other PSR-18 client the SDK cannot read the configuration:
 * build it with redirects off and TLS on yourself (Symfony:
 * `new Psr18Client(HttpClient::create(['max_redirects' => 0]))`), and
 * {@see PsrTransport::isHardened()} answers null so `dpay:doctor` can say
 * so. The SDK refuses to read a redirect either way.
 */
final class PsrTransport implements TransportInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
        self::assertNotDowngraded($client);
    }

    /** The PSR-18 client class in use (for `dpay:doctor` and logs). */
    public function clientClass(): string
    {
        return $this->client::class;
    }

    /**
     * True when the client is a Guzzle client whose config the SDK could
     * read and verify (redirects off, TLS on); null for any other PSR-18
     * client, whose settings the SDK cannot see.
     */
    public function isHardened(): ?bool
    {
        return self::guzzleConfig($this->client) === null ? null : true;
    }

    /**
     * Refuse a Guzzle client that would follow redirects or skip TLS
     * verification. Only Guzzle exposes its config (`getConfig()`, Guzzle 6/7);
     * any other client passes through unread.
     *
     * @throws InvalidArgumentException
     */
    public static function assertNotDowngraded(ClientInterface $client): void
    {
        $config = self::guzzleConfig($client);
        if ($config === null) {
            return;
        }
        $redirects = $config['allow_redirects'] ?? true; // Guzzle's default is ON
        if ($redirects !== false && $redirects !== []) {
            throw new InvalidArgumentException('The injected Guzzle client follows redirects (allow_redirects) — build it with "allow_redirects" => false: a 307/308 would re-send the payment body wherever Location points. (عميل Guzzle المُمرَّر يتبع التحويلات؛ عطّل allow_redirects.)');
        }
        $verify = $config['verify'] ?? true;
        if ($verify === false) {
            throw new InvalidArgumentException('The injected Guzzle client skips TLS verification ("verify" => false) — the SDK never sends a merchant token over an unverified connection. (عميل Guzzle المُمرَّر يتجاوز التحقق من TLS.)');
        }
    }

    /** @return array<string, mixed>|null the Guzzle client's config, or null when not a Guzzle client */
    private static function guzzleConfig(ClientInterface $client): ?array
    {
        if (!$client instanceof \GuzzleHttp\ClientInterface || !method_exists($client, 'getConfig')) {
            return null;
        }
        /** @var mixed $config */
        $config = $client->getConfig();
        if (!is_array($config)) {
            return null;
        }
        $out = [];
        foreach ($config as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /** Guzzle (hardened) when present, otherwise whatever php-http/discovery finds. */
    public static function discover(float $timeout = 15.0, float $connectTimeout = 5.0): self
    {
        $client = class_exists(\GuzzleHttp\Client::class)
            ? self::hardenedGuzzle($timeout, $connectTimeout)
            : Psr18ClientDiscovery::find();

        return new self(
            $client,
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
        );
    }

    /** @return ClientInterface a Guzzle client with the DPay policy applied */
    public static function hardenedGuzzle(float $timeout = 15.0, float $connectTimeout = 5.0): ClientInterface
    {
        $client = new \GuzzleHttp\Client([
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => true,
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
        ]);

        return $client;
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): ApiResponse
    {
        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ConnectionException('DPay API unreachable: '.$e->getMessage(), 0, $e);
        }
        $flat = [];
        foreach ($response->getHeaders() as $name => $values) {
            $flat[strtolower((string) $name)] = implode(', ', $values);
        }

        return new ApiResponse($response->getStatusCode(), $flat, (string) $response->getBody());
    }
}
