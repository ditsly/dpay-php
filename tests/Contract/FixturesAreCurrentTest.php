<?php

declare(strict_types=1);

namespace DPay\Tests\Contract;

use DPay\Tests\Support\Postman;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Inside the monorepo, the SDK's copy of the Postman collection must be the
 * platform's current one — `composer sync-fixtures` refreshes it. Outside
 * the monorepo (a Packagist checkout) the platform file is absent and the
 * bundled copy is the contract.
 */
final class FixturesAreCurrentTest extends TestCase
{
    #[Test]
    public function bundledCollectionMatchesThePlatformsGeneratedOne(): void
    {
        if (!is_file(Postman::PLATFORM_COPY)) {
            self::markTestSkipped('platform collection not present (standalone checkout)');
        }
        self::assertSame(
            hash_file('sha256', Postman::PLATFORM_COPY),
            hash_file('sha256', Postman::FIXTURE),
            'tests/Contract/fixtures/dpay-v1.postman_collection.json is stale — run `composer sync-fixtures` and re-check the classification table.',
        );
    }

    #[Test]
    public function collectionIsTheExecutionGeneratedOne(): void
    {
        $collection = Postman::collection();
        $info = $collection['info'] ?? null;
        self::assertIsArray($info);
        self::assertIsString($info['description'] ?? null);
        self::assertStringContainsString('produced by sending that exact request', $info['description']);
    }
}
