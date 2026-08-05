<?php

/**
 * Plugin Name:       Energy CRM
 * Plugin URI:        https://example.gr/energy-crm
 * Description:       CRM για ενεργειακούς συνεργάτες: αιτήσεις και συμβάσεις παρόχων, με AI εξαγωγή από έγγραφα.
 * Version:           0.93.0
 * Author:            Konstantinos
 * Text Domain:       energy-crm
 * Domain Path:       /languages
 * Requires PHP:      8.2
 * Requires at least: 6.2
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/*
 * Legacy constants. Still consumed by the ECRM_* classes; each one disappears
 * as its consumers migrate to EnergyCRM\Plugin. Do not use in new code.
 */
define('ECRM_VERSION', '0.93.0');
define('ECRM_FILE', __FILE__);
define('ECRM_DIR', plugin_dir_path(__FILE__));
define('ECRM_URL', plugin_dir_url(__FILE__));
define('ECRM_PREFIX', 'ecrm_');

/*
 * Autoloading. Composer is dev-only (PHPUnit / PHPStan / PHPCS), so the plugin
 * ships a standalone PSR-4 loader and uses Composer's only when it exists.
 */
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/src/Autoloader.php';
    EnergyCRM\Autoloader::register('EnergyCRM', __DIR__ . '/src');
}

EnergyCRM\Plugin::boot(__FILE__);
