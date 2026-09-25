<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Money;

use DPay\Exceptions\InvalidArgumentException;
use DPay\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    public function canonicalisesStringsIntegersAndFloatsWithoutLosingPrecision(): void
    {
        self::assertSame('125.5', Money::of('125.50')->value);
        self::assertSame('40', Money::of(40)->value);
        self::assertSame('40', Money::of(40.0)->value);
        self::assertSame('0', Money::of('0.000')->value);
        self::assertSame('76.255', Money::of(76.255)->value);
        self::assertSame('10.61', Money::of('10.61')->value);
        self::assertSame('7', Money::of('007')->value);
    }

    #[Test]
    public function fromApiAcceptsEveryWireShape(): void
    {
        self::assertSame('76.26', Money::fromApi(76.26)?->value);
        self::assertSame('40', Money::fromApi(40)?->value);
        self::assertSame('10.61', Money::fromApi('10.61')?->value);
        self::assertNull(Money::fromApi(null));
    }

    #[Test]
    public function refusesNonDecimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('ten');
    }

    #[Test]
    public function formatsAndComparesAtScaleWithoutFloats(): void
    {
        self::assertSame('125.50', Money::of('125.5')->format(2));
        self::assertSame('0.755', Money::of('0.755')->format(3));
        self::assertSame('76.26', Money::of('76.255')->format(2));
        self::assertTrue(Money::of('76.255')->equalsAt(Money::of('76.26'), 2));
        self::assertFalse(Money::of('76.255')->equals(Money::of('76.26')));
        self::assertSame(1, Money::of('10.61')->compareTo(Money::of('10.6')));
        self::assertSame(-1, Money::of('9.99')->compareTo(Money::of('10')));
        self::assertSame(0, Money::of('5')->compareTo(Money::of('5.00')));
        self::assertSame(-1, Money::of('-1')->compareTo(Money::of('0.01')));
        self::assertTrue(Money::of('60000')->isGreaterThan(Money::of('59999.999')));
        self::assertTrue(Money::of('0.01')->isPositive());
        self::assertFalse(Money::zero()->isPositive());
        self::assertSame(1, Money::of('125.50')->scale());
        self::assertSame(0, Money::of('40')->scale());
        self::assertSame(3, Money::of('1.255')->scale());
    }

    #[Test]
    public function chargeRoundedIsTheStorageRule(): void
    {
        self::assertSame('76.26', Money::of('76.255')->chargeRounded()->value);
        self::assertSame('2.7', Money::of('2.702')->chargeRounded()->value);
        self::assertSame('0.04', Money::of('0.036')->chargeRounded()->value);
        self::assertSame('"125.5"', json_encode(Money::of('125.50')));
        self::assertSame('125.5', (string) Money::of('125.50'));
    }
}
