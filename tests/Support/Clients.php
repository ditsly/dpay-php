<?php

declare(strict_types=1);

namespace DPay\Tests\Support;

use DPay\Client;
use DPay\Http\RetryPolicy;

final class Clients
{
    public const LIVE_TOKEN = '12|AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd1a2b3c4d';
    public const SANDBOX_TOKEN = 'sb_tk_0123456789abcdef0123456789abcdef';

    public static function live(FixtureTransport $transport, ?RetryPolicy $retry = null): Client
    {
        return Client::live(self::LIVE_TOKEN, ['transport' => $transport, 'retry' => $retry ?? RetryPolicy::none()]);
    }

    public static function sandbox(FixtureTransport $transport, ?RetryPolicy $retry = null): Client
    {
        return Client::sandbox(self::SANDBOX_TOKEN, ['transport' => $transport, 'retry' => $retry ?? RetryPolicy::none()]);
    }
}
