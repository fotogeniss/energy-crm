<?php
/**
 * Plugin Name:       Energy CRM
 * Plugin URI:        https://example.gr/energy-crm
 * Description:       CRM για ενεργειακούς συνεργάτες — αιτήσεις/συμβάσεις παρόχων με AI εξαγωγή στοιχείων από έγγραφα (ταυτότητα + λογαριασμός) μέσω Claude.
 * Version:           0.63.0
 * Author:            Konstantinos
 * Text Domain:       energy-crm
 * Domain Path:       /languages
 * Requires PHP:      8.0
 * Requires at least: 6.2
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ECRM_VERSION', '0.63.0' );
define( 'ECRM_FILE', __FILE__ );
define( 'ECRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'ECRM_URL', plugin_dir_url( __FILE__ ) );
define( 'ECRM_PREFIX', 'ecrm_' ); // option / meta prefix

// --- Includes ---------------------------------------------------------------
require_once ECRM_DIR . 'includes/class-ecrm-db.php';
require_once ECRM_DIR . 'includes/class-ecrm-docs.php';
require_once ECRM_DIR . 'includes/class-ecrm-validate.php';
require_once ECRM_DIR . 'includes/class-ecrm-audit.php';
require_once ECRM_DIR . 'includes/class-ecrm-files.php';
require_once ECRM_DIR . 'includes/class-ecrm-ratelimit.php';
require_once ECRM_DIR . 'includes/class-ecrm-security.php';
require_once ECRM_DIR . 'includes/class-ecrm-providers.php';
require_once ECRM_DIR . 'includes/class-ecrm-extractor.php';
require_once ECRM_DIR . 'includes/class-ecrm-export.php';
require_once ECRM_DIR . 'includes/class-ecrm-pdf.php';
require_once ECRM_DIR . 'includes/class-ecrm-formfill.php';
require_once ECRM_DIR . 'includes/class-ecrm-import.php';
require_once ECRM_DIR . 'includes/class-ecrm-assistant.php';
require_once ECRM_DIR . 'includes/class-ecrm-rest.php';
require_once ECRM_DIR . 'includes/class-ecrm-notifications.php';
require_once ECRM_DIR . 'includes/class-ecrm-tasks.php';
require_once ECRM_DIR . 'includes/class-ecrm-kb.php';
require_once ECRM_DIR . 'includes/class-ecrm-leads.php';
require_once ECRM_DIR . 'includes/class-ecrm-messaging.php';
require_once ECRM_DIR . 'admin/class-ecrm-admin.php';
require_once ECRM_DIR . 'admin/class-ecrm-providers-admin.php';
require_once ECRM_DIR . 'admin/class-ecrm-commissions.php';
require_once ECRM_DIR . 'admin/class-ecrm-payouts.php';
require_once ECRM_DIR . 'admin/class-ecrm-gdpr.php';
require_once ECRM_DIR . 'admin/class-ecrm-kb-admin.php';
require_once ECRM_DIR . 'public/class-ecrm-shortcodes.php';
require_once ECRM_DIR . 'public/class-ecrm-app.php';
require_once ECRM_DIR . 'public/class-ecrm-sign-page.php';
require_once ECRM_DIR . 'public/class-ecrm-tracking.php';

// --- Activation / deactivation ---------------------------------------------
register_activation_hook( __FILE__, function () {
	ECRM_DB::install();          // create tables
	ECRM_Files::dir();           // create + harden the protected upload dir
	ECRM_DB::install_roles();    // create roles/capabilities
	ECRM_Providers::seed();      // seed default providers + programs (idempotent)
	ECRM_Providers::backfill();  // logos + Orizon + mobile for fresh/old installs
	add_option( ECRM_PREFIX . 'db_version', ECRM_DB::DB_VERSION );
	ECRM_Notifications::schedule();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	ECRM_Notifications::unschedule();
	wp_clear_scheduled_hook( ECRM_REST::AUTO_PROCESS_HOOK );
	wp_clear_scheduled_hook( ECRM_REST::AUTO_PROCESS_HOOK . '_sweep' );
	flush_rewrite_rules();
} );

// --- Boot -------------------------------------------------------------------
add_action( 'plugins_loaded', function () {
	// Run lightweight migrations if the schema version bumped between releases.
	if ( get_option( ECRM_PREFIX . 'db_version' ) !== ECRM_DB::DB_VERSION ) {
		ECRM_DB::install();
		ECRM_DB::install_roles();
		ECRM_Providers::backfill();
		update_option( ECRM_PREFIX . 'db_version', ECRM_DB::DB_VERSION );
	}

	ECRM_Admin::init();
	ECRM_Providers_Admin::init();
	ECRM_Commissions::init();
	ECRM_Payouts::init();
	ECRM_GDPR::init();
	ECRM_KB_Admin::init();
	ECRM_Security::init();
	ECRM_REST::init();
	ECRM_Notifications::init();
	ECRM_Tasks::init();
	ECRM_KB::init();
	ECRM_Leads::init();
	ECRM_Messaging::init();
	ECRM_Assistant::init();
	ECRM_Shortcodes::init();
	ECRM_App::init();
	ECRM_Sign_Page::init();
	ECRM_Tracking::init();
} );
