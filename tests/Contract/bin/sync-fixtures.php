<?php

declare(strict_types=1);

/**
 * Copies the execution-generated Postman collection from the platform
 * monorepo into the SDK's contract fixtures. Run after the platform
 * regenerates it; the contract suite fails while the copy is stale.
 */
$source = __DIR__.'/../../../../../platform/packages/contracts/docs/dpay-v1.postman_collection.json';
$target = __DIR__.'/../fixtures/dpay-v1.postman_collection.json';

if (!is_file($source)) {
    fwrite(STDERR, "Platform collection not found at $source\n");
    exit(2);
}
if (!copy($source, $target)) {
    fwrite(STDERR, "Could not copy to $target\n");
    exit(1);
}
echo 'Synced '.basename($target).' ('.hash_file('sha256', $target).")\n";
