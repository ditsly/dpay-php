<?php

declare(strict_types=1);

namespace DPay\Gateways;

use DPay\Exceptions\InvalidArgumentException;

/**
 * `customer_mobile` for EDFali and Sadad: `^0?9[1-9]\d{7}$` — a Libyan
 * mobile number with or without the leading 0. The value is sent as a JSON
 * STRING exactly as normalised here (Arabic-Indic digits folded, separators
 * removed, `+218`/`00218` country code stripped to the local form).
 */
final class LibyanMobile implements \JsonSerializable, \Stringable
{
    private function __construct(public readonly string $value)
    {
    }

    public static function of(string $input): self
    {
        $digits = Digits::clean($input);
        if (str_starts_with($digits, '+218')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '00218')) {
            $digits = '0'.substr($digits, 5);
        } elseif (str_starts_with($digits, '218') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 3);
        }
        if (!self::isValid($digits)) {
            throw new InvalidArgumentException('The customer mobile field format is invalid.');
        }

        return new self($digits);
    }

    public static function isValid(string $digits): bool
    {
        return preg_match(Rules::CUSTOMER_MOBILE_REGEX, $digits) === 1;
    }

    /** `09•••••78` — safe to show the customer or store on an order. */
    public function masked(): string
    {
        $n = strlen($this->value);

        return substr($this->value, 0, 2).str_repeat('•', max(0, $n - 4)).substr($this->value, -2);
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
