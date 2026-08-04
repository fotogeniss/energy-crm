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

declare( strict_types=1 );

namespace EnergyCRM;

use EnergyCRM\Legacy\Loader as LegacyLoader;
use ECRM_DB;
use ECRM_Files;
use ECRM_Notifications;
use ECRM_Providers;
use ECRM_REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {

	/** Option holding the schema version currently applied to the database. */
	public const VERSION_OPTION = 'ecrm_db_version';

	public static function activate(): void {
		LegacyLoader::load_files();

		ECRM_DB::install();
		ECRM_Files::dir();
		ECRM_DB::install_roles();
		ECRM_Providers::seed();
		ECRM_Providers::backfill();

		update_option( self::VERSION_OPTION, ECRM_DB::DB_VERSION );

		ECRM_Notifications::schedule();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		LegacyLoader::load_files();

		ECRM_Notifications::unschedule();
		wp_clear_scheduled_hook( ECRM_REST::AUTO_PROCESS_HOOK );
		wp_clear_scheduled_hook( ECRM_REST::AUTO_PROCESS_HOOK . '_sweep' );
		flush_rewrite_rules();
	}

	/**
	 * Apply pending schema changes when the stored version lags the code.
	 * Cheap no-op on the vast majority of requests (one option read).
	 */
	public static function maybe_upgrade(): void {
		LegacyLoader::load_files();

		if ( get_option( self::VERSION_OPTION ) === ECRM_DB::DB_VERSION ) {
			return;
		}

		ECRM_DB::install();
		ECRM_DB::install_roles();
		ECRM_Providers::backfill();

		update_option( self::VERSION_OPTION, ECRM_DB::DB_VERSION );
	}
}
