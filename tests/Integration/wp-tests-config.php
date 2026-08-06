<?php

/**
 * What WordPress needs to know before it will start, for tests.
 *
 * This exists as a file rather than as `define()` calls in the bootstrap for
 * one reason: WordPress installs the test database by spawning a **separate**
 * PHP process, and that process inherits no constants. It is handed the path
 * to this file instead. Anything defined only in the bootstrap is invisible to
 * it, which surfaces as "Undefined constant WP_TESTS_DOMAIN" from inside the
 * installer.
 *
 * Everything here comes from the environment, with no database default — see
 * bootstrap.php for why that matters.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

$ecrmPluginDir = dirname(__DIR__, 2);

// --- Database --------------------------------------------------------------

define('DB_NAME', (string) getenv('ECRM_TEST_DB_NAME'));
define('DB_USER', getenv('ECRM_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('ECRM_TEST_DB_PASSWORD') === false ? 'root' : (string) getenv('ECRM_TEST_DB_PASSWORD'));
define('DB_HOST', getenv('ECRM_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

/**
 * Fixed, not random.
 *
 * FieldCipher derives its keys from these, so a value encrypted in one test
 * must still decrypt in the next. Random salts would make encryption tests
 * fail in a way that looks like a bug in the cipher.
 */
define('AUTH_KEY', 'energy-crm-integration-auth-key');
define('SECURE_AUTH_KEY', 'energy-crm-integration-secure-auth-key');
define('LOGGED_IN_KEY', 'energy-crm-integration-logged-in-key');
define('NONCE_KEY', 'energy-crm-integration-nonce-key');
define('AUTH_SALT', 'energy-crm-integration-auth-salt');
define('SECURE_AUTH_SALT', 'energy-crm-integration-secure-auth-salt');
define('LOGGED_IN_SALT', 'energy-crm-integration-logged-in-salt');
define('NONCE_SALT', 'energy-crm-integration-nonce-salt');

// --- The test site ---------------------------------------------------------

define('WP_TESTS_DOMAIN', 'energy-crm.test');
define('WP_TESTS_EMAIL', 'admin@energy-crm.test');
define('WP_TESTS_TITLE', 'Energy CRM integration');

define('WP_PHP_BINARY', getenv('ECRM_TEST_PHP_BINARY') ?: 'php');
define('WP_DEBUG', true);

// The WordPress installation the tests run against. Three levels up from the
// plugin is the site root; ECRM_TEST_WP_ROOT overrides it for other layouts.
$ecrmWpRoot = getenv('ECRM_TEST_WP_ROOT') ?: dirname($ecrmPluginDir, 3);

define('ABSPATH', rtrim(str_replace('\\', '/', $ecrmWpRoot), '/') . '/');

// Read by name from the global scope; not ours to rename.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride
$table_prefix = 'wptests_';
