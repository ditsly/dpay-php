<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Requests;

use DPay\Exceptions\InvalidArgumentException;
use DPay\Gateways\Gateway;
use DPay\Requests\OpenSessionRequest;
use DPay\Requests\VerifyRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpenSessionRequestTest extends TestCase
{
    #[Test]
    public function buildsThePerGatewayBodiesWithStringAmountsAndStringFields(): void
    {
        self::assertSame(
            ['pay_method' => 'edfali', 'amount' => '75.5', 'customer_mobile' => '0912345678', 'data' => ['description' => 'Order #10483']],
            OpenSessionRequest::edfali('75.50', '0912345678')->withDescription('Order #10483')->toArray(),
        );
        self::assertSame(
            ['pay_method' => 'sadad', 'amount' => '90', 'customer_mobile' => '0912345678', 'birth_year' => 1990, 'category' => 20],
            OpenSessionRequest::sadad(90, '0912345678', '1990', 20)->toArray(),
        );
        self::assertSame(
            ['pay_method' => 'mobicash', 'amount' => '60', 'card_number' => '1234567', 'description' => 'Order #10484', 'data' => ['description' => 'Order #10484']],
            OpenSessionRequest::mobicash(60, '1234567')->withDescription('Order #10484')->toArray(),
        );
        self::assertSame(
            ['pay_method' => 'yousrpay', 'amount' => '40', 'card_number' => '331234567'],
            OpenSessionRequest::mitf(Gateway::YousrPay, '40', '331234567')->toArray(),
        );
        self::assertSame(
            ['pay_method' => 'moamalat', 'amount' => '250', 'return_url' => 'https://shop.ly/return?order=1', 'data' => ['order_id' => 10482]],
            OpenSessionRequest::moamalat('250', 'https://shop.ly/return?order=1')->withData(['order_id' => 10482])->toArray(),
        );
        self::assertSame(['pay_method' => 'mpgs', 'amount' => '120'], OpenSessionRequest::mpgs(120)->toArray());
        self::assertSame('"1234567"', json_encode(OpenSessionRequest::mobicash(60, '1234567')->toArray()['card_number']), 'card_number is a JSON string');
    }

    #[Test]
    public function validatesLocallyWithTheApisOwnMessages(): void
    {
        try {
            OpenSessionRequest::edfali('10', '+218912345678x');
            self::fail('expected refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('The customer mobile field format is invalid.', $e->getMessage());
        }
        try {
            OpenSessionRequest::sadad('10', '0912345678', '199');
            self::fail('expected refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('The birth year field must be 4 digits.', $e->getMessage());
        }
        try {
            OpenSessionRequest::sadad('10', '0912345678', 1990, 99);
            self::fail('expected refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('The category field must be between 0 and 36.', $e->getMessage());
        }
        try {
            OpenSessionRequest::mobicash('10', '1234567')->withDescription(str_repeat('x', 256));
            self::fail('expected refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('The description field must not be greater than 255 characters.', $e->getMessage());
        }
        try {
            OpenSessionRequest::moamalat('0');
            self::fail('expected refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('The amount field must be at least 0.01.', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        OpenSessionRequest::moamalat('10', 'not a url');
    }

    #[Test]
    public function mitfRefusesANonMitfGateway(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpenSessionRequest::mitf('edfali', '10', '1234567');
    }

    #[Test]
    public function verifyRequestFoldsDigitsAndRequiresAReference(): void
    {
        self::assertSame(['session_id' => 2, 'otp' => '1234'], VerifyRequest::otp(2, '١٢٣٤')->toArray());
        self::assertSame(['session_id' => 1, 'moamalat_response' => ['MerchantReference' => 'txn_x']], VerifyRequest::moamalat(1, ['MerchantReference' => 'txn_x'])->toArray());
        $this->expectException(InvalidArgumentException::class);
        VerifyRequest::otp(2, '   ');
    }

    #[Test]
    public function verifyRequestRefusesOnlyWhatTheApiRefuses(): void
    {
        // MobiCash is `required|string`, the MITF banks bare `required`: a bank-issued alphanumeric or 9-digit code goes through.
        self::assertSame(['session_id' => 2, 'otp' => 'AB12CD'], VerifyRequest::otp(2, 'AB12CD', Gateway::MobiCash)->toArray());
        self::assertSame(['session_id' => 2, 'otp' => '123456789'], VerifyRequest::otp(2, '123 456 789', 'yousrpay')->toArray());
        self::assertSame(['session_id' => 2, 'otp' => '123'], VerifyRequest::otp(2, '123')->toArray(), 'no gateway: nothing but emptiness is refused');
        // EDFali `digits:4`, Sadad `digits:6` are refused locally before an attempt is spent.
        self::assertSame(['session_id' => 2, 'otp' => '482913'], VerifyRequest::otp(2, '٤٨٢٩١٣', Gateway::Sadad)->toArray());
        foreach ([['12345', Gateway::Edfali], ['12345', 'sadad'], ['abcd', Gateway::Edfali]] as [$otp, $gateway]) {
            try {
                VerifyRequest::otp(2, $otp, $gateway);
                self::fail("$otp must be refused for ".($gateway instanceof Gateway ? $gateway->value : $gateway));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('digits', $e->getMessage());
            }
        }
    }
}
