<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Money;

use DPay\Money\FeeMath;
use DPay\Money\Money;
use DPay\Money\PhpRound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhpRoundTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function goldenVectors(): iterable
    {
        $raw = file_get_contents(__DIR__.'/../../fixtures/legacy-money.vectors.json');
        self::assertNotFalse($raw);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        foreach ($decoded['vectors'] as $i => $vector) {
            yield sprintf('#%d amount=%s fee=%s', $i, $vector['amount'], $vector['feePercent']) => [$vector];
        }
    }

    /** @param array<string, mixed> $v */
    #[Test]
    #[DataProvider('goldenVectors')]
    public function reproducesTheLegacyPhpFormulasBitForBit(array $v): void
    {
        $amount = Money::of($v['amount']);
        $fee = FeeMath::fee($amount, $v['feePercent']);
        $total = FeeMath::total($amount, $fee);

        self::assertSame(Money::of($v['fee3'])->value, $fee->value, 'fee 3dp');
        self::assertSame(Money::of($v['fee2'])->value, FeeMath::fee2dp($amount, $v['feePercent'])->value, 'fee 2dp');
        self::assertSame(Money::of($v['total'])->value, $total->value, 'total');
        self::assertSame(Money::of($v['charge2'])->value, $total->chargeRounded()->value, 'charge');
        self::assertSame(Money::of($v['charge2'])->value, FeeMath::charge($amount, $v['feePercent'])->value, 'charge helper');
        self::assertSame($v['dirhams'], FeeMath::toDirhams($total), 'dirhams');
    }

    #[Test]
    public function roundsTheShortestDecimalHalfAwayFromZeroNotTheBinaryValue(): void
    {
        // PHP < 8.4 native round() and naive binary rounding disagree on these.
        self::assertSame('2.68', PhpRound::format(2.675, 2));
        self::assertSame('1.01', PhpRound::format(1.005, 2));
        self::assertSame('0.3', PhpRound::format(0.285, 1));
        self::assertSame(2.68, PhpRound::round(2.675, 2));
        self::assertSame(-2.68, PhpRound::round(-2.675, 2));
        self::assertSame('-2.68', PhpRound::format(-2.675, 2));
        self::assertSame('0.00', PhpRound::format(-0.001, 2), 'never "-0.00"');
        self::assertSame('101.000', PhpRound::format(100.9995, 3));
        self::assertSame('1000', PhpRound::format(999.5, 0));
    }

    #[Test]
    public function shortestRepresentationIsIniIndependent(): void
    {
        self::assertSame('0.30000000000000004', PhpRound::shortest(0.1 + 0.2));
        self::assertSame('76.255', PhpRound::shortest(76.255));
        self::assertSame('0.005', PhpRound::shortest(0.005));
        self::assertSame('1000000000000000000000', PhpRound::shortest(1e21));
        self::assertSame('0.000001', PhpRound::shortest(0.000001));
        self::assertSame('40', PhpRound::shortest(40.0));
        self::assertSame('0', PhpRound::shortest(0.0));
    }

    #[Test]
    public function openResponseFigures(): void
    {
        // Postman: open edfali 75.5 @1% → fee_amount 0.755, total 76.255; GET reports 76.26.
        $amount = Money::of('75.5');
        $fee = FeeMath::fee($amount, '1');
        $total = FeeMath::total($amount, $fee);
        self::assertSame('0.755', $fee->value);
        self::assertSame('76.255', $total->value);
        self::assertSame('76.26', $total->chargeRounded()->value);
        // Sandbox: 10.5 @1% → fee 0.11 (2dp), total 10.61.
        self::assertSame('0.11', FeeMath::fee2dp(Money::of('10.5'), 1)->value);
        self::assertSame('10.61', FeeMath::sandboxTotal(Money::of('10.5'), 1)->value);
        self::assertSame('26.14', FeeMath::sandboxTotal(Money::of('25.5'), 2.5)->value);
    }
}
