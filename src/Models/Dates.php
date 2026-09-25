<?php

declare(strict_types=1);

namespace DPay\Models;

use DPay\Exceptions\UnexpectedResponseException;

/** Parses the API's two timestamp forms: `…000000Z` (Carbon micro) and `…+00:00` (ISO offset). */
final class Dates
{
    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new UnexpectedResponseException('Timestamp is not a string.', 200);
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new UnexpectedResponseException(sprintf('Unreadable timestamp "%s".', $value), 200, null, null);
        }
    }

    public static function require(mixed $value, string $field): \DateTimeImmutable
    {
        $parsed = self::parse($value);
        if ($parsed === null) {
            throw new UnexpectedResponseException(sprintf('Missing "%s" in the API response.', $field), 200);
        }

        return $parsed;
    }
}
