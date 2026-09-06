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
 *   kb_entries     — knowledge base + τα μαθήματα της «Εκπαίδευσης» (section=training)
 *   kb_read        — ποιος χρήστης δήλωσε ότι διάβασε ποιο μάθημα
 *   leads          — υποψήφιοι· και όσοι έρχονται μόνοι τους από τον «σύνδεσμό μου»
 *   events         — audit/status-history log per contract
 *   assistant_messages — Λίτσα assistant conversation, per user (build queue 14)
 *   deletion_log   — μόνιμο αρχείο admin παράκαμψης διαγραφής υπογεγραμμένων (20/DB_VERSION)
 *   guarantee_rules — κανόνες πρότασης εγγύησης ανά πάροχο/κλίμακα kVA (21/DB_VERSION)
 *   tasks.seen_at  — πότε ο χρήστης είδε την ανοιχτή/ληξιπρόθεσμη εργασία στη
 *                    δική του λίστα, για το badge του μενού (22/DB_VERSION)
 *   metrics        — ιστορικό λειτουργίας: (ημέρα, μετρητής, αριθμός), μόνο
 *                    αριθμοί, καμία σύνδεση με πρόσωπο (23/DB_VERSION)
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
	const DB_VERSION = '23';

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
		// code: σταθερό αναγνωριστικό πλάνου (π.χ. MobilePlans::P_5GB), ανεξάρτητο
		// από το name — έτσι μια μετονομασία στο wp-admin δεν σπάει ποτέ την
		// αντιστοίχιση προγράμματος → τυπωμένη τιμή. NULL για προγράμματα χωρίς
		// σταθερό κατάλογο τιμών (π.χ. ρεύμα/αέριο με ελεύθερη τιμολόγηση).
		dbDelta( "CREATE TABLE {$p}programs (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider_id  BIGINT UNSIGNED NOT NULL,
			name         VARCHAR(160) NOT NULL,
			code         VARCHAR(32)  NULL,
			energy_type  VARCHAR(8)   NOT NULL DEFAULT 'power',
			category     VARCHAR(16)  NOT NULL DEFAULT 'home',
			price_type   VARCHAR(16)  NOT NULL DEFAULT 'fixed',
			active       TINYINT(1)   NOT NULL DEFAULT 1,
			sort_order   INT          NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY provider_id (provider_id),
			KEY energy_type (energy_type),
			KEY code (code)
		) {$charset};" );

		// --- customers ------------------------------------------------------
		// customer_type: individual|sole_prop|company
		// afm ΑΦΜ · doy ΔΟΥ · father_name πατρώνυμο · adt ΑΔΤ ή διαβατήριο
		// region Νομός · city Πόλη · street Οδός · street_no Αριθμός · postal_code ΤΚ
		//
		// afm, adt, street, street_no, postal_code and phone are wider than
		// their contents need because CustomerFields may store them
		// encrypted, and ciphertext is several times longer than the value.
		// A column too narrow for it truncates instead of failing on a
		// non-strict server, which loses the value permanently. See
		// WidenEncryptedColumns and WidenCustomerPhoneColumn.
		//
		// afm_hash / phone_hash: blind indexes (see CustomerFields), kept
		// here too -- SchemaInspector's own contract is that a fresh install
		// gets every column dbDelta can give it, so the matching migrations
		// (0010, 0020) have nothing left to do on a new site and only matter
		// for upgrading one that already existed before they shipped.
		dbDelta( "CREATE TABLE {$p}customers (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_type VARCHAR(16) NOT NULL DEFAULT 'individual',
			afm           VARCHAR(255) NULL,
			afm_hash      CHAR(64)     NULL,
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
			phone         VARCHAR(255) NULL,
			phone_hash    CHAR(64)     NULL,
			mobile        VARCHAR(40)  NULL,
			email         VARCHAR(160) NULL,
			contact_phone VARCHAR(255) NULL,
			created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY afm (afm),
			KEY afm_hash (afm_hash),
			KEY phone_hash (phone_hash),
			KEY last_name (last_name)
		) {$charset};" );

		// --- customer_notes (247, Στάδιο 2) ---------------------------------
		// Ελεύθερο κείμενο ΓΙΑ έναν πελάτη, όχι πεδίο αίτησης -- δεν τυπώνεται
		// πουθενά. customer_id, ΟΧΙ contract_id: μια σημείωση αφορά τον
		// άνθρωπο, όχι μία συγκεκριμένη σύμβασή του (βλ. PersonalDataTables,
		// HANDLED_INLINE -- ίδια ακμή-δύο με το tasks.customer_id, εδώ μόνη).
		dbDelta( "CREATE TABLE {$p}customer_notes (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id     BIGINT UNSIGNED NOT NULL,
			partner_user_id BIGINT UNSIGNED NULL,
			body            TEXT NOT NULL,
			created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY customer_id (customer_id)
		) {$charset};" );

		// --- customer_events (247, Στάδιο 3) --------------------------------
		// Ιστορικό αλλαγών στοιχείων πελάτη -- ένα γραμμή ανά ΠΕΔΙΟ που άλλαξε
		// σε ένα save, όχι μία γραμμή ανά save με όλα μαζί: έτσι "ποιος άλλαξε
		// ΤΙ" απαντιέται με ένα SELECT, όχι με parse ενός JSON blob. customer_id,
		// ΟΧΙ contract_id -- ίδια ακμή-δύο με το customer_notes παραπάνω.
		// old_value/new_value κρυπτογραφημένα όταν το πεδίο είναι στο
		// CustomerFields::ENCRYPTED (βλ. CustomerEventRepository) -- ένα
		// ιστορικό αλλαγών δεν έχει λόγο να είναι λιγότερο προστατευμένο από
		// την ίδια τη στήλη που καταγράφει.
		dbDelta( "CREATE TABLE {$p}customer_events (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id     BIGINT UNSIGNED NOT NULL,
			partner_user_id BIGINT UNSIGNED NULL,
			field           VARCHAR(40) NOT NULL,
			old_value       TEXT NULL,
			new_value       TEXT NULL,
			created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY customer_id (customer_id)
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
		/*
		 * Το lead_id προστέθηκε 27/08 μαζί με τον «σύνδεσμό μου»: ο πελάτης
		 * ανεβάζει έγγραφα ΠΡΙΝ υπάρξει σύμβαση, οπότε το αρχείο κρέμεται από
		 * τον υποψήφιο. Στη μετατροπή σε αίτηση γεμίζει το contract_id και το
		 * lead_id ΜΕΝΕΙ -- η προέλευση δεν σβήνεται: φαίνεται ποια έγγραφα τα
		 * έφερε ο ίδιος ο πελάτης και ποια ο πωλητής.
		 *
		 * Δεν χρειάστηκε να γίνει nullable το contract_id. Ηταν ήδη.
		 */
		dbDelta( "CREATE TABLE {$p}files (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_id BIGINT UNSIGNED NULL,
			lead_id     BIGINT UNSIGNED NULL,
			attachment_id BIGINT UNSIGNED NULL,
			doc_kind    VARCHAR(24) NOT NULL DEFAULT 'other',
			kind_source VARCHAR(16) NULL,
			kind_before VARCHAR(24) NULL,
			filename    VARCHAR(255) NULL,
			mime        VARCHAR(80)  NULL,
			path        VARCHAR(500) NULL,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY contract_id (contract_id),
			KEY lead_id (lead_id)
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

		// --- guarantee rules ------------------------------------------------
		// Ιδιο σχήμα με τους κανόνες προμήθειας, συν την κλίμακα ισχύος:
		// kva_min/kva_max NULL σημαίνει «για κάθε ισχύ», και τα δύο όρια είναι
		// συμπεριληπτικά (βλ. Domain\Guarantee\GuaranteeMatch).
		//
		// Ο πίνακας γεννιέται άδειος και αυτό ΔΕΝ είναι σφάλμα: άδειος = καμία
		// πρόταση = η σημερινή συμπεριφορά, όπου ο πωλητής γράφει το ποσό μόνος
		// του. Γι' αυτό, αντίθετα με το commission_rules, δεν μπαίνει έλεγχος
		// υγείας που να κοκκινίζει όσο είναι άδειος — βλ. CHANGELOG (210).
		dbDelta( "CREATE TABLE {$p}guarantee_rules (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider_id  BIGINT UNSIGNED NULL,
			program_id   BIGINT UNSIGNED NULL,
			energy_type  VARCHAR(8)  NULL,
			category     VARCHAR(16) NULL,
			kva_min      DECIMAL(8,2) NULL,
			kva_max      DECIMAL(8,2) NULL,
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
			seen_at      DATETIME NULL,
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
		/*
		 * consent_at / consent_ip: μόνο για υποψηφίους που ήρθαν από τον
		 * δημόσιο «σύνδεσμό μου». Ιδιώτης στέλνει ταυτότητα μέσα από δημόσια
		 * σελίδα -- η συναίνεση είναι νομικό τεκμήριο, όχι checkbox, οπότε
		 * παίρνει δικές της στήλες αντί να χωθεί στο notes. Ιδιο σκεπτικό με
		 * τα signed_at/signed_ip της σύμβασης.
		 */
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
			consent_at    DATETIME NULL,
			consent_ip    VARCHAR(64) NULL,
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

		/*
		 * Ποιος πωλητής δήλωσε ότι διάβασε ποιο μάθημα.
		 *
		 * Ξεχωριστός πίνακας και όχι στήλη στο kb_entries, γιατί η σχέση
		 * είναι χρήστης-προς-μάθημα: μια στήλη θα κρατούσε την πρόοδο ενός
		 * μόνο ανθρώπου. Το unique key είναι ο φύλακας του διπλού κλικ.
		 */
		dbDelta( "CREATE TABLE {$p}kb_read (
			id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id  BIGINT UNSIGNED NOT NULL,
			entry_id BIGINT UNSIGNED NOT NULL,
			read_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_entry (user_id, entry_id),
			KEY user_id (user_id)
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

		// --- assistant_messages -----------------------------------------------
		// role: user|assistant. Η ιστορία της Λίτσα, ένας χρήστης τη φορά --
		// ζούσε ως τώρα μόνο σε localStorage του browser, σε καθαρό κείμενο
		// (build queue 14, docs/CHANGELOG.md). Ιδιο όριο διατήρησης με πριν:
		// οι τελευταίες 40 γραμμές ανά χρήστη -- βλ. AssistantHistoryRepository.
		dbDelta( "CREATE TABLE {$p}assistant_messages (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id    BIGINT UNSIGNED NOT NULL,
			role       VARCHAR(16) NOT NULL,
			content    TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id)
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

		// --- deletion_log ------------------------------------------------------
		// Μόνιμο αρχείο: admin παρέκαμψε την άρνηση διαγραφής υπογεγραμμένης
		// σύμβασης (DeletionGate::WAS_SIGNED). ΕΠΙΤΗΔΕΣ χωρίς foreign key και
		// χωρίς στήλη ονομασμένη ακριβώς 'contract_id' -- deleted_contract_id
		// είναι στιγμιότυπο, όχι ζωντανή σχέση, γιατί η γραμμή contracts που
		// δείχνει έχει ήδη φύγει τη στιγμή που γράφεται αυτό εδώ. Αν λεγόταν
		// 'contract_id' θα έμπαινε στη σάρωση του PersonalDataCoverageTest σαν
		// ζωντανή ακμή προς πρόσωπο -- και ΔΕΝ είναι: το αρχείο αυτό υπάρχει
		// ΓΙΑ να επιζήσει ενός αιτήματος διαγραφής, όχι για να το εξυπηρετήσει
		// (GDPR άρθρο 17§3β -- τήρηση αρχείου για νομική υποχρέωση/λογοδοσία).
		// Κρατά το ελάχιστο: κωδικό/κατάσταση σύμβασης, ποιος, πότε, γιατί --
		// όχι όνομα πελάτη, όχι ιστορικό μηνυμάτων, ώστε να μην ξαναδημιουργεί
		// σε νέο πίνακα ό,τι το PersonalDataEraser έσβησε αλλού.
		dbDelta( "CREATE TABLE {$p}deletion_log (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			deleted_contract_id BIGINT UNSIGNED NOT NULL,
			contract_code       VARCHAR(64) NOT NULL,
			status_at_deletion  VARCHAR(32) NOT NULL,
			reason              TEXT NOT NULL,
			deleted_by          BIGINT UNSIGNED NOT NULL,
			deleted_by_name     VARCHAR(190) NOT NULL,
			deleted_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY deleted_at (deleted_at)
		) {$charset};" );

		/* --- metrics (ιστορικό λειτουργίας) --------------------------------
		 *
		 * Μία γραμμή ανά (ημέρα, μετρητή). Το PRIMARY KEY στα δύο μαζί είναι
		 * ΟΛΗ η δουλειά: επιτρέπει το `INSERT … ON DUPLICATE KEY UPDATE`, που
		 * ανεβάζει τον σημερινό αριθμό ατομικά μέσα στη MySQL -- χωρίς
		 * διάβασμα-τροποποίηση-γράψιμο, άρα χωρίς χαμένες μετρήσεις όταν δύο
		 * requests μετρούν το ίδιο δευτερόλεπτο.
		 *
		 * Χωρίς AUTO_INCREMENT id: δεν υπάρχει τίποτα να δείξει κανείς σε μια
		 * γραμμή μετρητή, και το ζευγάρι είναι ήδη το κλειδί.
		 *
		 * Ογκος: ~6 μετρητές × 1 γραμμή/ημέρα = ~2.200 γραμμές/έτος, με
		 * διατήρηση 180 ημερών στο ημερήσιο σάρωμα του Retention.
		 *
		 * Μόνο αριθμοί: κανένα user_id, καμία διαδρομή, κανένα μήνυμα.
		 */
		dbDelta( "CREATE TABLE {$p}metrics (
			day    DATE NOT NULL,
			metric VARCHAR(40) NOT NULL,
			value  BIGINT NOT NULL DEFAULT 0,
			PRIMARY KEY (day, metric),
			KEY metric_day (metric, day)
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
	 * All user IDs visible to a given user.
	 *
	 * ## Δεν αποφασίζει εδώ, και αυτό ΗΤΑΝ το bug
	 *
	 * Ρωτούσε μόνη της το δίκτυο: διαχειριστής -> όλοι, οποιοσδήποτε άλλος ->
	 * ολόκληρο το υποδέντρο του. Ο σύγχρονος WordPressScopeResolver όμως ρωτά
	 * και μια τρίτη ερώτηση — αν ο χρήστης έχει `ecrm_manage_team` — και χωρίς
	 * αυτήν επιστρέφει ΜΟΝΟ τον εαυτό του.
	 *
	 * Δύο απαντήσεις στο «τι βλέπει ποιος», και η μία τις αγνοούσε τα
	 * δικαιώματα. Οι δύο καλούντες αυτής εδώ δεν είναι αθώοι: ECRM_Files::serve
	 * (ποιος κατεβάζει σαρωμένη ταυτότητα) και ECRM_Import::apply (ποιων
	 * συμβάσεων αλλάζει την κατάσταση το Excel του παρόχου).
	 *
	 * Με το σημερινό Roles::matrix() οι δύο απαντήσεις συμπίπτουν, επειδή ο
	 * μόνος ρόλος με IMPORT_DATA έχει και MANAGE_TEAM. Το Roles.php όμως
	 * υπόσχεται «άλλαξε το matrix() και τίποτα άλλο δεν χρειάζεται να κουνηθεί»
	 * — και αυτή η υπόσχεση ήταν ψευδής όσο ζούσε εδώ δεύτερη πολιτική.
	 *
	 * Η πολιτική είναι πλέον μία, στο src/Access. Εδώ μένει μόνο η επέκταση
	 * του «χωρίς περιορισμό» σε πραγματική λίστα.
	 *
	 * @return int[]
	 */
	public static function visible_user_ids( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$resolver = \EnergyCRM\Services::scopeResolver();

		// Η επέκταση του «χωρίς περιορισμό» σε πραγματική λίστα ζούσε εδώ για
		// μισή μέρα. Εχει πλέον σπίτι στον resolver, μαζί με την εξήγηση γιατί
		// το UserScope::userIds() δεν αρκεί.
		return $resolver->visibleUserIds( $resolver->forUser( $user_id ) );
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
