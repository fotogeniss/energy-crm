<?php
/**
 * GDPR tools (admin): subject-access export + right-to-erasure.
 *
 * Energy CRM → GDPR. Search a customer by ΑΦΜ or name, then:
 *   - Export: download a full JSON of the customer, their contracts, files
 *     metadata and event history (Article 15, right of access).
 *   - Anonymize: strip personal data (name, ΑΦΜ, contact, address) and delete
 *     attached documents, keeping non-personal contract records for reporting
 *     (Article 17, right to erasure). This is irreversible.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_GDPR {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 32 );
		add_action( 'admin_post_ecrm_gdpr_export', [ __CLASS__, 'export' ] );
		add_action( 'admin_post_ecrm_gdpr_erase',  [ __CLASS__, 'erase' ] );
		add_action( 'admin_post_ecrm_secure_files', [ __CLASS__, 'secure_files' ] );
		add_action( 'admin_post_ecrm_backfill_pii', [ __CLASS__, 'backfill_pii' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'energy-crm', 'GDPR', 'GDPR', 'manage_options', 'energy-crm-gdpr', [ __CLASS__, 'render' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb;
		$cu = ECRM_DB::table( 'customers' );

		echo '<div class="wrap"><h1>GDPR — Δεδομένα Πελάτη</h1>';
		if ( isset( $_GET['ecrm_msg'] ) ) {
			$m = sanitize_text_field( wp_unslash( $_GET['ecrm_msg'] ) );
			$txt = [ 'erased' => 'Τα προσωπικά δεδομένα ανωνυμοποιήθηκαν.' ][ $m ] ?? 'Έγινε.';
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $txt ) . '</p></div>';
		}
		if ( isset( $_GET['secured'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( 'Ασφαλίστηκαν %d αρχεία.', (int) $_GET['secured'] ) ) . '</p></div>';
		}
		if ( isset( $_GET['encrypted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( 'Κρυπτογραφήθηκαν %d εγγραφές.', (int) $_GET['encrypted'] ) ) . '</p></div>';
		}

		self::render_file_security_notice();
		self::render_pii_backfill_notice();

		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		echo '<form method="get" style="margin:14px 0;"><input type="hidden" name="page" value="energy-crm-gdpr">';
		echo '<input type="search" name="q" value="' . esc_attr( $q ) . '" class="regular-text" placeholder="ΑΦΜ ή όνομα/επωνυμία">';
		echo ' <button class="button button-primary">Αναζήτηση</button></form>';

		if ( $q === '' ) {
			echo '<p class="description">Αναζήτησε πελάτη για εξαγωγή ή διαγραφή δεδομένων.</p></div>';
			return;
		}

		// The ΑΦΜ may be plaintext or ciphertext depending on the install, so
		// it is matched both ways. See EnergyCRM\Persistence\CustomerFields.
		$fields = \EnergyCRM\Persistence\CustomerFields::default();
		$index  = \EnergyCRM\Persistence\CustomerFields::INDEX_COLUMN;

		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, first_name, last_name, company_name, afm, email, mobile FROM {$cu}
			 WHERE afm LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR company_name LIKE %s
			   OR {$index} = %s
			 ORDER BY id DESC LIMIT 50",
			$like, $like, $like, $like, $fields->index( $q )
		), ARRAY_A );

		$rows = $fields->fromStorageAll( (array) $rows );

		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Όνομα/Επωνυμία</th><th>ΑΦΜ</th><th>Επικοινωνία</th><th>Ενέργειες</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Καμία εγγραφή.</td></tr>';
		}
		foreach ( (array) $rows as $r ) {
			$name = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			echo '<tr><td>' . (int) $r['id'] . '</td>';
			echo '<td><strong>' . esc_html( $name ?: '—' ) . '</strong></td>';
			echo '<td>' . esc_html( $r['afm'] ?: '—' ) . '</td>';
			echo '<td>' . esc_html( trim( ( $r['email'] ?? '' ) . ' ' . ( $r['mobile'] ?? '' ) ) ?: '—' ) . '</td>';
			echo '<td>';
			echo '<a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ecrm_gdpr_export&id=' . (int) $r['id'] ), 'ecrm_gdpr_export' ) ) . '">Εξαγωγή JSON</a> ';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'Μη αναστρέψιμη ανωνυμοποίηση όλων των προσωπικών δεδομένων αυτού του πελάτη. Συνέχεια;\')">';
			wp_nonce_field( 'ecrm_gdpr_erase' );
			echo '<input type="hidden" name="action" value="ecrm_gdpr_erase"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
			echo '<button class="button button-small button-link-delete">Διαγραφή δεδομένων</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * State of the legacy-document backlog.
	 *
	 * Securing them runs on cron now, so the useful thing to show is whether
	 * any are left — not a button that looks the same whether the work is done
	 * or was never started. See EnergyCRM\Infrastructure\DocumentProtection.
	 */
	private static function render_file_security_notice(): void {
		$pending = self::protection()->pending();

		if ( $pending === 0 ) {
			echo '<div class="notice notice-success"><p><strong>Ασφάλεια αρχείων.</strong> Όλα τα έγγραφα βρίσκονται σε προστατευμένη αποθήκευση.</p></div>';
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>Ασφάλεια αρχείων.</strong> ';
		echo esc_html( sprintf( '%d έγγραφα είναι ακόμη δημόσια προσβάσιμα.', $pending ) );
		echo ' Μεταφέρονται αυτόματα ανά ώρα, σε παρτίδες. ';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ecrm_secure_files' ), 'ecrm_secure_files' ) ) . '">Μεταφορά τώρα</a></p></div>';
	}

	private static function protection(): \EnergyCRM\Infrastructure\DocumentProtection {
		return new \EnergyCRM\Infrastructure\DocumentProtection( \EnergyCRM\Services::unprotectedDocuments() );
	}

	/**
	 * State of the encryption backfill.
	 *
	 * ECRM_ENCRYPT_PII only ever governed new writes, so a site that switches
	 * it on keeps every existing ΑΦΜ, ΑΔΤ and address in plaintext until this
	 * has run. That gap is invisible from anywhere else in wp-admin — the
	 * screens all read through the decrypting layer and look identical either
	 * way — which is the whole reason it is reported here.
	 *
	 * See EnergyCRM\Infrastructure\PiiBackfill.
	 */
	private static function render_pii_backfill_notice(): void {
		$backfill = self::backfill();
		$blocked  = $backfill->blockedReason();

		// Encryption off is the default and not worth nagging about. Sodium
		// missing while encryption is on is a real problem and says so.
		if ( null !== $blocked ) {
			if ( ! \EnergyCRM\Persistence\CustomerFields::isEnabled() ) {
				return;
			}

			echo '<div class="notice notice-error"><p><strong>Κρυπτογράφηση προσωπικών δεδομένων.</strong> ';
			echo esc_html( $blocked );
			echo '</p></div>';
			return;
		}

		$keys = \EnergyCRM\Infrastructure\KeyFingerprint::default();

		if ( ! $keys->matches() ) {
			// Before the pending counts, because none of them mean anything
			// while the data cannot be read. This is the one notice on this
			// screen that describes damage still in progress.
			echo '<div class="notice notice-error"><p><strong>Το κλειδί κρυπτογράφησης άλλαξε.</strong> ';
			echo 'Τα ΑΦΜ, ΑΔΤ και οι διευθύνσεις διαβάζονται ως κενά, και κάθε αποθήκευση τα γράφει ';
			echo 'οριστικά κενά. Επανάφερε το παλιό <code>SECURE_AUTH_SALT</code> ΠΡΙΝ συνεχίσει ';
			echo 'οποιαδήποτε καταχώρηση — μέχρι τότε τα δεδομένα είναι ακέραια στον δίσκο. ';
			echo 'Οδηγίες: <code>docs/BACKUP.md</code>.</p></div>';

			return;
		}

		$pending = $backfill->pending();

		if ( 0 === $pending['customers'] && 0 === $pending['contracts'] ) {
			echo '<div class="notice notice-success"><p><strong>Κρυπτογράφηση προσωπικών δεδομένων.</strong> Δεν έμεινε τίποτα σε καθαρό κείμενο.</p></div>';
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>Κρυπτογράφηση προσωπικών δεδομένων.</strong> ';
		echo esc_html(
			sprintf(
				'Απομένουν %d πελάτες και έως %d συμβόλαια προς έλεγχο.',
				$pending['customers'],
				$pending['contracts']
			)
		);
		echo ' Μετατρέπονται αυτόματα ανά ώρα, σε παρτίδες. ';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ecrm_backfill_pii' ), 'ecrm_backfill_pii' ) ) . '">Μετατροπή τώρα</a></p>';

		// The number for the contracts is rows the walk has not reached, not
		// rows still holding plaintext. Saying so beats an admin concluding the
		// job is stuck because the figure does not drop the way the other does.
		echo '<p class="description">Ο αριθμός των συμβολαίων είναι όσα δεν έχει προσπελάσει ακόμη η σάρωση — όχι όσα κρατούν καθαρό κείμενο. Τα περισσότερα δεν χρειάζονται καμία αλλαγή.</p></div>';
	}

	private static function backfill(): \EnergyCRM\Infrastructure\PiiBackfill {
		return new \EnergyCRM\Infrastructure\PiiBackfill( \EnergyCRM\Persistence\PiiBackfillRepository::default() );
	}

	public static function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_gdpr_export' );
		$id = (int) ( $_GET['id'] ?? 0 );

		// What counts as "everything we hold" is decided in one place, shared
		// with erasure. See EnergyCRM\Persistence\PersonalDataTables.
		$data = ( new \EnergyCRM\Persistence\PersonalDataExporter() )->export( $id );
		if ( ! $data ) { wp_die( 'Δεν βρέθηκε.' ); }

		$payload = [
			'exported_at' => current_time( 'mysql' ),
			'exported_by' => wp_get_current_user()->user_login,
			'subject'     => $data,
		];
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="gdpr-customer-' . $id . '.json"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore
		exit;
	}

	public static function erase(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_gdpr_erase' );
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! $id ) { self::back(); }

		// Which columns in which tables hold personal data is not this screen's
		// business to remember. See EnergyCRM\Persistence\PersonalDataEraser.
		$eraser = new \EnergyCRM\Persistence\PersonalDataEraser( \EnergyCRM\Services::files() );
		$eraser->erase( $id );

		self::back( 'erased' );
	}

	/**
	 * "Do it now" — one larger slice, still bounded.
	 *
	 * Unbounded would mean an admin request copying an unknown number of files
	 * while the site serves everyone else, and a timeout that reports nothing.
	 * Whatever is left stays with the hourly job.
	 */
	public static function secure_files(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_secure_files' );
		$report = self::protection()->sweep( 200 );
		wp_safe_redirect( add_query_arg( 'secured', (int) $report['protected'], admin_url( 'admin.php?page=energy-crm-gdpr' ) ) );
		exit;
	}

	/**
	 * "Do it now" — one larger slice, still bounded.
	 *
	 * This exists for the supervised run, not for convenience. Waiting on an
	 * hourly job of a hundred rows is fine for a backlog nobody is watching;
	 * it is the wrong tool for the afternoon somebody switches encryption on
	 * in production and wants to see the first few hundred rows convert while
	 * they are looking at the screen.
	 *
	 * Bounded for the same reason as secure_files(): an unbounded admin request
	 * rewriting an unknown number of rows ends in a timeout that reports
	 * nothing, and "did it finish?" is exactly the question you cannot afford
	 * to be unsure about here. Whatever is left stays with the hourly job.
	 */
	public static function backfill_pii(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_backfill_pii' );

		$report = self::backfill()->sweep( 500 );

		wp_safe_redirect(
			add_query_arg(
				'encrypted',
				(int) $report['customers'] + (int) $report['contracts'],
				admin_url( 'admin.php?page=energy-crm-gdpr' )
			)
		);
		exit;
	}

	private static function back( ?string $msg = null ): void {
		$url = admin_url( 'admin.php?page=energy-crm-gdpr' );
		if ( $msg ) { $url = add_query_arg( 'ecrm_msg', $msg, $url ); }
		wp_safe_redirect( $url );
		exit;
	}
}
