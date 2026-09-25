<?php

declare(strict_types=1);

namespace DPay\Requests;

use DPay\Exceptions\InvalidArgumentException;

/** `POST /api/payments/filter` body: `from`, `to` (Y-m-d, to ≥ from), `type`. */
final class PaymentsFilter
{
    /** @var list<string> */
    public const TYPES = ['all', 'paid', 'failed', 'pending', 'expired', 'refunded', 'voided'];

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $type = 'all',
        public readonly ?int $perPage = null,
        public readonly ?int $page = null,
    ) {
        foreach ([$from, $to] as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
                throw new InvalidArgumentException('Dates must be Y-m-d.');
            }
        }
        if ($to < $from) {
            throw new InvalidArgumentException('The to field must be a date after or equal to from.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('The selected type is invalid.');
        }
        if ($perPage !== null && ($perPage < 1 || $perPage > 100)) {
            throw new InvalidArgumentException('per_page must be 1..100.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $body = ['from' => $this->from, 'to' => $this->to, 'type' => $this->type];
        if ($this->perPage !== null) {
            $body['per_page'] = $this->perPage;
        }
        if ($this->page !== null) {
            $body['page'] = $this->page;
        }

        return $body;
    }
}
