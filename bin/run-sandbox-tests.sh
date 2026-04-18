#!/usr/bin/env bash
# Run Paygent sandbox API integration tests inside wp-env tests-cli container.
#
# Usage:
#   PAYGENT_TEST_MID=xxx PAYGENT_TEST_CID=yyy PAYGENT_TEST_CPASS=zzz \
#     composer test:sandbox
#
# Hash-check mode (no client cert required):
#   PAYGENT_TEST_MID=xxx PAYGENT_TEST_CID=yyy PAYGENT_TEST_CPASS=zzz \
#   PAYGENT_TEST_HASH_CHECK=1 PAYGENT_TEST_HASH_CODE=hhh \
#     composer test:sandbox

set -e

PLUGIN_PATH="/var/www/html/wp-content/plugins/woocommerce-for-paygent-payment-main"

# Pass through sandbox credential env vars to the container.
ENV_FLAGS=""
for var in PAYGENT_TEST_MID PAYGENT_TEST_CID PAYGENT_TEST_CPASS \
           PAYGENT_TEST_HASH_CHECK PAYGENT_TEST_HASH_CODE PAYGENT_TEST_PREFIX; do
  if [ -n "${!var}" ]; then
    ENV_FLAGS="${ENV_FLAGS} -e ${var}=${!var}"
  fi
done

npx wp-env run tests-cli sh -c \
  "cd ${PLUGIN_PATH} && \
   php vendor/bin/phpunit -c phpunit-sandbox.xml.dist 2>&1"
