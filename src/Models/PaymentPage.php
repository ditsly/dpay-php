<?php

declare(strict_types=1);

namespace DPay\Models;

/**
 * A Laravel length-aware page of {@see PaymentRecord} rows
 * (`GET /api/payments`, `POST /api/payments/filter`).
 */
final class PaymentPage
{
    /**
     * @param list<PaymentRecord>  $data
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly array $data,
        public readonly int $currentPage,
        public readonly int $lastPage,
        public readonly int $perPage,
        public readonly int $total,
        public readonly ?int $from,
        public readonly ?int $to,
        public readonly ?string $nextPageUrl,
        public readonly ?string $prevPageUrl,
        public readonly array $raw,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $rows = [];
        foreach ((isset($a['data']) && is_array($a['data'])) ? $a['data'] : [] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = PaymentRecord::fromArray($row);
            }
        }

        return new self(
            data: $rows,
            currentPage: Read::int($a, 'current_page'),
            lastPage: Read::int($a, 'last_page'),
            perPage: Read::int($a, 'per_page'),
            total: Read::int($a, 'total'),
            from: Read::intOrNull($a, 'from'),
            to: Read::intOrNull($a, 'to'),
            nextPageUrl: Read::stringOrNull($a, 'next_page_url'),
            prevPageUrl: Read::stringOrNull($a, 'prev_page_url'),
            raw: $a,
        );
    }

    public function hasMore(): bool
    {
        return $this->nextPageUrl !== null;
    }

    public function count(): int
    {
        return count($this->data);
    }
}
