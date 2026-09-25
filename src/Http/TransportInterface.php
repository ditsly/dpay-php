<?php

declare(strict_types=1);

namespace DPay\Http;

use DPay\Exceptions\ConnectionException;

/**
 * Sends one HTTP request and returns its raw answer. The SDK ships a PSR-18
 * transport; tests use a recording/fixture transport. A transport never
 * follows redirects and never throws on HTTP error statuses — mapping is the
 * client's job.
 */
interface TransportInterface
{
    /**
     * @param string                $method
     * @param string                $url     absolute URL
     * @param array<string, string> $headers
     *
     * @throws ConnectionException when no HTTP response was obtained
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): ApiResponse;
}
