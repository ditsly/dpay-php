<?php

declare(strict_types=1);

namespace DPay\Models;

/** A cursor page of checkout sessions (`GET /api/v2/checkout-sessions`). */
final class CheckoutSessionPage
{
    /**
     * @param list<CheckoutSession> $data
     * @param array<string, mixed>  $meta
     */
    public function __construct(
        public readonly array $data,
        public readonly int $limit,
        public readonly bool $hasMore,
        public readonly ?string $nextCursor,
        public readonly array $meta,
    ) {
    }

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json): self
    {
        $rows = [];
        foreach ((isset($json['data']) && is_array($json['data'])) ? $json['data'] : [] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = CheckoutSession::fromArray($row);
            }
        }
        $meta = Read::arrayOrNull($json, 'meta') ?? [];

        return new self(
            $rows,
            Read::intOrNull($meta, 'limit') ?? count($rows),
            Read::bool($meta, 'has_more'),
            Read::stringOrNull($meta, 'next_cursor'),
            $meta,
        );
    }
}
