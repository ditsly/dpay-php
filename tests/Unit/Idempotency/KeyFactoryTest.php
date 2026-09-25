<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Idempotency;

use DPay\Exceptions\InvalidArgumentException;
use DPay\Idempotency\KeyFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeyFactoryTest extends TestCase
{
    #[Test]
    public function keysAreDeterministicPerInstallOrderAttemptAndMethod(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $f = new KeyFactory('woocommerce', $uid);
        $expected = rtrim(strtr(base64_encode(hash('sha256', 'woocommerce:'.$uid.':10483:1:edfali', true)), '+/', '-_'), '=');

        self::assertSame($expected, $f->forOrder(10483, 1, 'edfali'));
        self::assertSame($f->forOrder('10483', 1, 'edfali'), $f->forOrder(10483, 1, 'edfali'));
        self::assertNotSame($f->forOrder(10483, 1, 'edfali'), $f->forOrder(10483, 2, 'edfali'), 'a new attempt is a new key');
        self::assertNotSame($f->forOrder(10483, 1, 'edfali'), $f->forOrder(10483, 1, 'sadad'), 'a method change is a new key');
        self::assertNotSame($f->forOrder(10483), (new KeyFactory('woocommerce', bin2hex(random_bytes(16))))->forOrder(10483), 'two stores never collide');
        self::assertSame($f->forOrder(10483, 1, 'checkout'), $f->forCheckout(10483));
        self::assertSame(43, strlen($f->forCheckout(10483)), 'fits the v2 64-char limit');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $f->forCheckout(10483));
    }

    #[Test]
    public function generatesStoreUidsAndUuids(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', KeyFactory::generateStoreUid());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', KeyFactory::random());
        self::assertNotSame(KeyFactory::random(), KeyFactory::random());
    }

    #[Test]
    public function refusesAShortStoreUid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new KeyFactory('laravel', 'short');
    }

    #[Test]
    public function refusesAttemptZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new KeyFactory('laravel', KeyFactory::generateStoreUid()))->forOrder(1, 0);
    }
}
