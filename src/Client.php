<?php

declare(strict_types=1);

namespace DPay;

use DPay\Config\Config;
use DPay\Config\Environment;
use DPay\Exceptions\ConnectionException;
use DPay\Exceptions\UnexpectedResponseException;
use DPay\Http\ApiRequest;
use DPay\Http\ApiResponse;
use DPay\Http\BaseUrlPolicy;
use DPay\Http\ErrorMapper;
use DPay\Http\PsrTransport;
use DPay\Http\TransportInterface;
use DPay\Logging\Redactor;
use DPay\Resources\CheckoutSessions;
use DPay\Resources\Payments;
use DPay\Resources\PaymentSessions;
use DPay\Resources\PayMethods;
use DPay\Resources\Sandbox;
use Psr\Log\LoggerInterface;

/**
 * The DPay API client.
 *
 *   $dpay = Client::live($token);                 // integration token from the dashboard
 *   $dpay = Client::sandbox($sbToken);            // sb_tk_… — same code, simulated banks
 *
 *   $checkout = $dpay->checkoutSessions()->create(...);   // hosted checkout (recommended)
 *   $dpay->payMethods()->list();                          // raw per-method surface
 *   $dpay->paymentSessions()->open(...)->verify(...)->get(...);
 *
 * One client = one token = one environment. Every request is JSON, bearer
 * authenticated, sent to an https DPay host only, never redirected, and
 * retried only when it is safe to (see {@see \DPay\Http\RetryPolicy}).
 */
final class Client
{
    private readonly TransportInterface $transport;
    private ?PayMethods $payMethods = null;
    private ?PaymentSessions $paymentSessions = null;
    private ?Payments $payments = null;
    private ?CheckoutSessions $checkoutSessions = null;
    private ?Sandbox $sandbox = null;

    public function __construct(
        public readonly Config $config,
        ?TransportInterface $transport = null,
    ) {
        $this->transport = $transport ?? PsrTransport::discover($config->timeout, $config->connectTimeout);
    }

    /** @param array{base_url?: string, base_url_policy?: BaseUrlPolicy, timeout?: float, connect_timeout?: float, logger?: LoggerInterface, retry?: Http\RetryPolicy, locale?: string, transport?: TransportInterface} $options */
    public static function live(string $token, array $options = []): self
    {
        return self::build($token, Environment::Live, $options);
    }

    /** @param array{base_url?: string, base_url_policy?: BaseUrlPolicy, timeout?: float, connect_timeout?: float, logger?: LoggerInterface, retry?: Http\RetryPolicy, locale?: string, transport?: TransportInterface} $options */
    public static function sandbox(string $token, array $options = []): self
    {
        return self::build($token, Environment::Sandbox, $options);
    }

    /**
     * Environment from the token prefix (`sb_tk_` → sandbox).
     *
     * @param array{base_url?: string, base_url_policy?: BaseUrlPolicy, timeout?: float, connect_timeout?: float, logger?: LoggerInterface, retry?: Http\RetryPolicy, locale?: string, transport?: TransportInterface} $options
     */
    public static function fromToken(string $token, array $options = []): self
    {
        return self::build($token, Environment::fromToken($token), $options);
    }

    /** @param array{base_url?: string, base_url_policy?: BaseUrlPolicy, timeout?: float, connect_timeout?: float, logger?: LoggerInterface, retry?: Http\RetryPolicy, locale?: string, transport?: TransportInterface} $options */
    private static function build(string $token, Environment $environment, array $options): self
    {
        $config = new Config(
            token: $token,
            environment: $environment,
            baseUrl: $options['base_url'] ?? BaseUrlPolicy::DEFAULT_BASE_URL,
            baseUrlPolicy: $options['base_url_policy'] ?? null,
            timeout: $options['timeout'] ?? Config::DEFAULT_TIMEOUT,
            connectTimeout: $options['connect_timeout'] ?? Config::DEFAULT_CONNECT_TIMEOUT,
            retry: $options['retry'] ?? null,
            logger: $options['logger'] ?? null,
            locale: $options['locale'] ?? 'ar',
        );

        return new self($config, $options['transport'] ?? null);
    }

    public function environment(): Environment
    {
        return $this->config->environment;
    }

