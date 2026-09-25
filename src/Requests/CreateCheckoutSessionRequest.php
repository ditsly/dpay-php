<?php

declare(strict_types=1);

namespace DPay\Requests;

use DPay\Exceptions\InvalidArgumentException;
use DPay\Gateways\Rules;
use DPay\Models\CheckoutCustomer;
use DPay\Money\Money;

/**
 * The body of `POST /api/v2/checkout-sessions` (strict: unknown keys are 422).
 *
 *   CreateCheckoutSessionRequest::of('125.50', 'https://shop.ly/dpay/return?order=10483&key=k9')
 *       ->reference('10483')
 *       ->description('Order #10483')
 *       ->cancelUrl('https://shop.ly/cart')
 *       ->metadata(['order_id' => 10483, 'platform' => 'woocommerce', 'plugin_version' => '1.0.0'])
 *       ->customer(name: 'سالم علي', phone: '0912345678')
 *       ->expiresInMinutes(60)
 *       ->locale('ar');
 */
final class CreateCheckoutSessionRequest
{
    public const AMOUNT_MAX_DECIMALS = 2;
    public const DESCRIPTION_MAX = 255;
    public const REFERENCE_MAX = 64;
    public const METADATA_MAX_KEYS = 50;
    public const METADATA_VALUE_MAX = 500;
    public const METADATA_MAX_BYTES = 16 * 1024;
    public const EXPIRES_MIN = 5;
    public const EXPIRES_MAX = 1440;
    public const EXPIRES_DEFAULT = 60;

    /** @var array<string, mixed> */
    private array $body;

    private function __construct(Money $amount, string $returnUrl)
    {
        if ($amount->scale() > self::AMOUNT_MAX_DECIMALS) {
            throw new InvalidArgumentException('amount must have at most 2 decimal places.');
        }
        if ($amount->isLessThan(Money::of('0.01'))) {
            throw new InvalidArgumentException('amount must be at least 0.01.');
        }
        self::assertHttpsUrl($returnUrl, 'return_url');
        $this->body = ['amount' => $amount->format(2), 'currency' => 'LYD', 'return_url' => $returnUrl];
    }

    public static function of(Money|string|int|float $amount, string $returnUrl): self
    {
        return new self($amount instanceof Money ? $amount : Money::of($amount), $returnUrl);
    }

    public function description(string $description): self
    {
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new InvalidArgumentException('description must be ≤ 255 characters.');
        }

        return $this->with('description', $description);
    }

    /** The store's order number — comes back on the object and in every webhook. */
    public function reference(string|int $reference): self
    {
        $reference = (string) $reference;
        if ($reference === '' || mb_strlen($reference) > self::REFERENCE_MAX) {
            throw new InvalidArgumentException('reference must be 1..64 characters.');
        }

        return $this->with('reference', $reference);
    }

    public function cancelUrl(string $url): self
    {
        self::assertHttpsUrl($url, 'cancel_url');

        return $this->with('cancel_url', $url);
    }

    /** @param array<string, string|int|float|bool|null> $metadata ≤ 50 keys, scalar values, strings ≤ 500 chars, ≤ 16 KB */
    public function metadata(array $metadata): self
    {
        if (count($metadata) > self::METADATA_MAX_KEYS) {
            throw new InvalidArgumentException('metadata may have at most 50 keys.');
        }
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('metadata keys must be non-empty strings.');
            }
            if ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('metadata.%s must be a string, number, boolean or null.', $key));
            }
            if (is_string($value) && mb_strlen($value) > self::METADATA_VALUE_MAX) {
                throw new InvalidArgumentException(sprintf('metadata.%s must be ≤ 500 characters.', $key));
            }
        }
        if (strlen((string) json_encode($metadata, JSON_UNESCAPED_UNICODE)) > self::METADATA_MAX_BYTES) {
            throw new InvalidArgumentException('metadata must be ≤ 16 KB serialised.');
        }

        return $this->with('metadata', $metadata === [] ? new \stdClass() : $metadata);
    }

    public function customer(?string $name = null, ?string $email = null, ?string $phone = null): self
    {
        if ($name !== null && mb_strlen($name) > 120) {
            throw new InvalidArgumentException('customer.name must be ≤ 120 characters.');
        }
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('customer.email must be an email address.');
        }
        if ($phone !== null && mb_strlen($phone) > 32) {
            throw new InvalidArgumentException('customer.phone must be ≤ 32 characters.');
        }
        $c = (new CheckoutCustomer($name, $email, $phone))->toArray();

        return $this->with('customer', $c);
    }

    /** @param list<string> $slugs only these methods are offered on the hosted page */
    public function allowedMethods(array $slugs): self
    {
        foreach ($slugs as $slug) {
            if (!Rules::isKnown($slug)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a gateway slug.', $slug));
            }
        }

        return $this->with('allowed_methods', array_values($slugs));
    }

    public function expiresInMinutes(int $minutes): self
    {
        if ($minutes < self::EXPIRES_MIN || $minutes > self::EXPIRES_MAX) {
            throw new InvalidArgumentException('expires_in_minutes must be 5..1440.');
        }

        return $this->with('expires_in_minutes', $minutes);
    }

    /** `ar` | `en` — the hosted page's first-visit language. */
    public function locale(string $locale): self
    {
        if (!in_array($locale, ['ar', 'en'], true)) {
            throw new InvalidArgumentException('locale must be "ar" or "en".');
        }

        return $this->with('locale', $locale);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->body;
    }

    public function amount(): Money
    {
        $amount = $this->body['amount'];

        return Money::of(is_string($amount) ? $amount : '0');
    }

    private function with(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->body[$key] = $value;

        return $clone;
    }

    private static function assertHttpsUrl(string $url, string $field): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['https', 'http'], true)) {
            throw new InvalidArgumentException(sprintf('%s must be an absolute URL.', $field));
        }
        if ($scheme !== 'https') {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (!in_array($host, ['localhost', '127.0.0.1'], true)) {
                throw new InvalidArgumentException(sprintf('%s must use https:// (http:// is accepted only on localhost against a local API).', $field));
            }
        }
    }
}
