<?php

declare(strict_types=1);

/**
 * Shared by every example: one client from the environment.
 *
 *   DPAY_API_TOKEN            sb_tk_… (sandbox) or the integration token (live); the prefix decides
 *   DPAY_BASE_URL             optional — https://dpay.ly (default), https://next.dpay.ly, or a local API
 *   DPAY_ALLOW_HTTP_LOCALHOST optional — "1" to allow http://localhost:PORT (the Docker parity API, a mock)
 *
 * Mirrors config/dpay.php in the Laravel package: DPay hosts only unless you opt in.
 */

require __DIR__.'/../vendor/autoload.php';

use DPay\Client;
use DPay\Http\BaseUrlPolicy;

function dpay_client(): Client
{
    $token = getenv('DPAY_API_TOKEN') ?: 'sb_tk_paste-your-sandbox-token';
    $options = [];
    $baseUrl = getenv('DPAY_BASE_URL');
    if (is_string($baseUrl) && $baseUrl !== '') {
        $options['base_url'] = $baseUrl;
    }
    if (in_array(getenv('DPAY_ALLOW_HTTP_LOCALHOST'), ['1', 'true', 'yes'], true)) {
        $options['base_url_policy'] = BaseUrlPolicy::allowing([], allowHttpLocalhost: true);
    }

    return Client::fromToken($token, $options); // sb_tk_… → sandbox, integration token → live
}
