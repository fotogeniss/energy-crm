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
	}

	public static function menu(): void {
		add_submenu_page( 'energy-crm', 'GDPR', 'GDPR', 'manage_options', 'energy-crm-gdpr', [ __CLASS__, 'render' ] );
	}

	/** Gather everything we hold about a customer. */
	private static function collect( int $customer_id ): ?array {
		global $wpdb;
		$cu = ECRM_DB::table( 'customers' );
		$ct = ECRM_DB::table( 'contracts' );
		$fl = ECRM_DB::table( 'files' );
		$ev = ECRM_DB::table( 'events' );

		$customer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$cu} WHERE id = %d", $customer_id ), ARRAY_A );
		if ( ! $customer ) { return null; }

		$contracts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ct} WHERE customer_id = %d", $customer_id ), ARRAY_A );
		$cids = wp_list_pluck( $contracts, 'id' );
		$files = $events = [];
		if ( $cids ) {
			$in = implode( ',', array_map( 'intval', $cids ) );
			$files  = $wpdb->get_results( "SELECT id, contract_id, doc_kind, filename, mime, created_at FROM {$fl} WHERE contract_id IN ($in)", ARRAY_A );
			$events = $wpdb->get_results( "SELECT id, contract_id, type, from_status, to_status, message, created_at FROM {$ev} WHERE contract_id IN ($in)", ARRAY_A );
		}
		return [ 'customer' => $customer, 'contracts' => $contracts, 'files' => $files, 'events' => $events ];
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
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( 'Ασφαλίστηκαν %d αρχεία (απέτυχαν %d).', (int) $_GET['secured'], (int) ( $_GET['failed'] ?? 0 ) ) ) . '</p></div>';
		}

		// Security: re-secure any legacy publicly-stored documents.
		echo '<div class="notice notice-warning"><p><strong>Ασφάλεια αρχείων.</strong> Μετακίνηση παλαιών δημόσιων εγγράφων σε προστατευμένη αποθήκευση. ';
		echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ecrm_secure_files' ), 'ecrm_secure_files' ) ) . '" onclick="return confirm(\'Μετακίνηση όλων των παλαιών αρχείων σε προστατευμένη αποθήκευση;\')">Ασφάλιση παλαιών αρχείων</a></p></div>';

		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		echo '<form method="get" style="margin:14px 0;"><input type="hidden" name="page" value="energy-crm-gdpr">';
		echo '<input type="search" name="q" value="' . esc_attr( $q ) . '" class="regular-text" placeholder="ΑΦΜ ή όνομα/επωνυμία">';
		echo ' <button class="button button-primary">Αναζήτηση</button></form>';

		if ( $q === '' ) {
			echo '<p class="description">Αναζήτησε πελάτη για εξαγωγή ή διαγραφή δεδομένων.</p></div>';
			return;
		}

		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, first_name, last_name, company_name, afm, email, mobile FROM {$cu}
			 WHERE afm LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR company_name LIKE %s
			 ORDER BY id DESC LIMIT 50",
			$like, $like, $like, $like
		), ARRAY_A );

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

	public static function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_gdpr_export' );
		$id   = (int) ( $_GET['id'] ?? 0 );
		$data = self::collect( $id );
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
		global $wpdb;
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( ! $id ) { self::back(); }

		$cu = ECRM_DB::table( 'customers' );
		$ct = ECRM_DB::table( 'contracts' );
		$fl = ECRM_DB::table( 'files' );

		// 1) Delete attached documents (media + DB rows) for this customer's contracts.
		$cids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$ct} WHERE customer_id = %d", $id ) );
		if ( $cids ) {
			$in    = implode( ',', array_map( 'intval', $cids ) );
			$files = $wpdb->get_results( "SELECT id, attachment_id, path FROM {$fl} WHERE contract_id IN ($in)", ARRAY_A );
			foreach ( $files as $f ) {
				if ( ! empty( $f['attachment_id'] ) ) {
					wp_delete_attachment( (int) $f['attachment_id'], true );
				} elseif ( ! empty( $f['path'] ) && file_exists( $f['path'] ) ) {
					@unlink( $f['path'] ); // phpcs:ignore
				}
			}
			$wpdb->query( "DELETE FROM {$fl} WHERE contract_id IN ($in)" );
			// Strip PII echoed into contract notes/extracted data.
			$wpdb->query( "UPDATE {$ct} SET notes = NULL, extracted_json = NULL, consent_ip = NULL WHERE customer_id = " . (int) $id );
		}

		// 2) Anonymize the customer record (keep id + region for non-personal stats).
		$wpdb->update( $cu, [
			'first_name'  => '—',
			'last_name'   => 'ΔΙΑΓΡΑΦΗ',
			'father_name' => null,
			'company_name'=> null,
			'afm'         => null,
			'doy'         => null,
			'adt'         => null,
			'birth_date'  => null,
			'email'       => null,
			'phone'       => null,
			'mobile'      => null,
			'street'      => null,
			'street_no'   => null,
			'postal_code' => null,
			'city'        => null,
		], [ 'id' => $id ] );

		self::back( 'erased' );
	}

	public static function secure_files(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_secure_files' );
		$res = class_exists( 'ECRM_Files' ) ? ECRM_Files::secure_legacy() : [ 'moved' => 0, 'failed' => 0 ];
		wp_safe_redirect( add_query_arg( [ 'secured' => (int) $res['moved'], 'failed' => (int) $res['failed'] ], admin_url( 'admin.php?page=energy-crm-gdpr' ) ) );
		exit;
	}

	private static function back( ?string $msg = null ): void {
		$url = admin_url( 'admin.php?page=energy-crm-gdpr' );
		if ( $msg ) { $url = add_query_arg( 'ecrm_msg', $msg, $url ); }
		wp_safe_redirect( $url );
		exit;
	}
}
