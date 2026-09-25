<?php

declare(strict_types=1);

namespace DPay\Tests\Support;

/**
 * Reads the execution-generated Postman collection into flat
 * request/response samples, with `{{variables}}` substituted.
 */
final class Postman
{
    public const FIXTURE = __DIR__.'/../Contract/fixtures/dpay-v1.postman_collection.json';
    public const PLATFORM_COPY = __DIR__.'/../../../../platform/packages/contracts/docs/dpay-v1.postman_collection.json';

    /** Values for variables the collection leaves to the environment. */
    public const VARIABLES = [
        'base_url' => 'https://dpay.ly',
        'api_token' => Clients::LIVE_TOKEN,
        'session_id' => '2',
        'other_merchant_token' => '99|OtherMerchantTokenAbcdefghijklmnopqrstu1a2b3c4d',
        'restricted_token' => '7|RestrictedTokenAbcdefghijklmnopqrstuvwxy1a2b3c4d',
        'webhook_secret' => 'whsec_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
    ];

    /** @return list<Sample> */
    public static function samples(): array
    {
        $collection = self::collection();
        $vars = self::VARIABLES;
        foreach ($collection['variable'] ?? [] as $v) {
            if (is_array($v) && isset($v['key'], $v['value']) && is_string($v['key']) && is_scalar($v['value'])) {
                $vars[$v['key']] = (string) $v['value'];
            }
        }
        $out = [];
        self::walk($collection['item'] ?? [], '', $vars, $out);

        return $out;
    }

    /** @return array<string, mixed> */
    public static function collection(): array
    {
        $raw = file_get_contents(self::FIXTURE);
        if ($raw === false) {
            throw new \RuntimeException('Missing '.self::FIXTURE);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Fixture is not JSON.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<mixed>            $items
     * @param array<string, string>   $vars
     * @param list<Sample>            $out
     */
    private static function walk(array $items, string $path, array $vars, array &$out): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = isset($item['name']) && is_string($item['name']) ? $item['name'] : '?';
            if (isset($item['item']) && is_array($item['item'])) {
                self::walk($item['item'], $path.'/'.$name, $vars, $out);
                continue;
            }
            $request = isset($item['request']) && is_array($item['request']) ? $item['request'] : [];
            $url = $request['url'] ?? '';
            $rawUrl = is_array($url) ? self::str($url['raw'] ?? '') : self::str($url);
            $headers = [];
            foreach (isset($request['header']) && is_array($request['header']) ? $request['header'] : [] as $h) {
                if (is_array($h) && isset($h['key'], $h['value'])) {
                    $headers[self::str($h['key'])] = self::sub(self::str($h['value']), $vars);
                }
            }
            $body = isset($request['body']) && is_array($request['body']) && isset($request['body']['raw']) ? self::sub(self::str($request['body']['raw']), $vars) : null;
            foreach (isset($item['response']) && is_array($item['response']) ? $item['response'] : [] as $response) {
                if (!is_array($response)) {
                    continue;
                }
                $rh = [];
                foreach (isset($response['header']) && is_array($response['header']) ? $response['header'] : [] as $h) {
                    if (is_array($h) && isset($h['key'], $h['value'])) {
                        $rh[strtolower(self::str($h['key']))] = self::sub(self::str($h['value']), $vars);
                    }
                }
                $out[] = new Sample(
                    $path,
                    $name,
                    self::str($request['method'] ?? 'GET'),
                    self::sub($rawUrl, $vars),
                    $headers,
                    $body,
                    self::str($response['name'] ?? ''),
                    (int) self::str($response['code'] ?? '0'),
                    $rh,
                    self::sub(self::str($response['body'] ?? ''), $vars),
                );
            }
            if (!isset($item['response']) || $item['response'] === []) {
                // Outbound webhook samples have no response: keep the request.
                $out[] = new Sample($path, $name, self::str($request['method'] ?? 'POST'), self::sub($rawUrl, $vars), $headers, $body, '', 0, [], '');
            }
        }
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * `{{name}}` → value. Names may carry digits (`v2_created_at`,
     * `checkout_expires_at`); an unknown name is left as it came so the
     * failure names it.
     *
     * @param array<string, string> $vars
     */
    private static function sub(string $text, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{([a-z0-9_]+)\}\}/i', static fn (array $m): string => $vars[$m[1]] ?? $m[0], $text);
    }
}
