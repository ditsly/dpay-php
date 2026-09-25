<?php

declare(strict_types=1);

namespace DPay;

final class Version
{
    public const SDK = '1.0.0';

    public static function userAgent(): string
    {
        return sprintf('dpay-php/%s php/%s', self::SDK, PHP_VERSION);
    }
}
