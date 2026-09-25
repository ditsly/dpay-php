<?php

declare(strict_types=1);

namespace DPay\Requests;

use DPay\Exceptions\InvalidArgumentException;
use DPay\Gateways\BankCardNumber;
use DPay\Gateways\Digits;
use DPay\Gateways\Gateway;
use DPay\Gateways\LibyanMobile;
use DPay\Gateways\Rules;
use DPay\Money\Money;

/**
 * The typed body of `POST /api/payment/sessions/open`, one named constructor
 * per gateway so the required fields cannot be forgotten and the value
 * objects validate them locally with the API's own messages.
 *
 *   OpenSessionRequest::edfali('75.50', '0912345678')
 *   OpenSessionRequest::sadad('90', '0912345678', 1990)
 *   OpenSessionRequest::mobicash('60', '1234567')
 *   OpenSessionRequest::mitf(Gateway::YousrPay, '40', '331234567')
 *   OpenSessionRequest::moamalat('250', returnUrl: 'https://shop.ly/return?order=10482')
 *   OpenSessionRequest::mpgs('120')   // USD on this path — read the README first
 *
 * then `->withData(['order_id' => 10482])->withDescription('Order #10482')`.
 */
final class OpenSessionRequest
{
    /** @var array<string, mixed> */
    private array $data = [];
    private ?string $returnUrl = null;
    private ?string $description = null;

    /** @param array<string, mixed> $fields gateway-specific top-level fields, already validated */
    private function __construct(
        public readonly Gateway $gateway,
        public readonly Money $amount,
        private readonly array $fields,
    ) {
        if (!$amount->isPositive() || $amount->isLessThan(Money::of('0.01'))) {
            throw new InvalidArgumentException('The amount field must be at least 0.01.');
        }
    }

    public static function edfali(string|int|float $amount, string|LibyanMobile $customerMobile): self
    {
        $mobile = $customerMobile instanceof LibyanMobile ? $customerMobile : LibyanMobile::of($customerMobile);

        return new self(Gateway::Edfali, Money::of($amount), ['customer_mobile' => $mobile->value]);
    }

    /**
     * @param int|string $birthYear 4 digits, 1900..current year
     * @param int|null   $category  0..36, default 20 (e-commerce)
     */
    public static function sadad(string|int|float $amount, string|LibyanMobile $customerMobile, int|string $birthYear, ?int $category = null): self
    {
        $mobile = $customerMobile instanceof LibyanMobile ? $customerMobile : LibyanMobile::of($customerMobile);
        $year = Digits::clean((string) $birthYear);
        if (preg_match('/^\d{4}$/', $year) !== 1) {
            throw new InvalidArgumentException('The birth year field must be 4 digits.');
        }
        $currentYear = (int) date('Y');
        if ((int) $year < Rules::SADAD_BIRTH_YEAR_MIN || (int) $year > $currentYear) {
            throw new InvalidArgumentException(sprintf('The birth year field must be between %d and %d.', Rules::SADAD_BIRTH_YEAR_MIN, $currentYear));
        }
        if ($category !== null && ($category < Rules::SADAD_CATEGORY_MIN || $category > Rules::SADAD_CATEGORY_MAX)) {
            throw new InvalidArgumentException(sprintf('The category field must be between %d and %d.', Rules::SADAD_CATEGORY_MIN, Rules::SADAD_CATEGORY_MAX));
        }
        $fields = ['customer_mobile' => $mobile->value, 'birth_year' => (int) $year];
        if ($category !== null) {
            $fields['category'] = $category;
        }

        return new self(Gateway::Sadad, Money::of($amount), $fields);
    }

    public static function mobicash(string|int|float $amount, string|BankCardNumber $cardNumber): self
    {
        $card = $cardNumber instanceof BankCardNumber ? $cardNumber : BankCardNumber::forMobiCash($cardNumber);

        return new self(Gateway::MobiCash, Money::of($amount), ['card_number' => $card->value]);
    }

    /** @param Gateway|string $gateway masrefypay | yousrpay | saharapay */
    public static function mitf(Gateway|string $gateway, string|int|float $amount, string|BankCardNumber $cardNumber): self
    {
        $g = $gateway instanceof Gateway ? $gateway : (Gateway::tryFrom($gateway) ?? throw new InvalidArgumentException(sprintf('"%s" is not a gateway slug.', $gateway)));
        if (!$g->isMitf()) {
            throw new InvalidArgumentException(sprintf('"%s" is not a MITF gateway (masrefypay, yousrpay, saharapay).', $g->value));
        }
        $card = $cardNumber instanceof BankCardNumber ? $cardNumber : BankCardNumber::forMitf($g->value, $cardNumber);

        return new self($g, Money::of($amount), ['card_number' => $card->value]);
    }

    public static function moamalat(string|int|float $amount, ?string $returnUrl = null): self
    {
        $r = new self(Gateway::Moamalat, Money::of($amount), []);

        return $returnUrl === null ? $r : $r->withReturnUrl($returnUrl);
    }

    /**
     * Mastercard on the RAW API path charges the amount in USD with no FX,
     * and its hosted page returns to the merchant's dashboard-configured
     * return URL only. Prefer the hosted checkout for card payments.
     */
    public static function mpgs(string|int|float $amount): self
    {
        return new self(Gateway::Mpgs, Money::of($amount), []);
    }

    /**
     * Free-form `data` (your order id etc.); echoed back on reads and in the webhook.
     *
     * @param array<string, mixed> $data
     */
    public function withData(array $data): self
    {
        $clone = clone $this;
        foreach ($data as $key => $value) {
            $clone->data[$key] = $value;
        }

        return $clone;
    }

    /** Absolute https URL the hosted Moamalat page returns the customer to (silently dropped by the API otherwise). */
    public function withReturnUrl(string $url): self
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)) {
            throw new InvalidArgumentException('return_url must be an absolute URL — the API silently ignores anything else.');
        }
        $clone = clone $this;
        $clone->returnUrl = $url;

        return $clone;
    }

    /** ≤255 chars. MobiCash takes it top-level; every gateway keeps it in `data.description`. */
    public function withDescription(string $description): self
    {
        if (mb_strlen($description) > Rules::DESCRIPTION_MAX) {
            throw new InvalidArgumentException(sprintf('The description field must not be greater than %d characters.', Rules::DESCRIPTION_MAX));
        }
        $clone = clone $this;
        $clone->description = $description;

        return $clone;
    }

    /** @return array<string, mixed> the JSON body, amount as a decimal string */
    public function toArray(): array
    {
        $body = ['pay_method' => $this->gateway->value, 'amount' => $this->amount->value];
        foreach ($this->fields as $k => $v) {
            $body[$k] = $v;
        }
        $data = $this->data;
        if ($this->description !== null) {
            $data['description'] ??= $this->description;
            if ($this->gateway === Gateway::MobiCash) {
                $body['description'] = $this->description;
            }
        }
        if ($this->returnUrl !== null) {
            $body['return_url'] = $this->returnUrl;
        }
        if ($data !== []) {
            $body['data'] = $data;
        }

        return $body;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function returnUrl(): ?string
    {
        return $this->returnUrl;
    }
}
