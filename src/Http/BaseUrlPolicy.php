<?php

declare(strict_types=1);

namespace DPay\Http;

use DPay\Exceptions\InvalidArgumentException;

/**
 * Where the SDK is allowed to send a merchant's token.
 *
 * HTTPS only, and only DPay hosts (`dpay.ly`, `next.dpay.ly`, `pg.dits.ly`)
 * unless the integrator opts into a custom base URL explicitly — a typo'd or
 * injected base URL must never leak a bearer to a third party. Redirects are
 * never followed and TLS is always verified (see {@see PsrTransport}).
 */
final class BaseUrlPolicy
{
    public const DEFAULT_BASE_URL = 'https://dpay.ly';

    /** @var list<string> */
    public const DPAY_HOSTS = ['dpay.ly', 'next.dpay.ly', 'pg.dits.ly'];

    /**
     * The developer's own machine, by every name Docker gives it: the loopback
     * names, plus `host.docker.internal` / `host.lima.internal` — what a
     * container calls the host it runs on (Docker Desktop, Colima, Podman).
     * Only these may be spoken to over plain `http://`, and only under the
     * explicit `allowHttpLocalhost` opt-in: none of them can be a third party.
     *
     * @var list<string>
     */
    public const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]', 'host.docker.internal', 'host.lima.internal'];

    /** @param list<string> $allowedHosts */
    private function __construct(
        private readonly array $allowedHosts,
        private readonly bool $allowHttpLocalhost,
    ) {
    }

    /** Production policy: the DPay hosts only. */
    public static function dpayOnly(): self
    {
        return new self(self::DPAY_HOSTS, false);
    }

    /**
     * Also accept the given hosts (exact match, lower-case). `http://` stays
     * refused except for the {@see LOCAL_HOSTS} (`localhost`, `127.0.0.1`,
     * `host.docker.internal`…) when `$allowHttpLocalhost` is set, for a local
     * API stack — on the machine itself or reached from a container.
     *
     * @param list<string> $extraHosts
     */
    public static function allowing(array $extraHosts, bool $allowHttpLocalhost = false): self
    {
        $hosts = self::DPAY_HOSTS;
        foreach ($extraHosts as $host) {
            $hosts[] = strtolower(trim($host));
        }

        return new self(array_values(array_unique($hosts)), $allowHttpLocalhost);
    }

    /** Validate and normalise (no trailing slash, no path/query/fragment). */
    public function normalize(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');
        $parts = parse_url($trimmed);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException(sprintf('Base URL "%s" is not an absolute URL.', $baseUrl));
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Base URL must not carry credentials.');
        }
        if (isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '')) {
            throw new InvalidArgumentException('Base URL must be an origin only (scheme + host [+ port]); paths start at /api.');
        }
        $isLocal = in_array($host, self::LOCAL_HOSTS, true);
        if ($scheme !== 'https') {
            if (!($scheme === 'http' && $isLocal && $this->allowHttpLocalhost)) {
                throw new InvalidArgumentException('Base URL must use https://.');
            }
        }
        if (!in_array($host, $this->allowedHosts, true) && !($isLocal && $this->allowHttpLocalhost)) {
            throw new InvalidArgumentException(sprintf(
                'Host "%s" is not a DPay host (%s). Use BaseUrlPolicy::allowing([...]) to opt in explicitly.',
                $host,
                implode(', ', $this->allowedHosts),
            ));
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    /** Is `$url` (a payment_link, a hosted page) on a host this policy trusts? */
    public function trusts(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        $isLocal = in_array($host, self::LOCAL_HOSTS, true);
        if (strtolower($parts['scheme']) !== 'https' && !($isLocal && $this->allowHttpLocalhost)) {
            return false;
        }

        return in_array($host, $this->allowedHosts, true) || ($isLocal && $this->allowHttpLocalhost);
    }
}
