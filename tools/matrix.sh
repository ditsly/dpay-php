#!/usr/bin/env bash
# Run the full gate set on PHP 8.1–8.5 in Docker (php:X.Y-cli images). Composer
# is the binary of the official `composer:2` image, copied out once and mounted
# read-only into every cell — never an installer script fetched over the network
# and run unverified. The checkout is mounted READ-ONLY and copied into the
# container without vendor/ or composer.lock, so every version resolves its own
# dependency set and the host tree is never touched.
#   tools/matrix.sh            # all five versions
#   tools/matrix.sh 8.1 8.4    # a subset
set -uo pipefail
cd "$(dirname "$0")/.."
versions=("$@")
[ ${#versions[@]} -eq 0 ] && versions=(8.1 8.2 8.3 8.4 8.5)
status=0
# The published repository ships its own copy in tools/; inside the monorepo it is
# integrations/tools/composer-bin.sh.
if [ -f "$PWD/tools/composer-bin.sh" ]; then
  # shellcheck source=composer-bin.sh
  . "$PWD/tools/composer-bin.sh"
else
  # shellcheck source=../../tools/composer-bin.sh
  . "$PWD/../tools/composer-bin.sh"
fi
composer_bin="$(composer_bin_from_image)" || { echo "could not obtain the composer binary from the composer:2 image"; exit 2; }
for v in "${versions[@]}"; do
  echo "=== PHP $v ==="
  # Inside the monorepo, also expose the platform's generated Postman collection and the
  # checkout app's message table so the fixtures-are-current and the messages-are-a-verbatim-port
  # checks run (both are skipped on a standalone checkout).
  platform_docs="$PWD/../../platform/packages/contracts/docs"
  checkout_lib="$PWD/../../platform/apps/checkout/lib"
  extra_mount=()
  [ -d "$platform_docs" ] && extra_mount+=(-v "$platform_docs:/platform/packages/contracts/docs:ro")
  [ -d "$checkout_lib" ] && extra_mount+=(-v "$checkout_lib:/platform/apps/checkout/lib:ro")
  docker run --rm -v "$PWD:/src:ro" -v "$composer_bin:/usr/local/bin/composer:ro" "${extra_mount[@]}" -w /work "php:$v-cli" bash -ec '
    set -o pipefail
    mkdir -p /work && cd /src && tar --exclude=./vendor --exclude=./composer.lock --exclude=./.phpunit.cache --exclude=./.phpstan.cache --exclude=./.phpstan.tests.cache -cf - . | tar -xf - -C /work && cd /work
    php -v | head -1
    (apt-get update -qq && apt-get install -y -qq git unzip) >/dev/null 2>&1
    export COMPOSER_ROOT_VERSION=1.0.0
    composer --version 2>/dev/null | head -1
    composer install --no-interaction --no-progress --prefer-dist 2>&1 | grep -E "^(Installing|  - Installing phpunit/phpunit|  - Installing phpstan/phpstan|  - Installing friendsofphp)" || true
    for pkg in phpunit/phpunit phpstan/phpstan friendsofphp/php-cs-fixer guzzlehttp/guzzle; do printf "%s " "$pkg"; composer show "$pkg" 2>/dev/null | grep -E "^versions" | sed "s/versions *: *//"; done
    composer validate --strict
    vendor/bin/phpunit --testsuite unit | tail -2
    vendor/bin/phpunit --testsuite contract | tail -2
    vendor/bin/phpstan analyse --memory-limit=1G --no-progress | tail -2
    vendor/bin/phpstan analyse --memory-limit=1G --no-progress -c phpstan.tests.neon | tail -2
    vendor/bin/php-cs-fixer fix --dry-run --show-progress=none 2>&1 | tail -1
  '
  rc=$?
  echo "=== PHP $v exit=$rc"
  [ $rc -ne 0 ] && status=1
done
echo "=== matrix exit=$status"
exit $status
