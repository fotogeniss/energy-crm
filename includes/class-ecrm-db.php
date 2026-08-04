<?php
/**
 * Database schema + helpers for Energy CRM.
 *
 * Tables (prefixed with $wpdb->prefix . 'ecrm_'):
 *   providers      — energy suppliers (ΔΕΗ, PROTERGIA, VOLTON, …)
 *   programs       — tariff programs per provider
 *   customers      — end customers (individuals / sole props / companies)
 *   contracts      — applications / contracts (the core record)
 *   files          — uploaded documents linked to a contract
 *   events         — audit/status-history log per contract
 *
 * Designed so future sessions can bolt on: network/team, commissions, mail.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_DB {

	/** Bump when schema changes to trigger migration on plugins_loaded. */
	const DB_VERSION = '16';

	/** @return string Fully-qualified table name. */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'ecrm_' . $name;
	}

	/**
	 * Create / upgrade all tables. Safe to run repeatedly (dbDelta).
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'ecrm_';

		// --- providers ------------------------------------------------------
		dbDelta( "CREATE TABLE {$p}providers (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug         VARCHAR(64)  NOT NULL,
			name         VARCHAR(128) NOT NULL,
			energy_types VARCHAR(32)  NOT NULL DEFAULT 'power,gas',  -- comma list: power|gas
			logo_url     VARCHAR(255) NULL,
			active       TINYINT(1)   NOT NULL DEFAULT 1,
			sort_order   INT          NOT NULL DEFAULT 0,
			created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset};" );

		// --- programs -------------------------------------------------------
		dbDelta( "CREATE TABLE {$p}programs (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider_id  BIGINT UNSIGNED NOT NULL,
			name         VARCHAR(160) NOT NULL,
			energy_type  VARCHAR(8)   NOT NULL DEFAULT 'power',     -- power|gas
			category     VARCHAR(16)  NOT NULL DEFAULT 'home',      -- home|business|communal
			price_type   VARCHAR(16)  NOT NULL DEFAULT 'fixed',     -- fixed|special|variable|dynamic
			active       TINYINT(1)   NOT NULL DEFAULT 1,
			sort_order   INT          NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY provider_id (provider_id),
			KEY energy_type (energy_type)
		) {$charset};" );

		// --- customers ------------------------------------------------------
		dbDelta( "CREATE TABLE {$p}customers (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_type VARCHAR(16) NOT NULL DEFAULT 'individual', -- individual|sole_prop|company
			afm           VARCHAR(20)  NULL,   -- ΑΦΜ
			doy           VARCHAR(80)  NULL,   -- ΔΟΥ
			first_name    VARCHAR(120) NULL,
			last_name     VARCHAR(120) NULL,
			father_name   VARCHAR(120) NULL,   -- πατρώνυμο
			company_name  VARCHAR(200) NULL,
			adt           VARCHAR(40)  NULL,   -- ΑΔΤ / διαβατήριο
			birth_date    DATE         NULL,
			region        VARCHAR(120) NULL,   -- Νομός
			city          VARCHAR(120) NULL,   -- Πόλη
			street        VARCHAR(180) NULL,   -- Οδός
			street_no     VARCHAR(20)  NULL,   -- Αριθμός
			postal_code   VARCHAR(12)  NULL,   -- ΤΚ
			phone         VARCHAR(40)  NULL,
			mobile        VARCHAR(40)  NULL,
			email         VARCHAR(160) NULL,
			created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY afm (afm),
			KEY last_name (last_name)
		) {$charset};" );

		// --- contracts (applications) --------------------------------------
		dbDelta( "CREATE TABLE {$p}contracts (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code            VARCHAR(32)  NULL,   -- human ref e.g. APP-0001
			partner_user_id BIGINT UNSIGNED NULL, -- WP user (the συνεργάτης)
			customer_id     BIGINT UNSIGNED NULL,
			provider_id     BIGINT UNSIGNED NULL,
			program_id      BIGINT UNSIGNED NULL,
			energy_type     VARCHAR(8)  NOT NULL DEFAULT 'power',  -- power|gas
			category        VARCHAR(16) NOT NULL DEFAULT 'home',
			price_type      VARCHAR(16) NULL,
			customer_type   VARCHAR(16) NOT NULL DEFAULT 'individual',
			activation_type VARCHAR(24) NULL,    -- change_provider|succession|reconnection|renewal|new_connection|program_change
			supply_number   VARCHAR(40) NULL,    -- ΗΚΑΣΠ / αριθμός παροχής
			meter_number    VARCHAR(40) NULL,    -- αριθμός μετρητή
			invoice_code    VARCHAR(20) NULL,    -- ΤΙΜΟΛΟΓΙΟ (Γ1 κλπ)
			status          VARCHAR(24) NOT NULL DEFAULT 'draft',
			notes           TEXT NULL,
			extracted_json  LONGTEXT NULL,       -- raw AI extraction payload (audit)
			extra_json      LONGTEXT NULL,       -- extended fields (legal rep, contact, meter extras)
			start_date      DATE NULL,
			term_months     INT NULL,
			end_date        DATE NULL,
			created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY partner_user_id (partner_user_id),
			KEY status (status),
			KEY provider_id (provider_id),
			KEY customer_id (customer_id)
		) {$charset};" );

		// --- files ----------------------------------------------------------
		dbDelta( "CREATE TABLE {$p}files (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_id BIGINT UNSIGNED NULL,
			attachment_id BIGINT UNSIGNED NULL, -- WP media id if stored in library
			doc_kind    VARCHAR(24) NOT NULL DEFAULT 'other', -- id_card|provider_bill|other
			filename    VARCHAR(255) NULL,
			mime        VARCHAR(80)  NULL,
			path        VARCHAR(500) NULL,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY contract_id (contract_id)
		) {$charset};" );

		// --- events (status history / audit) -------------------------------
		// --- commission rules ----------------------------------------------
		dbDelta( "CREATE TABLE {$p}commission_rules (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider_id  BIGINT UNSIGNED NULL,
			program_id   BIGINT UNSIGNED NULL,
			energy_type  VARCHAR(8)  NULL,
			category     VARCHAR(16) NULL,
			amount       DECIMAL(10,2) NOT NULL DEFAULT 0,
			active       TINYINT(1) NOT NULL DEFAULT 1,
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY provider_id (provider_id)
		) {$charset};" );

		// --- signatures -----------------------------------------------------
		dbDelta( "CREATE TABLE {$p}signatures (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_id  BIGINT UNSIGNED NOT NULL,
			token        VARCHAR(48) NOT NULL,
			signer_name  VARCHAR(160) NULL,
			image        LONGTEXT NULL,
			signed_at    DATETIME NULL,
			ip           VARCHAR(64) NULL,
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY token (token),
			KEY contract_id (contract_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$p}payouts (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_user_id BIGINT UNSIGNED NOT NULL,
			period          VARCHAR(16) NULL,           -- e.g. 2026-06 or 'all'
			cnt             INT NOT NULL DEFAULT 0,
			amount          DECIMAL(10,2) NOT NULL DEFAULT 0,
			status          VARCHAR(16) NOT NULL DEFAULT 'pending', -- pending|paid
			note            VARCHAR(255) NULL,
			created_by      BIGINT UNSIGNED NULL,
			created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			paid_at         DATETIME NULL,
			PRIMARY KEY (id),
			KEY partner_user_id (partner_user_id),
			KEY status (status)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$p}tasks (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_id  BIGINT UNSIGNED NULL,
			customer_id  BIGINT UNSIGNED NULL,
			assigned_to  BIGINT UNSIGNED NOT NULL,
			created_by   BIGINT UNSIGNED NULL,
			title        VARCHAR(255) NOT NULL,
			note         TEXT NULL,
			due_at       DATETIME NULL,
			priority     VARCHAR(8) NOT NULL DEFAULT 'normal', -- low|normal|high
			status       VARCHAR(16) NOT NULL DEFAULT 'open',  -- open|done
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			done_at      DATETIME NULL,
			PRIMARY KEY (id),
			KEY assigned_to (assigned_to),
			KEY contract_id (contract_id),
			KEY status (status),
			KEY due_at (due_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$p}leads (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_user_id BIGINT UNSIGNED NULL,
			name          VARCHAR(160) NOT NULL,
			phone         VARCHAR(40) NULL,
			email         VARCHAR(160) NULL,
			source        VARCHAR(32) NULL,   -- phone|chatbot|referral|walk_in|social|other
			energy_type   VARCHAR(16) NULL,   -- power|gas|mobile|''
			stage         VARCHAR(16) NOT NULL DEFAULT 'new', -- new|contacted|callback|qualified|won|lost
			callback_at   DATETIME NULL,
			interest      VARCHAR(255) NULL,
			notes         LONGTEXT NULL,
			contract_id   BIGINT UNSIGNED NULL,
			lost_reason   VARCHAR(160) NULL,
			created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY partner_user_id (partner_user_id),
			KEY stage (stage),
			KEY callback_at (callback_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$p}kb_entries (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider_id   BIGINT UNSIGNED NULL,
			provider_name VARCHAR(120) NULL,
			energy_type   VARCHAR(16) NULL,   -- power|gas|'' (όλα)
			section       VARCHAR(24) NOT NULL DEFAULT 'docs', -- docs|guarantees|charges|other
			customer_type VARCHAR(16) NULL,   -- home|business|'' (όλα)
			title         VARCHAR(255) NOT NULL,
			body          LONGTEXT NULL,
			sort_order    INT NOT NULL DEFAULT 0,
			active        TINYINT NOT NULL DEFAULT 1,
			created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY provider_id (provider_id),
			KEY section (section)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$p}events (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_id BIGINT UNSIGNED NOT NULL,
			user_id     BIGINT UNSIGNED NULL,
			type        VARCHAR(40) NOT NULL,  -- status_change|note|created|extracted
			from_status VARCHAR(24) NULL,
			to_status   VARCHAR(24) NULL,
			message     TEXT NULL,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY contract_id (contract_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$p}notifications (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id     BIGINT UNSIGNED NOT NULL,
			contract_id BIGINT UNSIGNED NULL,
			type        VARCHAR(40) NOT NULL,  -- signed|status|system
			title       VARCHAR(190) NOT NULL,
			body        TEXT NULL,
			read_at     DATETIME NULL,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY user_unread (user_id, read_at)
		) {$charset};" );

		self::ensure_columns();
	}

	/**
	 * Canonical status list (slug => Greek label). Mirrors the PSS-style pipeline.
	 *
	 * @return array<string,string>
	 */
	public static function statuses(): array {
		return [
			'draft'             => 'Πρόχειρο',
			'new'               => 'Νέα',
			'pending_signature' => 'Προς υπογραφή',
			'awaiting_signature' => 'Αναμονή υπογραφής πελάτη',
			'signed'            => 'Υπεγράφη',
			'processing'        => 'Σε επεξεργασία',
			'pending'           => 'Εκκρεμότητα',
			'resolved'          => 'Επιλύθηκε',
			'routed'            => 'Δρομολογήθηκε',
			'active'            => 'Ενεργή',
			'cancelled'         => 'Ακυρώθηκε',
			'terminated'        => 'Τερματίστηκε',
		];
	}

	/** Activation types (slug => label). */
	public static function activation_types(): array {
		return [
			'change_provider' => 'Αλλαγή παρόχου',
			'succession'      => 'Διαδοχή',
			'reconnection'    => 'Επανασύνδεση',
			'renewal'         => 'Ανανέωση',
			'new_connection'  => 'Νέα σύνδεση',
			'program_change'  => 'Αλλαγή προγράμματος',
		];
	}

	/**
	 * Custom roles for the CRM hierarchy.
	 *
	 * @return array<string,string> role slug => display name
	 */
	public static function roles(): array {
		return [
			'ecrm_partner'   => 'Συνεργάτης',
			'ecrm_seller'    => 'Πωλητής',
			'ecrm_registrar' => 'Καταχωρητής',
		];
	}

	/** Create the roles (idempotent). Existing WP roles untouched. */
	public static function install_roles(): void {
		$base = [ 'read' => true, 'ecrm_use' => true ];
		add_role( 'ecrm_partner', 'Συνεργάτης', $base + [ 'ecrm_manage_team' => true ] );
		add_role( 'ecrm_seller', 'Πωλητής', $base );
		add_role( 'ecrm_registrar', 'Καταχωρητής', $base );

		// Grant the umbrella caps to administrators too.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'ecrm_use' );
			$admin->add_cap( 'ecrm_manage_team' );
		}
	}

	/**
	 * All user IDs visible to a given user: themselves + their whole
	 * downline (team members and sub-partners, recursively).
	 *
	 * Delegates to EnergyCRM\Persistence\NetworkRepository, which resolves the
	 * subtree with a single prefix query on the materialized path. This used to
	 * be a breadth-first walk issuing one get_users() call per node.
	 *
	 * @return int[]
	 */
	public static function visible_user_ids( int $user_id ): array {
		$network = \EnergyCRM\Services::network();

		// Administrators run the company; the partner tree describes who earns
		// commission, not who is allowed to look. See docs/ARCHITECTURE.md.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return $network->allUserIds();
		}

		return $network->subtreeIds( $user_id );
	}


	/** Statuses that count as a payable/successful contract for commissions. */
	public static function payable_statuses(): array {
		return [ 'routed', 'active', 'resolved' ];
	}

	/** Guarantee columns that dbDelta may miss (inline-comment quirks). Runs after table create. */
	public static function ensure_columns(): void {
		global $wpdb;
		$ct = self::table( 'contracts' );
		$has = $wpdb->get_results( "SHOW COLUMNS FROM {$ct} LIKE 'extra_json'" );
		if ( empty( $has ) ) {
			$wpdb->query( "ALTER TABLE {$ct} ADD COLUMN extra_json LONGTEXT NULL" );
		}
		foreach ( [ 'start_date' => 'DATE NULL', 'term_months' => 'INT NULL', 'end_date' => 'DATE NULL', 'payout_id' => 'BIGINT UNSIGNED NULL', 'consent_at' => 'DATETIME NULL', 'consent_ip' => 'VARCHAR(64) NULL', 'signed_at' => 'DATETIME NULL', 'signed_ip' => 'VARCHAR(64) NULL' ] as $col => $def ) {
			$c = $wpdb->get_results( "SHOW COLUMNS FROM {$ct} LIKE '{$col}'" );
			if ( empty( $c ) ) { $wpdb->query( "ALTER TABLE {$ct} ADD COLUMN {$col} {$def}" ); }
		}
		$pr = self::table( 'providers' );
		$hasl = $wpdb->get_results( "SHOW COLUMNS FROM {$pr} LIKE 'logo_url'" );
		if ( empty( $hasl ) ) {
			$wpdb->query( "ALTER TABLE {$pr} ADD COLUMN logo_url VARCHAR(300) NULL" );
		}
		$pg = self::table( 'programs' );
		foreach ( [ 'price_kwh' => 'DECIMAL(8,5) NULL', 'fixed_charge' => 'DECIMAL(8,2) NULL' ] as $col => $def ) {
			$c = $wpdb->get_results( "SHOW COLUMNS FROM {$pg} LIKE '{$col}'" );
			if ( empty( $c ) ) { $wpdb->query( "ALTER TABLE {$pg} ADD COLUMN {$col} {$def}" ); }
		}
		$flt = self::table( 'files' );
		$cf  = $wpdb->get_results( "SHOW COLUMNS FROM {$flt} LIKE 'protected'" );
		if ( empty( $cf ) ) { $wpdb->query( "ALTER TABLE {$flt} ADD COLUMN protected TINYINT NOT NULL DEFAULT 0" ); }
	}

	/** Greek label for an energy/service type. */
	public static function energy_label( string $t ): string {
		$m = [ 'power' => 'Ηλεκτρισμός', 'gas' => 'Φυσικό Αέριο', 'mobile' => 'Κινητή Τηλεφωνία' ];
		return $m[ $t ] ?? 'Ηλεκτρισμός';
	}

}
