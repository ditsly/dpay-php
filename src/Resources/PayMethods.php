<?php

declare(strict_types=1);

namespace DPay\Resources;

use DPay\Client;
use DPay\Exceptions\UnexpectedResponseException;
use DPay\Http\ApiRequest;
use DPay\Models\PayMethod;

/**
 * `GET /api/pay-methods` (live: `{data: [...]}`) and
 * `GET /api/sandbox/pay-methods` (bare array). Cache the answer for a few
 * minutes; it drives which tiles you show and the min/max you check BEFORE
 * opening a session.
 */
final class PayMethods
{
    public function __construct(private readonly Client $client)
    {
    }

    /** @return list<PayMethod> every row, usable or not */
    public function list(): array
    {
        $env = $this->client->environment();
        $json = $this->client->request(new ApiRequest('GET', $env->pathPrefix().'/pay-methods', retryable: true));
        $rows = $env->payMethodsAreEnveloped() ? ($json['data'] ?? null) : $json;
        if (!is_array($rows)) {
            throw new UnexpectedResponseException('pay-methods answer has no rows.', 200, null, null);
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $out[] = PayMethod::fromArray($row);
            }
        }

        return $out;
    }

    /** @return list<PayMethod> only the methods a customer can actually pay with here */
    public function usable(): array
    {
        $env = $this->client->environment();

        return array_values(array_filter(
            $this->list(),
            static fn (PayMethod $m): bool => $m->isUsable() && $env->supportsSlug($m->slug),
        ));
    }

    public function find(string $slug): ?PayMethod
    {
        foreach ($this->list() as $method) {
            if ($method->slug === $slug) {
                return $method;
            }
        }

        return null;
    }
}
