<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Logging;

use DPay\Logging\Redactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    #[Test]
    public function masksSensitiveKeysRecursivelyAndTokenShapesInText(): void
    {
        $out = Redactor::array(['otp' => '1234', 'customer_mobile' => '0912345678', 'data' => ['card_number' => '1234567', 'order_id' => 5, 'note' => 'Bearer sb_tk_abc']]);
        self::assertSame('[redacted]', $out['otp']);
        self::assertSame('[redacted]', $out['customer_mobile']);
        self::assertSame('[redacted]', $out['data']['card_number']);
        self::assertSame(5, $out['data']['order_id']);
        self::assertSame('Bearer [redacted]', $out['data']['note']);
        self::assertSame('token whsec_[redacted] and [redacted]', Redactor::string('token whsec_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef and 12|AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd1a2b3c4d'));
    }
}
