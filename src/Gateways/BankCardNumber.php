<?php

declare(strict_types=1);

namespace DPay\Gateways;

use DPay\Exceptions\InvalidArgumentException;

/**
 * `card_number` for MobiCash and the MITF banks. This is a 7/9/10-digit
 * bank card IDENTIFIER, not a 16-digit PAN — outside PCI PAN scope, but still
 * an account identifier: send it once over TLS, never log it, store it
 * masked. MobiCash: exactly 7 digits. MITF: 7 (same bank), 9 (2-digit bank
 * prefix 11/33/66 + 7) or 10 (Trade & Development Bank).
 */
final class BankCardNumber implements \JsonSerializable, \Stringable
{
    private function __construct(public readonly string $value, public readonly string $gateway)
    {
    }

    public static function forMobiCash(string $input): self
    {
        $digits = Digits::clean($input);
        if (preg_match(Rules::MOBICASH_CARD_REGEX, $digits) !== 1) {
            throw new InvalidArgumentException('The card number field format is invalid.');
        }

        return new self($digits, 'mobicash');
    }

    /** @param string $gateway masrefypay | yousrpay | saharapay */
    public static function forMitf(string $gateway, string $input): self
    {
        if (!Rules::isMitf($gateway)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a MITF gateway.', $gateway));
        }
        $digits = Digits::clean($input);
        if (preg_match(Rules::MITF_CARD_REGEX, $digits) !== 1) {
            throw new InvalidArgumentException('The card number field format is invalid.');
        }

        return new self($digits, $gateway);
    }

    /** The 2-digit bank prefix of a 9-digit MITF card, else null. */
    public function bankPrefix(): ?string
    {
        return strlen($this->value) === 9 ? substr($this->value, 0, 2) : null;
    }

    /**
     * A 9-digit card whose prefix is another MITF bank's: the API refuses it
     * with 422 `This card belongs to a different bank…` unless the merchant
     * enabled OnePay on the gateway. Check `cross_bank_enabled` first.
     */
    public function isCrossBank(): bool
    {
        $prefix = $this->bankPrefix();
        if ($prefix === null || !Rules::isMitf($this->gateway)) {
            return false;
        }

        return $prefix !== Rules::MITF_BANK_PREFIXES[$this->gateway];
    }

    /** `•••••12` — the only form to keep on an order. */
    public function masked(): string
    {
        return str_repeat('•', max(0, strlen($this->value) - 2)).substr($this->value, -2);
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
