<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The constant is what the User-Agent reports and what the store plugins print as "SDK version".
 * The published 1.0.1 shipped with it still at 1.0.0: the newest CHANGELOG heading (the release
 * being prepared, 1.0.2) is the truth.
 */
final class VersionTest extends TestCase
{
    #[Test]
    public function theSdkConstantIsTheNewestReleasedChangelogVersion(): void
    {
        $changelog = (string) file_get_contents(dirname(__DIR__, 2).'/CHANGELOG.md');
        self::assertSame(1, preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $m), 'CHANGELOG has a released version heading');

        self::assertSame($m[1], Version::SDK);
        self::assertSame(sprintf('dpay-php/%s php/%s', $m[1], PHP_VERSION), Version::userAgent());
    }
}
