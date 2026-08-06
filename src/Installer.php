<?php

/**
 * Activation, deactivation and schema-upgrade lifecycle.
 *
 * Currently delegates to the legacy ECRM_* classes so behaviour is unchanged.
 * The migration runner that replaces `ECRM_DB::install()` will be introduced
 * here, behind the same three public methods, so callers never move.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM;

use ECRM_DB;
use ECRM_Files;
use ECRM_Notifications;
use ECRM_Providers;
use ECRM_REST;
use EnergyCRM\Infrastructure\DocumentProtection;
use EnergyCRM\Infrastructure\Retention;
use EnergyCRM\Legacy\Loader as LegacyLoader;
use EnergyCRM\Persistence\Schema\MigrationList;
use EnergyCRM\Persistence\Schema\MigrationRunner;

final class Installer
{
    /** Option holding the schema version currently applied to the database. */
    public const VERSION_OPTION = 'ecrm_db_version';

    public static function activate(): void
    {
        LegacyLoader::loadFiles();

        ECRM_DB::install();
        ECRM_Files::dir();
        ECRM_DB::install_roles();
        ECRM_Providers::seed();
        ECRM_Providers::backfill();
        Services::network()->rebuildAll();

        // dbDelta has just built the tables in their final shape, so replaying
        // historical migrations would be noise. Record them as done instead.
        self::migrations()->markAllApplied();

        update_option(self::VERSION_OPTION, ECRM_DB::DB_VERSION);

        ECRM_Notifications::schedule();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        LegacyLoader::loadFiles();

        ECRM_Notifications::unschedule();
        Retention::unschedule();
        DocumentProtection::unschedule();
        wp_clear_scheduled_hook(ECRM_REST::AUTO_PROCESS_HOOK);
        wp_clear_scheduled_hook(ECRM_REST::AUTO_PROCESS_HOOK . '_sweep');
        flush_rewrite_rules();
    }

    /**
     * Apply pending schema changes when the stored version lags the code.
     * Cheap no-op on the vast majority of requests: one option read.
     */
    public static function maybeUpgrade(): void
    {
        LegacyLoader::loadFiles();

        // Migrations are cheap to check and independent of the schema version,
        // so a failed one retries on the next request instead of being stranded
        // behind a version number that already moved on.
        self::migrations()->run();

        if (get_option(self::VERSION_OPTION) === ECRM_DB::DB_VERSION) {
            return;
        }

        ECRM_DB::install();
        ECRM_DB::install_roles();
        ECRM_Providers::backfill();
        Services::network()->rebuildAll();

        update_option(self::VERSION_OPTION, ECRM_DB::DB_VERSION);
    }

    private static function migrations(): MigrationRunner
    {
        return new MigrationRunner(MigrationList::all());
    }
}
