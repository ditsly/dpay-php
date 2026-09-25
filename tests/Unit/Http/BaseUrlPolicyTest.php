<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Http;

use DPay\Exceptions\InvalidArgumentException;
use DPay\Http\BaseUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BaseUrlPolicyTest extends TestCase
{
    #[Test]
    public function acceptsTheDpayHostsOverHttpsOnly(): void
    {
        $policy = BaseUrlPolicy::dpayOnly();
        self::assertSame('https://dpay.ly', $policy->normalize('https://dpay.ly/'));
        self::assertSame('https://next.dpay.ly', $policy->normalize('HTTPS://Next.DPay.ly'));
        self::assertSame('https://pg.dits.ly', $policy->normalize('https://pg.dits.ly'));
        self::assertTrue($policy->trusts('https://pg.dits.ly/moamalat-pay/1'));
        self::assertFalse($policy->trusts('https://evil.example/moamalat-pay/1'));
        self::assertFalse($policy->trusts('http://dpay.ly/x'));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'http' => ['http://dpay.ly'];
        yield 'foreign host' => ['https://dpay.ly.evil.example'];
        yield 'subdomain not listed' => ['https://api.dpay.ly'];
        yield 'path' => ['https://dpay.ly/api'];
        yield 'credentials' => ['https://user:pw@dpay.ly'];
        yield 'query' => ['https://dpay.ly?x=1'];
        yield 'relative' => ['dpay.ly'];
        yield 'localhost without opt-in' => ['http://localhost:3000'];
        yield 'docker host without opt-in' => ['http://host.docker.internal:3099'];
    }

    #[Test]
    #[DataProvider('refused')]
    public function refusesEverythingElse(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        BaseUrlPolicy::dpayOnly()->normalize($url);
    }

    #[Test]
    public function customHostsAndLocalhostAreAnExplicitOptIn(): void
    {
        $policy = BaseUrlPolicy::allowing(['Staging.Example.ly'], allowHttpLocalhost: true);
        self::assertSame('https://staging.example.ly', $policy->normalize('https://staging.example.ly'));
        self::assertSame('http://localhost:3000', $policy->normalize('http://localhost:3000/'));
        self::assertSame('https://dpay.ly', $policy->normalize('https://dpay.ly'));
        $this->expectException(InvalidArgumentException::class);
        $policy->normalize('http://staging.example.ly');
    }

    /**
     * A container calls the machine it runs on `host.docker.internal` (Docker
     * Desktop, Colima, Podman) — the same developer box `localhost` names from
     * the outside, so the same opt-in covers it and nothing else does.
     */
    #[Test]
    public function theDockerHostNamesAreLocalhostSeenFromAContainer(): void
    {
        $policy = BaseUrlPolicy::allowing([], allowHttpLocalhost: true);
        self::assertSame('http://host.docker.internal:3099', $policy->normalize('http://HOST.docker.internal:3099/'));
        self::assertSame('http://host.lima.internal:3099', $policy->normalize('http://host.lima.internal:3099'));
        self::assertTrue($policy->trusts('http://host.docker.internal:3103/sandbox/pay/cs_test_x'));
        self::assertFalse(BaseUrlPolicy::dpayOnly()->trusts('http://host.docker.internal:3103/x'));
        $this->expectException(InvalidArgumentException::class);
        $policy->normalize('http://host.docker.internal.evil.example:3099');
    }
}
