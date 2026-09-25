<?php

declare(strict_types=1);

namespace DPay\Resources;

use DPay\Client;
use DPay\Exceptions\InvalidArgumentException;
use DPay\Http\ApiRequest;
use DPay\Models\CheckoutSession;
use DPay\Models\CheckoutSessionPage;
use DPay\Models\CheckoutStatus;
use DPay\Models\Read;
use DPay\Requests\CreateCheckoutSessionRequest;

/**
 * Hosted checkout — `/api/v2/checkout-sessions` (amendment A34, API.md §2.17).
 *
 * The recommended integration. Four steps, no OTPs, no card numbers:
 *
 *   1. create()  → store `id`, `url`, `expiresAt` on the order; redirect to `url`
 *   2. the customer pays on DPay's hosted page (every method the merchant offers)
 *   3. on return: get($id) — the query string is a hint, this is the truth
 *   4. consume `checkout.completed` webhooks and reconcile pending orders every 5 minutes with get()
 *
 * The same endpoints serve live and sandbox; the token decides. Ids are
 * `cs_…` (live) / `cs_test_…` (sandbox); an id of the other environment is a 404.
 */
final class CheckoutSessions
{
    public const IDEMPOTENCY_KEY_MAX = 64;

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * `POST /api/v2/checkout-sessions` (201). With an `Idempotency-Key`
     * (≤ 64 chars, e.g. {@see \DPay\Idempotency\KeyFactory::forCheckout()}):
     * same key + same body ⇒ 200 replay of the ORIGINAL (same id, same url,
     * `idempotentReplay` true); same key + different body ⇒ 409
     * {@see \DPay\Exceptions\IdempotencyException}. Works in sandbox too.
     */
    public function create(CreateCheckoutSessionRequest $request, ?string $idempotencyKey = null): CheckoutSession
    {
        return $this->createRaw($request->toArray(), $idempotencyKey);
    }

    /**
     * Create with an untyped body — sent to the API exactly as given, so
     * the API's own 422 answers the way it would for any client (advanced
     * integrations and the contract tests). Prefer {@see create()}.
     *
     * @param array<string, mixed> $body
     */
    public function createRaw(array $body, ?string $idempotencyKey = null): CheckoutSession
    {
        $headers = [];
        $retryable = false;
        if ($idempotencyKey !== null) {
            self::assertIdempotencyKey($idempotencyKey);
            $headers['Idempotency-Key'] = $idempotencyKey;
            $retryable = true;
        }
        $json = $this->client->request(new ApiRequest('POST', '/api/v2/checkout-sessions', $body, [], $headers, $retryable));
        /** @var array<string, mixed> $json */
        $meta = Read::arrayOrNull($json, 'meta') ?? [];

        return CheckoutSession::fromArray(Read::array($json, 'data'), Read::bool($meta, 'idempotent_replay'));
    }

    /**
     * The one Idempotency-Key rule for BOTH surfaces: 1..64 characters on one
     * line without whitespace. v2 validates the header; the legacy v1 open
     * stores it in a `varchar(64)` column AFTER the bank leg has run, so a
     * longer key there would send the customer an OTP and then answer 500.
     */
    public static function assertIdempotencyKey(string $key): void
    {
        if ($key === '' || strlen($key) > self::IDEMPOTENCY_KEY_MAX || preg_match('/\s/', $key) === 1) {
            throw new InvalidArgumentException(sprintf('Idempotency-Key must be 1..%d characters without whitespace.', self::IDEMPOTENCY_KEY_MAX));
        }
    }

    /** `GET /api/v2/checkout-sessions/{id}` — the authoritative status (lazy-expires an overdue `open`). */
    public function get(string $id): CheckoutSession
    {
        self::assertId($id);
        $json = $this->client->request(new ApiRequest('GET', '/api/v2/checkout-sessions/'.rawurlencode($id), retryable: true));

        /** @var array<string, mixed> $json */
        return CheckoutSession::fromArray(Read::array($json, 'data'));
    }

    /**
     * `GET /api/v2/checkout-sessions?status=&reference=&cursor=&limit=` — newest first.
     *
     * @param int $limit 1..100
     */
    public function list(?CheckoutStatus $status = null, ?string $reference = null, ?string $cursor = null, int $limit = 25): CheckoutSessionPage
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be 1..100.');
        }
        $query = ['limit' => $limit];
        if ($status !== null) {
            $query['status'] = $status->value;
        }
        if ($reference !== null && $reference !== '') {
            $query['reference'] = $reference;
        }
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        $json = $this->client->request(new ApiRequest('GET', '/api/v2/checkout-sessions', null, $query, [], true));

        /** @var array<string, mixed> $json */
        return CheckoutSessionPage::fromArray($json);
    }

    /**
     * `POST /api/v2/checkout-sessions/{id}/cancel` → `cancelled`, every
     * pending attempt expired, one `checkout.cancelled`. 409
     * {@see \DPay\Exceptions\CheckoutNotOpenException} when already
     * paid/expired/cancelled — read the session and act on its status.
     */
    public function cancel(string $id): CheckoutSession
    {
        self::assertId($id);
        $json = $this->client->request(new ApiRequest('POST', '/api/v2/checkout-sessions/'.rawurlencode($id).'/cancel', null, [], [], false));

        /** @var array<string, mixed> $json */
        return CheckoutSession::fromArray(Read::array($json, 'data'));
    }

    private static function assertId(string $id): void
    {
        if (preg_match('/^cs_(test_)?[0-9A-HJKMNP-TV-Z]{26}$/i', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a checkout session id (cs_… / cs_test_… + 26 characters).', $id));
        }
    }
}
