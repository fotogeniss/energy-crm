<?php

/**
 * Loader for the pre-refactor ECRM_* classes.
 *
 * This is the strangler seam. Every class listed here is scheduled to move
 * under `src/` behind a proper interface; as each one migrates it is deleted
 * from these arrays. When both arrays are empty the refactor is done and this
 * file goes away.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Legacy;

use EnergyCRM\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Loader
{
    private static bool $filesLoaded = false;

    private static bool $booted = false;

    /**
     * Legacy class files, relative to the plugin root, in dependency order.
     *
     * @return list<string>
     */
    private static function files(): array
    {
        return [
            'includes/class-ecrm-db.php',
            'includes/class-ecrm-docs.php',
            'includes/class-ecrm-validate.php',
            'includes/class-ecrm-audit.php',
            'includes/class-ecrm-files.php',
            'includes/class-ecrm-ratelimit.php',
            'includes/class-ecrm-security.php',
            'includes/class-ecrm-providers.php',
            'includes/class-ecrm-extractor.php',
            'includes/class-ecrm-export.php',
            'includes/class-ecrm-pdf.php',
            'includes/class-ecrm-formfill.php',
            'includes/class-ecrm-import.php',
            'includes/class-ecrm-assistant.php',
            'includes/class-ecrm-rest.php',
            'includes/class-ecrm-notifications.php',
            'includes/class-ecrm-tasks.php',
            'includes/class-ecrm-kb.php',
            'includes/class-ecrm-leads.php',
            'includes/class-ecrm-messaging.php',
            'admin/class-ecrm-admin.php',
            'admin/class-ecrm-providers-admin.php',
            'admin/class-ecrm-commissions.php',
            'admin/class-ecrm-payouts.php',
            'admin/class-ecrm-gdpr.php',
            'admin/class-ecrm-kb-admin.php',
            'public/class-ecrm-shortcodes.php',
            'public/class-ecrm-app.php',
            'public/class-ecrm-sign-page.php',
            'public/class-ecrm-tracking.php',
        ];
    }

    /**
     * Legacy modules exposing a static `init()` that registers WordPress hooks.
     *
     * @return list<string>
     */
    private static function modules(): array
    {
        return [
            'ECRM_Admin',
            'ECRM_Providers_Admin',
            'ECRM_Commissions',
            'ECRM_Payouts',
            'ECRM_GDPR',
            'ECRM_KB_Admin',
            'ECRM_Security',
            'ECRM_REST',
            'ECRM_Notifications',
            'ECRM_Tasks',
            'ECRM_KB',
            'ECRM_Leads',
            'ECRM_Messaging',
            'ECRM_Assistant',
            'ECRM_Shortcodes',
            'ECRM_App',
            'ECRM_Sign_Page',
            'ECRM_Tracking',
        ];
    }

    /** Require every legacy class file. Idempotent. */
    public static function loadFiles(): void
    {
        if (self::$filesLoaded) {
            return;
        }
        self::$filesLoaded = true;

        $plugin = Plugin::instance();
        $dir    = $plugin instanceof Plugin
            ? $plugin->dir()
            : trailingslashit(dirname(__DIR__, 2));

        foreach (self::files() as $relative) {
            require_once $dir . $relative;
        }
    }

    /** Load the files and register every legacy module's hooks. Idempotent. */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::loadFiles();

        foreach (self::modules() as $module) {
            if (method_exists($module, 'init')) {
                $module::init();
            }
        }
    }
}
