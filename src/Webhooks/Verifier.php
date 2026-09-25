<?php

declare(strict_types=1);

namespace DPay\Webhooks;

use DPay\Config\Environment;
use DPay\Exceptions\InvalidArgumentException;
use DPay\Exceptions\InvalidSignatureException;
use DPay\Exceptions\StaleTimestampException;
use DPay\Exceptions\WebhookEnvironmentMismatchException;

/**
 * Verifies an inbound DPay webhook and returns the {@see Event}.
 *
 *   $verifier = new Verifier(getenv('DPAY_WEBHOOK_SECRET'), Environment::Live);
 *   $event = $verifier->verifyGlobals();                 // php://input + $_SERVER
 *   // or, in a framework:
 *   $event = $verifier->verify($request->getContent(), $request->headers->all());
 *
 * Rules (the published contract): verify over the UNTOUCHED raw bytes;
 * `hash_equals`; reject |now − X-DPAY-Timestamp| > 300 s; a missing header is
 * a refusal; a `live` flag that disagrees with the configured environment is
 * refused with {@see WebhookEnvironmentMismatchException} (acknowledge 2xx and
 * ignore). Several secrets may be given for a rotation grace window — retries
 * already queued keep signing with the secret captured at dispatch.
 */
final class Verifier
{
    public const DEFAULT_TOLERANCE = 300;

    /** @var list<string> */
    private readonly array $secrets;

    /** @var callable(): int */
    private $clock;

    /**
     * @param string|list<string>   $secrets     the `whsec_…` secret(s), prefix included
     * @param Environment|null      $environment refuse events whose `live` flag disagrees; null accepts both
     * @param int                   $tolerance   seconds; 300 per the DPay docs
     * @param callable(): int|null  $clock       unix-seconds provider (tests)
     */
    public function __construct(
        #[\SensitiveParameter]
        string|array $secrets,
        private readonly ?Environment $environment = null,
        private readonly int $tolerance = self::DEFAULT_TOLERANCE,
        ?callable $clock = null,
    ) {
        $list = is_string($secrets) ? [$secrets] : $secrets;
        $clean = [];
        foreach ($list as $secret) {
            $secret = trim($secret);
            if ($secret === '') {
                continue;
            }
            $clean[] = $secret;
        }
        if ($clean === []) {
            throw new InvalidArgumentException('A webhook secret is required (whsec_… from the dashboard).');
        }
        $this->secrets = $clean;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param string                                   $rawBody exactly the bytes received (php://input, $request->getContent(), get_body())
     * @param array<string, string|list<string>|null>  $headers request headers, any case; list values are joined
     */
    public function verify(string $rawBody, array $headers): Event
    {
        $timestamp = self::headerValue($headers, Signature::TIMESTAMP_HEADER);
        $signature = self::headerValue($headers, Signature::HEADER);

        return $this->verifyParts($rawBody, $timestamp, $signature);
    }

    /** The three values by hand (e.g. from `$_SERVER['HTTP_X_DPAY_TIMESTAMP']`). */
    public function verifyParts(string $rawBody, ?string $timestamp, ?string $signature): Event
    {
        $timestamp = $timestamp === null ? '' : trim($timestamp);
        if ($timestamp === '' || preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            throw new StaleTimestampException('Missing or malformed X-DPAY-Timestamp.');
        }
        if (abs(($this->clock)() - (int) $timestamp) > $this->tolerance) {
            throw new StaleTimestampException(sprintf('X-DPAY-Timestamp is outside the %d-second window.', $this->tolerance));
        }
        if ($signature === null || trim($signature) === '') {
            throw new InvalidSignatureException('Missing X-DPAY-Signature.');
        }
        $ok = false;
        foreach ($this->secrets as $secret) {
            // Every secret is compared so timing does not reveal which one matched.
            if (Signature::matches(Signature::compute($timestamp, $rawBody, $secret), $signature)) {
                $ok = true;
            }
        }
        if (!$ok) {
            throw new InvalidSignatureException('X-DPAY-Signature does not match.');
        }
        $event = Event::fromRawBody($rawBody, $timestamp);
        $live = $event->live();
        if ($this->environment !== null && $live !== null && $live !== $this->environment->webhookLiveFlag()) {
            throw new WebhookEnvironmentMismatchException(sprintf(
                'Received a %s event (live: %s) on a %s endpoint — acknowledge and ignore.',
                $live ? 'live' : 'sandbox',
                $live ? 'true' : 'false',
                $this->environment->value,
            ));
        }

        return $event;
    }

    /**
     * Plain PHP: read `php://input` and the `HTTP_X_DPAY_*` server variables.
     *
     * @param array<string, mixed>|null $server
     */
    public function verifyGlobals(?array $server = null, ?string $rawBody = null): Event
    {
        $server ??= $_SERVER;
        $rawBody ??= (string) file_get_contents('php://input');
        $ts = $server['HTTP_X_DPAY_TIMESTAMP'] ?? null;
        $sig = $server['HTTP_X_DPAY_SIGNATURE'] ?? null;

        return $this->verifyParts($rawBody, is_string($ts) ? $ts : null, is_string($sig) ? $sig : null);
    }

    /** @return array<string, mixed> what var_dump / print_r / dd() show: the secrets masked */
    public function __debugInfo(): array
    {
        return [
            'secrets' => array_map(static fn (string $s): string => substr($s, 0, 6).'…'.substr($s, -4), $this->secrets),
            'environment' => $this->environment?->value,
            'tolerance' => $this->tolerance,
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('DPay\\Webhooks\\Verifier holds the webhook secret and is never serialized. (يحمل سرّ الويب هوك ولا يُسلسل.)');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('DPay\\Webhooks\\Verifier is never serialized.');
    }

    /** @param array<string, string|list<string>|null> $headers */
    private static function headerValue(array $headers, string $name): ?string
    {
        $wanted = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $wanted) {
                continue;
            }
            if (is_array($value)) {
                return $value === [] ? null : (string) $value[0];
            }

            return $value;
        }

        return null;
    }
}
