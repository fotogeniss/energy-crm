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

		/*
		 * No `--` comments inside these statements. Ever.
		 *
		 * dbDelta does not parse SQL; it reads the CREATE TABLE line by line and
		 * treats everything after the column name as that column's definition —
		 * trailing comment included. It then emits
		 *
		 *     ALTER TABLE ... CHANGE COLUMN `afm` afm VARCHAR(20) NULL, -- ΑΦΜ
		 *
		 * which MySQL rejects. Every column change this plugin has ever needed
		 * failed exactly this way, silently, because $wpdb hides errors in
		 * production. That is the real reason the schema kept "losing" columns
		 * and why the EnsureLegacyColumns migration had to exist.
		 *
		 * Column vocabulary belongs above the statement, where it costs nothing.
		 */

		// --- providers ------------------------------------------------------
		// energy_types: comma list of power|gas.
		dbDelta( "CREATE TABLE {$p}providers (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug         VARCHAR(64)  NOT NULL,
			name         VARCHAR(128) NOT NULL,
			energy_types VARCHAR(32)  NOT NULL DEFAULT 'power,gas',
			logo_url     VARCHAR(255) NULL,
			active       TINYINT(1)   NOT NULL DEFAULT 1,
			sort_order   INT          NOT NULL DEFAULT 0,
			created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset};" );

		// --- programs -------------------------------------------------------
		// energy_type: power|gas
		// category:    home|business|communal
		// price_type:  fixed|special|variable|dynamic
		dbDelta( "CREATE TABLE {$p}programs (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider_id  BIGINT UNSIGNED NOT NULL,
			name         VARCHAR(160) NOT NULL,
			energy_type  VARCHAR(8)   NOT NULL DEFAULT 'power',
			category     VARCHAR(16)  NOT NULL DEFAULT 'home',
			price_type   VARCHAR(16)  NOT NULL DEFAULT 'fixed',
			active       TINYINT(1)   NOT NULL DEFAULT 1,
			sort_order   INT          NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY provider_id (provider_id),
			KEY energy_type (energy_type)
		) {$charset};" );

		// --- customers ------------------------------------------------------
		// customer_type: individual|sole_prop|company
		// afm ΑΦΜ · doy ΔΟΥ · father_name πατρώνυμο · adt ΑΔΤ ή διαβατήριο
		// region Νομός · city Πόλη · street Οδός · street_no Αριθμός · postal_code ΤΚ
		//
		// afm, adt, street, street_no and postal_code are wider than their
		// contents need because CustomerFields may store them encrypted, and
		// ciphertext is several times longer than the value. A column too
		// narrow for it truncates instead of failing on a non-strict server,
		// which loses the value permanently. See WidenEncryptedColumns.
		dbDelta( "CREATE TABLE {$p}customers (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_type VARCHAR(16) NOT NULL DEFAULT 'individual',
			afm           VARCHAR(255) NULL,
			doy           VARCHAR(80)  NULL,
			first_name    VARCHAR(120) NULL,
			last_name     VARCHAR(120) NULL,
			father_name   VARCHAR(120) NULL,
			company_name  VARCHAR(200) NULL,
			adt           VARCHAR(255) NULL,
			birth_date    DATE         NULL,
			region        VARCHAR(120) NULL,
			city          VARCHAR(120) NULL,
			street        VARCHAR(512) NULL,
			street_no     VARCHAR(255) NULL,
			postal_code   VARCHAR(255) NULL,
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
		// code:            human ref, e.g. APP-0001
		// partner_user_id: WP user — ο συνεργάτης
		// energy_type:     power|gas
		// activation_type: change_provider|succession|reconnection|renewal|
		//                  new_connection|program_change
		// supply_number:   ΗΚΑΣΠ / αριθμός παροχής
		// meter_number:    αριθμός μετρητή
		// invoice_code:    ΤΙΜΟΛΟΓΙΟ (Γ1 κλπ)
		// extracted_json:  raw AI extraction payload, kept for audit
		// extra_json:      extended fields (legal rep, contact, meter extras)
		dbDelta( "CREATE TABLE {$p}contracts (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code            VARCHAR(32)  NULL,
			partner_user_id BIGINT UNSIGNED NULL,
			customer_id     BIGINT UNSIGNED NULL,
			provider_id     BIGINT UNSIGNED NULL,
			program_id      BIGINT UNSIGNED NULL,
			energy_type     VARCHAR(8)  NOT NULL DEFAULT 'power',
			category        VARCHAR(16) NOT NULL DEFAULT 'home',
			price_type      VARCHAR(16) NULL,
			customer_type   VARCHAR(16) NOT NULL DEFAULT 'individual',
			activation_type VARCHAR(24) NULL,
			supply_number   VARCHAR(40) NULL,
			meter_number    VARCHAR(40) NULL,
			invoice_code    VARCHAR(20) NULL,
			status          VARCHAR(24) NOT NULL DEFAULT 'draft',
			notes           TEXT NULL,
			extracted_json  LONGTEXT NULL,
			extra_json      LONGTEXT NULL,
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
		// attachment_id: WP media id, for documents still in the library
		// doc_kind:      id_card|provider_bill|other
		dbDelta( "CREATE TABLE {$p}files (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_id BIGINT UNSIGNED NULL,
			attachment_id BIGINT UNSIGNED NULL,
			doc_kind    VARCHAR(24) NOT NULL DEFAULT 'other',
			filename    VARCHAR(255) NULL,
			mime        VARCHAR(80)  NULL,
			path        VARCHAR(500) NULL,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY contract_id (contract_id)
		) {$charset};" );

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

		// --- payouts --------------------------------------------------------
		// period: e.g. 2026-06, or 'all'
		// status: pending|paid
		dbDelta( "CREATE TABLE {$p}payouts (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_user_id BIGINT UNSIGNED NOT NULL,
			period          VARCHAR(16) NULL,
			cnt             INT NOT NULL DEFAULT 0,
			amount          DECIMAL(10,2) NOT NULL DEFAULT 0,
			status          VARCHAR(16) NOT NULL DEFAULT 'pending',
			note            VARCHAR(255) NULL,
			created_by      BIGINT UNSIGNED NULL,
			created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			paid_at         DATETIME NULL,
			PRIMARY KEY (id),
			KEY partner_user_id (partner_user_id),
			KEY status (status)
		) {$charset};" );

		// --- tasks ----------------------------------------------------------
		// priority: low|normal|high
		// status:   open|done
		dbDelta( "CREATE TABLE {$p}tasks (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_id  BIGINT UNSIGNED NULL,
			customer_id  BIGINT UNSIGNED NULL,
			assigned_to  BIGINT UNSIGNED NOT NULL,
			created_by   BIGINT UNSIGNED NULL,
			title        VARCHAR(255) NOT NULL,
			note         TEXT NULL,
			due_at       DATETIME NULL,
			priority     VARCHAR(8) NOT NULL DEFAULT 'normal',
			status       VARCHAR(16) NOT NULL DEFAULT 'open',
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			done_at      DATETIME NULL,
			PRIMARY KEY (id),
			KEY assigned_to (assigned_to),
			KEY contract_id (contract_id),
			KEY status (status),
			KEY due_at (due_at)
		) {$charset};" );

		// --- leads ----------------------------------------------------------
		// source:      phone|chatbot|referral|walk_in|social|other
		// energy_type: power|gas|mobile|'' (όλα)
		// stage:       new|contacted|callback|qualified|won|lost
		dbDelta( "CREATE TABLE {$p}leads (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_user_id BIGINT UNSIGNED NULL,
			name          VARCHAR(160) NOT NULL,
			phone         VARCHAR(40) NULL,
			email         VARCHAR(160) NULL,
			source        VARCHAR(32) NULL,
			energy_type   VARCHAR(16) NULL,
			stage         VARCHAR(16) NOT NULL DEFAULT 'new',
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

		// --- knowledge base --------------------------------------------------
		// energy_type:   power|gas|'' (όλα)
		// section:       docs|guarantees|charges|other
		// customer_type: home|business|'' (όλα)
		dbDelta( "CREATE TABLE {$p}kb_entries (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider_id   BIGINT UNSIGNED NULL,
			provider_name VARCHAR(120) NULL,
			energy_type   VARCHAR(16) NULL,
			section       VARCHAR(24) NOT NULL DEFAULT 'docs',
			customer_type VARCHAR(16) NULL,
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

		// --- events (status history / audit) ---------------------------------
		// type: status_change|note|created|extracted
		dbDelta( "CREATE TABLE {$p}events (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_id BIGINT UNSIGNED NOT NULL,
			user_id     BIGINT UNSIGNED NULL,
			type        VARCHAR(40) NOT NULL,
			from_status VARCHAR(24) NULL,
			to_status   VARCHAR(24) NULL,
			message     TEXT NULL,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY contract_id (contract_id)
		) {$charset};" );

		// --- notifications ---------------------------------------------------
		// type: signed|status|system
		dbDelta( "CREATE TABLE {$p}notifications (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id     BIGINT UNSIGNED NOT NULL,
			contract_id BIGINT UNSIGNED NULL,
			type        VARCHAR(40) NOT NULL,
			title       VARCHAR(190) NOT NULL,
			body        TEXT NULL,
			read_at     DATETIME NULL,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY user_unread (user_id, read_at)
		) {$charset};" );

	}

	/**
	 * Canonical status list (slug => Greek label). Mirrors the PSS-style pipeline.
	 *
	 * @return array<string,string>
	 */
	public static function statuses(): array {
		return \EnergyCRM\Domain\Contract\ContractStatus::labels();
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
			// Κινητή: το αντίστοιχο της «αλλαγής παρόχου», αλλά με δικό της
			// έντυπο — ο πελάτης κρατάει τον αριθμό του.
			'portability'     => 'Φορητότητα',
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

	/**
	 * Create the roles and align their capabilities with the matrix.
	 *
	 * Delegates to EnergyCRM\Access\Roles, which is the single place defining
	 * who may do what. add_role() alone was not enough: it is a no-op once the
	 * role exists, so an installed site kept its original capabilities forever
	 * and revoking one was impossible.
	 */
	public static function install_roles(): void {
		\EnergyCRM\Access\Roles::sync();
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
		$payable = [];
		foreach ( \EnergyCRM\Domain\Contract\ContractStatus::cases() as $status ) {
			if ( $status->isPayable() ) {
				$payable[] = $status->value;
			}
		}
		return $payable;
	}

	/** Greek label for an energy/service type. */
	public static function energy_label( string $t ): string {
		$m = [ 'power' => 'Ηλεκτρισμός', 'gas' => 'Φυσικό Αέριο', 'mobile' => 'Κινητή Τηλεφωνία' ];
		return $m[ $t ] ?? 'Ηλεκτρισμός';
	}

}
