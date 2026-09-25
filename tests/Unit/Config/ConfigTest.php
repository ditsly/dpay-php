<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Config;

use DPay\Client;
use DPay\Config\Config;
use DPay\Config\Environment;
use DPay\Exceptions\InvalidArgumentException;
use DPay\Tests\Support\Clients;
use DPay\Tests\Support\FixtureTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    #[Test]
    public function defaultsToDpayLyAndTheTokenEnvironment(): void
    {
        $config = new Config(Clients::SANDBOX_TOKEN);
        self::assertSame('https://dpay.ly', $config->baseUrl);
        self::assertSame(Environment::Sandbox, $config->environment);
        self::assertSame(15.0, $config->timeout);
        self::assertSame('ar', $config->locale);
    }

    #[Test]
    public function theTokenNeverLeaksThroughDumpsEncodingOrSerialization(): void
    {
        $token = Clients::LIVE_TOKEN;
        $client = Client::live($token, ['transport' => new FixtureTransport()]);

        self::assertSame($token, $client->config->token(), 'readable only through the method');
        self::assertSame('12|AbC…3c4d', $client->config->maskedToken());

        foreach (['json' => (string) json_encode($client), 'json-config' => (string) json_encode($client->config), 'print_r' => print_r($client, true), 'print_r-config' => print_r($client->config, true), 'var_export-free-dump' => (string) json_encode($client->__debugInfo())] as $label => $dump) {
            self::assertStringNotContainsString($token, $dump, "$label leaks the token");
        }
        ob_start();
        var_dump($client);
        $dumped = (string) ob_get_clean();
        self::assertStringNotContainsString($token, $dumped, 'var_dump leaks the token');
        self::assertStringContainsString('12|AbC…3c4d', $dumped, 'the masked token is still there to tell clients apart');

        foreach ([$client, $client->config] as $object) {
            try {
                serialize($object);
                self::fail($object::class.' must refuse serialize()');
            } catch (\LogicException $e) {
                self::assertStringContainsString('never serialized', $e->getMessage());
            }
        }
    }

    #[Test]
    public function refusesEmptyOrWhitespaceTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Config(' 12|abc def');
    }

    #[Test]
    public function liveFactoryRefusesASandboxToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Client::live(Clients::SANDBOX_TOKEN, ['transport' => new FixtureTransport()]);
    }

    #[Test]
    public function sandboxFactoryRefusesALiveToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Client::sandbox(Clients::LIVE_TOKEN, ['transport' => new FixtureTransport()]);
    }

    #[Test]
    public function fromTokenPicksTheEnvironment(): void
    {
        $t = new FixtureTransport();
        self::assertTrue(Client::fromToken(Clients::SANDBOX_TOKEN, ['transport' => $t])->isSandbox());
        self::assertFalse(Client::fromToken(Clients::LIVE_TOKEN, ['transport' => $t])->isSandbox());
    }

    #[Test]
    public function refusesUnknownLocales(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Config(Clients::LIVE_TOKEN, locale: 'fr');
    }
}
