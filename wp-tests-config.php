<?php
/**
 * WordPress test configuration for wp-env integration tests.
 *
 * Used by wp-phpunit/wp-phpunit when running integration tests inside the
 * wp-env tests-cli container via `composer test:integration:wp-env`.
 *
 * Environment variable WP_PHPUNIT__TESTS_CONFIG must point to this file.
 * Values here match the wp-env tests container defaults.
 */

define( 'ABSPATH', '/var/www/html/' );
define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'tests-wordpress' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASS' ) ?: 'password' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: 'tests-mysql' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Paygent Integration Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

$table_prefix = getenv( 'WP_TESTS_TABLE_PREFIX' ) ?: 'wptests_';
