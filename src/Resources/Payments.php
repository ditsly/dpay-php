<?php

declare(strict_types=1);

namespace DPay\Resources;

use DPay\Client;
use DPay\Exceptions\InvalidArgumentException;
use DPay\Exceptions\UnsupportedInEnvironmentException;
use DPay\Http\ApiRequest;
use DPay\Models\PaymentPage;
use DPay\Requests\PaymentsFilter;

/**
 * Settled payment records: `GET /api/payments` and `POST /api/payments/filter`.
 * Live only — the sandbox has no payments list. Rows are stored as
 * completed/refunded/voided, so `type=paid` matches nothing (legacy quirk).
 */
final class Payments
{
    public function __construct(private readonly Client $client)
    {
    }

    public function list(int $page = 1, ?int $perPage = null): PaymentPage
    {
        $this->assertLive();
        if ($page < 1 || ($perPage !== null && ($perPage < 1 || $perPage > 100))) {
            throw new InvalidArgumentException('page must be ≥ 1 and per_page 1..100.');
        }
        $query = ['page' => $page];
        if ($perPage !== null) {
            $query['per_page'] = $perPage;
        }
        $json = $this->client->request(new ApiRequest('GET', '/api/payments', null, $query, [], true));

        /** @var array<string, mixed> $json */
        return PaymentPage::fromArray($json);
    }

    public function filter(PaymentsFilter $filter): PaymentPage
    {
        return $this->filterRaw($filter->toArray());
    }

    /**
     * Filter with an untyped body sent as given (advanced use, contract tests).
     *
     * @param array<string, mixed> $body
     */
    public function filterRaw(array $body): PaymentPage
    {
        $this->assertLive();
        $json = $this->client->request(new ApiRequest('POST', '/api/payments/filter', $body, [], [], true));

        /** @var array<string, mixed> $json */
        return PaymentPage::fromArray($json);
    }

    private function assertLive(): void
    {
        if ($this->client->isSandbox()) {
            throw new UnsupportedInEnvironmentException('The payments list has no sandbox twin; read sessions with paymentSessions()->get() instead.');
        }
    }
}
