<?php

declare(strict_types=1);

namespace DPay\Config;

use DPay\Exceptions\InvalidArgumentException;
use DPay\Http\BaseUrlPolicy;
use DPay\Http\RetryPolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Immutable client configuration. Build it through {@see \DPay\Client::live()}
 * / {@see \DPay\Client::sandbox()} or directly for full control.
 *
 * The token is a live credential: it is readable only through
 * {@see Config::token()}, masked by `var_dump`/`print_r`/`dd()`
 * (`__debugInfo`), absent from `json_encode` (private), and the object
 * refuses `serialize()` — a queued job or a cache entry must hold the
 * .env key, never the client.
 */
final class Config
{
    public const DEFAULT_TIMEOUT = 15.0;
    public const DEFAULT_CONNECT_TIMEOUT = 5.0;

    public readonly string $baseUrl;
    public readonly Environment $environment;
    public readonly LoggerInterface $logger;
    public readonly RetryPolicy $retry;
    public readonly BaseUrlPolicy $baseUrlPolicy;

    private readonly string $token;

    /**
     * @param string             $token   `role:api` integration token (live) or `sb_tk_…` (sandbox)
     * @param float              $timeout total seconds per attempt (the API answers well inside 15 s)
     */
    public function __construct(
        #[\SensitiveParameter]
        string $token,
        ?Environment $environment = null,
        string $baseUrl = BaseUrlPolicy::DEFAULT_BASE_URL,
        ?BaseUrlPolicy $baseUrlPolicy = null,
        public readonly float $timeout = self::DEFAULT_TIMEOUT,
        public readonly float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        ?RetryPolicy $retry = null,
        ?LoggerInterface $logger = null,
        public readonly string $locale = 'ar',
    ) {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('An API token is required.');
        }
        if (preg_match('/\s/', $token) === 1) {
            throw new InvalidArgumentException('The API token contains whitespace — paste it exactly as the dashboard showed it.');
        }
        $this->token = $token;
        $this->environment = $environment ?? Environment::fromToken($token);
        $this->environment->assertTokenMatches($token);
        $this->baseUrlPolicy = $baseUrlPolicy ?? BaseUrlPolicy::dpayOnly();
        $this->baseUrl = $this->baseUrlPolicy->normalize($baseUrl);
        $this->retry = $retry ?? RetryPolicy::default();
        $this->logger = $logger ?? new NullLogger();
        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new InvalidArgumentException('Timeouts must be positive.');
        }
        if (!in_array($locale, ['ar', 'en'], true)) {
            throw new InvalidArgumentException('Locale must be "ar" or "en".');
        }
    }

    /** The bearer, for the transport. Never log it; {@see Config::maskedToken()} for output. */
    public function token(): string
    {
        return $this->token;
    }

    /** `12|abcd…9f3e` — enough to tell tokens apart in a log, never enough to use one. */
    public function maskedToken(): string
    {
        return self::mask($this->token);
    }

    public static function mask(string $secret): string
    {
        if (strlen($secret) <= 10) {
            return str_repeat('•', strlen($secret));
        }

        return substr($secret, 0, 6).'…'.substr($secret, -4);
    }

    /** @return array<string, mixed> what var_dump / print_r / dd() show: the token masked */
    public function __debugInfo(): array
    {
        return [
            'token' => $this->maskedToken(),
            'environment' => $this->environment->value,
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'locale' => $this->locale,
            'retry' => $this->retry,
            'baseUrlPolicy' => $this->baseUrlPolicy,
            'logger' => $this->logger::class,
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('DPay\\Config holds a live API token and is never serialized — store the .env key name, not the client. (تحتوي على مفتاح ربط حي ولا تُسلسل.)');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('DPay\\Config is never serialized.');
    }
}