    /** @return array<string, mixed> what var_dump / print_r / dd() show: no token */
    public function __debugInfo(): array
    {
        return [
            'config' => $this->config,
            'transport' => $this->transport::class,
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('DPay\\Client holds a live API token and is never serialized — resolve it again from the .env key inside the job. (يحمل مفتاح ربط حي ولا يُسلسل.)');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('DPay\\Client is never serialized.');
    }

    public function isSandbox(): bool
    {
        return $this->config->environment->isSandbox();
    }

    public function payMethods(): PayMethods
    {
        return $this->payMethods ??= new PayMethods($this);
    }

    public function paymentSessions(): PaymentSessions
    {
        return $this->paymentSessions ??= new PaymentSessions($this);
    }

    public function payments(): Payments
    {
        return $this->payments ??= new Payments($this);
    }

    public function checkoutSessions(): CheckoutSessions
    {
        return $this->checkoutSessions ??= new CheckoutSessions($this);
    }

    /**
     * `GET /api/health` — `{status, timestamp, database, cache}`; unauthenticated.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        /** @var array<string, mixed> $json */
        $json = $this->request(new ApiRequest('GET', '/api/health', null, [], [], true, false));

        return $json;
    }

    /** Sandbox-only helpers (magic OTPs, the simulator page). */
    public function sandboxTools(): Sandbox
    {
        return $this->sandbox ??= new Sandbox($this);
    }

    /**
     * Send one API call and return the decoded JSON body of a 2xx answer.
     * Errors become typed exceptions ({@see ErrorMapper}); a redirect or a
     * non-JSON 2xx is an {@see UnexpectedResponseException}.
     *
     * @return array<mixed>
     */
    public function request(ApiRequest $request): array
    {
        $response = $this->send($request);
        $json = $response->json();
        if ($json === null) {
            throw new UnexpectedResponseException(
                sprintf('DPay API answered HTTP %d with a non-JSON body.', $response->status),
                $response->status,
                null,
                $response->rawBody,
            );
        }

        return $json;
    }

    /** Send and return the raw response (2xx only; errors throw). */
    public function send(ApiRequest $request): ApiResponse
    {
        $url = $this->config->baseUrl.$request->path;
        if ($request->query !== []) {
            $url .= '?'.http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => Version::userAgent(),
            'Accept-Language' => $this->config->locale,
        ];
        if ($request->authenticated) {
            $headers['Authorization'] = 'Bearer '.$this->config->token();
        }
        $body = null;
        if ($request->body !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($request->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        foreach ($request->headers as $name => $value) {
            $headers[$name] = $value;
        }

        $retry = $this->config->retry;
        $attempt = 0;
        while (true) {
            $attempt++;
            $started = microtime(true);
            try {
                $response = $this->transport->send($request->method, $url, $headers, $body, $this->config->timeout);
            } catch (ConnectionException $e) {
                $this->log('warning', 'dpay.request.failed', $request, null, $started, ['error' => Redactor::string($e->getMessage()), 'attempt' => $attempt]);
                if ($request->retryable && $retry->shouldRetryConnectionFailure($attempt)) {
                    $retry->sleep($retry->delay($attempt, null));
                    continue;
                }
                throw $e;
            }
            $this->log('debug', 'dpay.request', $request, $response, $started, ['attempt' => $attempt]);

            if ($response->isSuccess()) {
                return $response;
            }
            if ($response->isRedirect()) {
                throw new UnexpectedResponseException(
                    sprintf('DPay API answered a redirect (HTTP %d) — the SDK never follows redirects; check the base URL.', $response->status),
                    $response->status,
                    null,
                    $response->rawBody,
                );
            }
            if ($request->retryable && $retry->shouldRetryStatus($response->status, $attempt)) {
                $retry->sleep($retry->delay($attempt, $response->retryAfterSeconds()));
                continue;
            }
            /** @var array<string, mixed>|null $decoded */
            $decoded = $response->json();
            throw ErrorMapper::map($response->status, $decoded, $response);
        }
    }

    /** @param array<string, mixed> $extra */
    private function log(string $level, string $event, ApiRequest $request, ?ApiResponse $response, float $started, array $extra): void
    {
        $this->config->logger->log($level, $event, array_merge([
            'method' => $request->method,
            'path' => $request->path,
            'environment' => $this->config->environment->value,
            'status' => $response?->status,
            'request_id' => $response?->requestId(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'body' => $request->body === null ? null : Redactor::array($request->body),
        ], $extra));
    }
}
