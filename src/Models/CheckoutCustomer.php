<?php

declare(strict_types=1);

namespace DPay\Models;

/** `customer: {name, email, phone}` on a checkout session — every field optional. */
final class CheckoutCustomer
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
    ) {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(Read::stringOrNull($a, 'name'), Read::stringOrNull($a, 'email'), Read::stringOrNull($a, 'phone'));
    }

    /** @return array<string, string> only the fields that are set */
    public function toArray(): array
    {
        return array_filter(['name' => $this->name, 'email' => $this->email, 'phone' => $this->phone], static fn (?string $v): bool => $v !== null);
    }
}
