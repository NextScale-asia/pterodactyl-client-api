#!/usr/bin/env bash
# Run this package's integration tests inside a Pterodactyl panel checkout.
#
# Usage: scripts/test-in-panel.sh <path-to-panel-source> [phpunit args...]
#   e.g. scripts/test-in-panel.sh ../Pterodactyl_Base/panel-1.15.1/panel-1.15.1
#        scripts/test-in-panel.sh ../panel --filter testKeyLimitIsApplied
#
# Needs only Docker. The panel source is mounted read-only and copied inside the
# container (bind-mount I/O on Windows is too slow for composer); it is never modified.
# Env: PHP_VERSION (default 8.3), DB_IMAGE (default mariadb:11), KEEP=1 keeps the
# database container and network for debugging.
set -euo pipefail

PANEL_SRC="${1:?usage: $0 <path-to-panel-source> [phpunit args...]}"
shift
PHP_VERSION="${PHP_VERSION:-8.3}"
DB_IMAGE="${DB_IMAGE:-mariadb:11}"

ADDON_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PANEL_SRC="$(cd "$PANEL_SRC" && pwd)"
[[ -f "$PANEL_SRC/artisan" ]] || { echo "not a panel checkout: $PANEL_SRC" >&2; exit 1; }

# Git Bash: give Docker Windows-style paths and stop MSYS from rewriting /addon etc.
export MSYS_NO_PATHCONV=1
hostpath() { (cd "$1" && (pwd -W 2>/dev/null || pwd)); }

ID="pca-test-$$"
cleanup() {
  if [[ "${KEEP:-}" == 1 ]]; then
    echo "kept: container $ID-db, network $ID"
    return
  fi
  docker rm -f "$ID-db" >/dev/null 2>&1 || true
  docker network rm "$ID" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> building PHP $PHP_VERSION image"
docker build -q -t "pca-test-php:$PHP_VERSION" - >/dev/null <<EOF
FROM php:${PHP_VERSION}-cli
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update && apt-get install -y --no-install-recommends git unzip mariadb-client && rm -rf /var/lib/apt/lists/* \
 && install-php-extensions bcmath gd intl pdo_mysql zip @composer \
 && mkdir -p /etc/mysql/conf.d && printf '[client]\nssl-verify-server-cert=off\n' > /etc/mysql/conf.d/zz-test.cnf
EOF

echo "==> starting $DB_IMAGE"
docker network create "$ID" >/dev/null
docker run -d --name "$ID-db" --network "$ID" \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=testing "$DB_IMAGE" >/dev/null

echo "==> installing panel + package, running tests"
docker run --rm --network "$ID" \
  -v "$(hostpath "$PANEL_SRC"):/src:ro" \
  -v "$(hostpath "$ADDON_DIR"):/addon:ro" \
  -v pca-test-composer-cache:/root/.composer/cache \
  -e DB_HOST="$ID-db" -e DB_USERNAME=root -e COMPOSER_PROCESS_TIMEOUT=0 \
  "pca-test-php:$PHP_VERSION" bash -euo pipefail -c '
    mkdir /panel && cd /panel
    tar -C /src --exclude=./node_modules --exclude=./vendor -cf - . | tar -xf -
    cp .env.ci .env
    composer config repositories.addon "{\"type\":\"path\",\"url\":\"/addon\",\"options\":{\"symlink\":false}}"
    composer require "nextscale-asia/pterodactyl-client-api:@dev" --no-interaction --no-progress -q
    mkdir -p tests/Integration/Api/Application/Users
    cp /addon/tests/Integration/*.php tests/Integration/Api/Application/Users/

    for i in $(seq 1 60); do
      php -r "try { new PDO(\"mysql:host=\" . getenv(\"DB_HOST\"), \"root\", \"\"); exit(0); } catch (Throwable \$e) { exit(1); }" && break
      sleep 2
    done

    vendor/bin/phpunit \
      tests/Integration/Api/Application/Users/UserApiKeyControllerTest.php \
      tests/Integration/Api/Application/Users/FreeAllocationControllerTest.php \
      tests/Integration/Api/Application/Users/ServerTransferControllerTest.php \
      tests/Integration/Api/Application/Users/EggVariableControllerTest.php "$@"
  ' bash "$@"
