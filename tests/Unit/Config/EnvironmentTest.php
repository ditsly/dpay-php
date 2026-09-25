<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Config;

use DPay\Config\Environment;
use DPay\Exceptions\InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    #[Test]
    public function tokenPrefixDecidesTheEnvironment(): void
    {
        self::assertSame(Environment::Sandbox, Environment::fromToken('sb_tk_abc'));
        self::assertSame(Environment::Live, Environment::fromToken('12|abcdef'));
    }

    #[Test]
    public function refusesATokenOfTheWrongKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Environment::Live->assertTokenMatches('sb_tk_abc');
    }

    #[Test]
    public function encodesEveryLiveSandboxDifference(): void
    {
        $live = Environment::Live;
        $sandbox = Environment::Sandbox;

        self::assertSame('/api', $live->pathPrefix());
        self::assertSame('/api/sandbox', $sandbox->pathPrefix());
        self::assertSame([], $live->hiddenSlugs());
        self::assertSame(['sadad', 'mpgs'], $sandbox->hiddenSlugs());
        self::assertFalse($sandbox->supportsSlug('mpgs'));
        self::assertTrue($live->supportsSlug('mpgs'));
        self::assertSame(10, $live->expiryMinutes('moamalat'));
        self::assertSame(30, $live->expiryMinutes('mpgs'));
        self::assertSame(10, $live->expiryMinutes('sadad'));
        self::assertSame(15, $live->expiryMinutes('edfali'));
        self::assertSame(5, $sandbox->expiryMinutes('moamalat'));
        self::assertTrue($sandbox->amountsAreStrings());
        self::assertFalse($live->amountsAreStrings());
        self::assertTrue($live->bodiesCarryCurrency());
        self::assertFalse($sandbox->bodiesCarryCurrency());
        self::assertTrue($live->payMethodsAreEnveloped());
        self::assertFalse($sandbox->payMethodsAreEnveloped());
        self::assertSame(3, $live->feeDecimals());
        self::assertSame(2, $sandbox->feeDecimals());
        self::assertNull($live->magicOtps());
        self::assertSame(['success' => '111111', 'failure' => '000000'], $sandbox->magicOtps());
        self::assertTrue($live->webhookLiveFlag());
        self::assertFalse($sandbox->webhookLiveFlag());
        self::assertSame('cs_', $live->checkoutIdPrefix());
        self::assertSame('cs_test_', $sandbox->checkoutIdPrefix());
        self::assertSame(5, $live->throttles()['verify']);
        self::assertSame(30, $sandbox->throttles()['verify']);
        self::assertTrue($live->supportsIdempotency());
        self::assertTrue($sandbox->supportsIdempotency());
    }
}
