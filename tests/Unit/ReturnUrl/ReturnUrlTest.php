<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\ReturnUrl;

use DPay\Exceptions\InvalidArgumentException;
use DPay\ReturnUrl\ReturnUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReturnUrlTest extends TestCase
{
    #[Test]
    public function appendsWithTheLegacySeparatorRuleAndKeyOrder(): void
    {
        self::assertSame('https://shop.ly/r?session_id=1&status=paid&payment_id=5', ReturnUrl::legacy('https://shop.ly/r', 1, 'paid', 5));
        self::assertSame('https://shop.ly/r?o=1&session_id=1&status=failed', ReturnUrl::legacy('https://shop.ly/r?o=1', 1, 'failed'));
        self::assertSame('https://shop.ly/r?session_id=8&status=paid', ReturnUrl::legacy('https://shop.ly/r', 8, 'paid', null), 'MPGS: no payment_id');
        self::assertSame('https://shop.ly/r?order=1&checkout_session_id=cs_x&status=open', ReturnUrl::checkout('https://shop.ly/r?order=1', 'cs_x', 'open'));
        self::assertSame('https://shop.ly/r?checkout_session_id=cs_x&status=paid&payment_id=5', ReturnUrl::checkout('https://shop.ly/r', 'cs_x', 'paid', 5), 'the paid return carries payment_id last');
        self::assertSame('https://shop.ly/r', ReturnUrl::append('https://shop.ly/r', [['a', null], ['b', '']]));
    }

    #[Test]
    public function parsesTheLegacyTrioFromAUrlAQueryOrAnArray(): void
    {
        $p = ReturnUrl::parse('https://shop.ly/r?order=1&session_id=1&status=paid&payment_id=5');
        self::assertSame(1, $p->sessionId);
        self::assertSame('paid', $p->status);
        self::assertSame(5, $p->paymentId);
        self::assertNull($p->checkoutSessionId);
        self::assertTrue($p->hintsPaid());
        self::assertFalse($p->isCheckout());

        $q = ReturnUrl::parse('session_id=8&status=cancelled');
        self::assertSame(8, $q->sessionId);
        self::assertTrue($q->hintsCancelled());
        self::assertNull($q->paymentId);

        $g = ReturnUrl::parse(['session_id' => '2', 'status' => 'FAILED', 'payment_id' => 'x']);
        self::assertSame(2, $g->sessionId);
        self::assertSame('failed', $g->status);
        self::assertNull($g->paymentId, 'non-numeric ids are dropped');
    }

    #[Test]
    public function parsesTheCheckoutPair(): void
    {
        $p = ReturnUrl::parse('https://shop.ly/dpay/return?order=10483&key=k9&checkout_session_id=cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2&status=open');
        self::assertSame('cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', $p->checkoutSessionId);
        self::assertSame('open', $p->status);
        self::assertTrue($p->isCheckout());
        $paid = ReturnUrl::parse(['checkout_session_id' => 'cs_x', 'status' => 'paid', 'payment_id' => '77']);
        self::assertSame(77, $paid->paymentId);
        self::assertTrue($paid->hintsPaid());
        self::assertSame('cs_x', $paid->requireCheckoutId());

        // The builder and the parser agree on the paid return end to end.
        $round = ReturnUrl::parse(ReturnUrl::checkout('https://shop.ly/r?order=1', 'cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', 'paid', 21));
        self::assertSame('cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', $round->checkoutSessionId);
        self::assertSame(21, $round->paymentId);
        self::assertTrue($round->hintsPaid());
    }

    #[Test]
    public function requireCheckoutIdRefusesAReturnWithoutOne(): void
    {
        $legacy = ReturnUrl::parse('session_id=1&status=paid');
        self::assertFalse($legacy->isCheckout());
        $this->expectException(InvalidArgumentException::class);
        $legacy->requireCheckoutId();
    }

    #[Test]
    public function unknownStatusesAndEmptyInputsAreNull(): void
    {
        self::assertNull(ReturnUrl::parse('session_id=1&status=hacked')->status);
        self::assertTrue(ReturnUrl::parse('https://shop.ly/r')->isEmpty());
        self::assertTrue(ReturnUrl::parse([])->isEmpty());
        self::assertNull(ReturnUrl::parse('session_id=1&status=open')->status, 'open is a checkout status only');
    }
}
