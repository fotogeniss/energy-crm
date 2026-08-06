<?php

/**
 * Boots a real WordPress so the risky layer can finally be tested.
 *
 * The unit suite deliberately runs without WordPress, and that constraint has
 * kept the domain honest. What it cannot reach is the part where the money and
 * the personal data actually live: whether a repository query really excludes
 * another partner's contracts, whether an encrypted column really comes back
 * readable, whether erasure really leaves nothing behind. Those are statements
 * about SQL and about WordPress, and only a real database can settle them.
 *
 * ## The destructive part, said first
 *
 * WordPress' test library DROPS EVERY TABLE in the database it is given, at
 * the start of every run. Pointed at the site's own database it would delete
 * every customer, contract and signature this plugin exists to protect.
 *
 * So it will not start unless a database has been named explicitly, and it
 * refuses outright if that name matches the one wp-config.php uses. No default,
 * no fallback, no "probably fine".
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

$ecrmPluginDir = dirname(__DIR__, 2);

/**
 * Stop with an explanation rather than a stack trace.
 */
$ecrmAbort = static function (string $message): never {
    fwrite(STDERR, "\n\033[31mIntegration tests not started.\033[0m\n\n" . $message . "\n\n");
    exit(1);
};

// --- The database ----------------------------------------------------------

$ecrmDbName = getenv('ECRM_TEST_DB_NAME') ?: '';

if ($ecrmDbName === '') {
    $ecrmAbort(
        "No test database named.\n\n"
        . "These tests drop every table in the database they are given, so there is\n"
        . "no default. Create a throwaway database and name it:\n\n"
        . "    set ECRM_TEST_DB_NAME=energy_crm_tests    (Windows)\n"
        . "    export ECRM_TEST_DB_NAME=energy_crm_tests (macOS / Linux)\n\n"
        . 'See docs/TESTING.md.'
    );
}

// User, password and host are read in wp-tests-config.php, which both this
// process and the installer subprocess load. Only the name is checked here,
// because only the name can destroy something.

// --- The guard against the wrong database ----------------------------------

$ecrmWpRoot = getenv('ECRM_TEST_WP_ROOT') ?: dirname($ecrmPluginDir, 3);
$ecrmWpConfig = $ecrmWpRoot . '/wp-config.php';

if (is_readable($ecrmWpConfig)) {
    // Read, never include: including it would connect to the live database and
    // define constants we are about to define differently.
    $ecrmConfigSource = (string) file_get_contents($ecrmWpConfig);

    if (preg_match("/define\(\s*'DB_NAME'\s*,\s*'([^']*)'/", $ecrmConfigSource, $ecrmMatch) === 1) {
        if (strcasecmp($ecrmMatch[1], $ecrmDbName) === 0) {
            $ecrmAbort(
                "ECRM_TEST_DB_NAME is the site's own database (\"{$ecrmDbName}\").\n\n"
                . "Running would drop every table in it, including every customer,\n"
                . 'contract and signature. Point it at a throwaway database instead.'
            );
        }
    }
}

// --- The WordPress test library --------------------------------------------

$ecrmTestLib = getenv('WP_TESTS_DIR') ?: $ecrmPluginDir . '/vendor/wp-phpunit/wp-phpunit';

if (! is_readable($ecrmTestLib . '/includes/functions.php')) {
    $ecrmAbort(
        "The WordPress test library is missing.\n\n"
        . "    composer update\n\n"
        . 'installs it (wp-phpunit/wp-phpunit). Or point WP_TESTS_DIR at your own copy.'
    );
}

/*
 * Hand over the configuration as a file path, not as constants.
 *
 * WordPress installs the test database by spawning a separate PHP process,
 * which inherits nothing defined here. It is given this path instead, and both
 * processes then read the same file. Two env var names because the library has
 * used both; setting the one that is not read costs nothing.
 */
$ecrmConfigFile = __DIR__ . '/wp-tests-config.php';

putenv('WP_PHPUNIT__TESTS_CONFIG=' . $ecrmConfigFile);
putenv('WP_TESTS_CONFIG_FILE_PATH=' . $ecrmConfigFile);

require_once $ecrmTestLib . '/includes/functions.php';

// The plugin has to be active before WordPress finishes loading, or none of
// its hooks — or its tables — exist by the time a test runs.
tests_add_filter('muplugins_loaded', static function () use ($ecrmPluginDir): void {
    require $ecrmPluginDir . '/energy-crm.php';
});

// Activation is what creates the schema. Without it every query hits a missing
// table and the failures say nothing useful.
tests_add_filter('wp_install', static function (): void {
    \EnergyCRM\Installer::activate();
}, 1);

require $ecrmTestLib . '/includes/bootstrap.php';
