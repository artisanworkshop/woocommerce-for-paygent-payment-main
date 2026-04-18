#!/usr/bin/env bash
#
# Run PHP integration tests inside the wp-env tests-cli container.
#
# Usage:
#   ./bin/run-integration-tests.sh
#   composer test:integration:wp-env
#
set -euo pipefail

PLUGIN_SLUG="woocommerce-for-paygent-payment-main"
CONTAINER_PLUGIN_DIR="/var/www/html/wp-content/plugins/${PLUGIN_SLUG}"

echo "==> Running integration tests in wp-env tests-cli..."

npx wp-env run tests-cli -- bash -c "
  set -e
  cd ${CONTAINER_PLUGIN_DIR}

  # Install composer dependencies if needed.
  if [ ! -d vendor/phpunit ]; then
    echo 'Installing composer dependencies...'
    composer install --no-interaction --quiet
  fi

  # Run integration tests.
  php vendor/bin/phpunit \
    --configuration phpunit-integration.xml.dist \
    --colors=true \
    2>&1
"
