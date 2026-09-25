<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Gateways;

use DPay\Exceptions\InvalidArgumentException;
use DPay\Gateways\BankCardNumber;
use DPay\Gateways\Digits;
use DPay\Gateways\Gateway;
use DPay\Gateways\LibyanMobile;
use DPay\Gateways\Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RulesAndValueObjectsTest extends TestCase
{
    #[Test]
    public function rulesMirrorTheContractsPackage(): void
    {
        self::assertSame(['customer_mobile'], Rules::requiredFields('edfali'));
        self::assertSame(['customer_mobile', 'birth_year'], Rules::requiredFields('sadad'));
        self::assertSame(['card_number'], Rules::requiredFields('mobicash'));
        self::assertSame(['card_number'], Rules::requiredFields('yousrpay'));
        self::assertSame([], Rules::requiredFields('moamalat'));
        self::assertSame([], Rules::requiredFields('mpgs'));
        self::assertSame('33', BankCardNumber::forMitf('yousrpay', '331234567')->bankPrefix());
        self::assertTrue(Rules::isOtpGateway('sadad'));
        self::assertFalse(Rules::isOtpGateway('moamalat'));
        self::assertSame(15, Rules::expiryMinutes('edfali'));
        self::assertSame(10, Rules::expiryMinutes('moamalat'));
        self::assertSame(30, Rules::expiryMinutes('mpgs'));
        self::assertSame(4, Rules::otpLength('edfali'));
        self::assertSame(6, Rules::otpLength('sadad'));
        self::assertNull(Rules::otpLength('mobicash'));
        self::assertTrue(Rules::verifyRequiresBearer('edfali'));
        self::assertFalse(Rules::verifyRequiresBearer('moamalat'));
        self::assertFalse(Rules::verifiableViaApi('mpgs'));
        self::assertTrue(Rules::otpLooksValid('edfali', '١٢٣٤'));
        self::assertFalse(Rules::otpLooksValid('edfali', '12345'));
        self::assertFalse(Rules::otpLooksValid('sadad', '12345'));
        self::assertTrue(Rules::otpLooksValid('sadad', '123456'));
        self::assertTrue(Rules::otpLooksValid('mobicash', '482913'));
        self::assertTrue(Rules::otpLooksValid('mobicash', '123'), 'MobiCash is `string`: the bank decides the shape');
        self::assertTrue(Rules::otpLooksValid('yousrpay', 'AB12CD9'), 'MITF verify is bare `required`');
        self::assertFalse(Rules::otpLooksValid('yousrpay', ' '));
        self::assertTrue(Gateway::YousrPay->isMitf());
        self::assertTrue(Gateway::Moamalat->isRedirect());
        self::assertTrue(Gateway::Sadad->isOtp());
    }

    #[Test]
    public function foldsArabicIndicDigits(): void
    {
        self::assertSame('0912345678', Digits::fold('٠٩١٢٣٤٥٦٧٨'));
        self::assertSame('0912345678', Digits::clean('۰۹۱ ۲۳۴-۵۶۷۸'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function validMobiles(): iterable
    {
        yield 'with zero' => ['0912345678', '0912345678'];
        yield 'without zero' => ['912345678', '912345678'];
        yield 'country code' => ['+218 91 234 5678', '0912345678'];
        yield '00218' => ['00218912345678', '0912345678'];
        yield 'arabic digits' => ['٠٩١٢٣٤٥٦٧٨', '0912345678'];
        yield 'libyana 94' => ['0941234567', '0941234567'];
    }

    #[Test]
    #[DataProvider('validMobiles')]
    public function acceptsLibyanMobiles(string $input, string $expected): void
    {
        $m = LibyanMobile::of($input);
        self::assertSame($expected, $m->value);
        self::assertSame('"'.$expected.'"', json_encode($m));
        self::assertSame(substr($expected, 0, 2).str_repeat('•', strlen($expected) - 4).substr($expected, -2), $m->masked());
        self::assertStringNotContainsString('1234', $m->masked());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMobiles(): iterable
    {
        yield 'landline' => ['0213334455'];
        yield 'too short' => ['091234567'];
        yield 'too long' => ['09123456789'];
        yield '90 prefix' => ['0901234567'];
        yield 'letters' => ['09abc45678'];
    }

    #[Test]
    #[DataProvider('invalidMobiles')]
    public function refusesNonMobiles(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The customer mobile field format is invalid.');
        LibyanMobile::of($input);
    }

    #[Test]
    public function bankCardNumbersFollowTheMobiCashAndMitfRegexes(): void
    {
        self::assertSame('1234567', BankCardNumber::forMobiCash('123 4567')->value);
        self::assertSame('•••••67', BankCardNumber::forMobiCash('1234567')->masked());
        self::assertSame('1234567', BankCardNumber::forMitf('masrefypay', '1234567')->value);
        self::assertSame('1234567890', BankCardNumber::forMitf('saharapay', '1234567890')->value);

        $same = BankCardNumber::forMitf('masrefypay', '111234567');
        self::assertSame('11', $same->bankPrefix());
        self::assertFalse($same->isCrossBank());

        $cross = BankCardNumber::forMitf('masrefypay', '331234567');
        self::assertTrue($cross->isCrossBank());
        self::assertFalse(BankCardNumber::forMitf('yousrpay', '331234567')->isCrossBank());
        self::assertNull(BankCardNumber::forMitf('yousrpay', '1234567')->bankPrefix());
    }

    #[Test]
    public function mobiCashRefusesSixteenDigitPans(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BankCardNumber::forMobiCash('1234567890123456');
    }

    #[Test]
    public function mitfRefusesEightDigits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BankCardNumber::forMitf('yousrpay', '12345678');
    }

    #[Test]
    public function mitfRefusesNonMitfGateways(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BankCardNumber::forMitf('edfali', '1234567');
    }
}
