<?php
/**
 * Admin: knowledge base authoring (Energy CRM → Βάση Γνώσης).
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_KB_Admin {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 30 );
		add_action( 'admin_post_ecrm_kb_save',   [ __CLASS__, 'save' ] );
		add_action( 'admin_post_ecrm_kb_delete', [ __CLASS__, 'delete' ] );
		add_action( 'admin_post_ecrm_kb_import', [ __CLASS__, 'import' ] );
		add_action( 'admin_post_ecrm_kb_export', [ __CLASS__, 'export' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'energy-crm', 'Βάση Γνώσης', 'Βάση Γνώσης', 'manage_options', 'energy-crm-kb', [ __CLASS__, 'render' ] );
	}

	private static function providers(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT id, name FROM " . ECRM_DB::table( 'providers' ) . " ORDER BY name", ARRAY_A ) ?: [];
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb;
		$t = ECRM_DB::table( 'kb_entries' );

		$edit = null;
		if ( isset( $_GET['edit'] ) ) {
			$edit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $_GET['edit'] ), ARRAY_A );
		}
		$sections = ECRM_KB::sections();
		$ctypes   = ECRM_KB::customer_types();
		$provs    = self::providers();

		echo '<div class="wrap"><h1>Βάση Γνώσης</h1>';
		if ( isset( $_GET['ecrm_msg'] ) ) {
			$m = sanitize_text_field( wp_unslash( $_GET['ecrm_msg'] ) );
			if ( $m === 'badjson' ) {
				echo '<div class="notice notice-error is-dismissible"><p>Μη έγκυρο JSON.</p></div>';
			} elseif ( strpos( $m, 'imp' ) === 0 ) {
				echo '<div class="notice notice-success is-dismissible"><p>Εισήχθησαν ' . (int) substr( $m, 3 ) . ' ενότητες.</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>Αποθηκεύτηκε.</p></div>';
			}
		}

		// --- form ---
		echo '<h2>' . ( $edit ? 'Επεξεργασία ενότητας' : 'Νέα ενότητα' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ecrm_kb_save' );
		echo '<input type="hidden" name="action" value="ecrm_kb_save">';
		if ( $edit ) { echo '<input type="hidden" name="id" value="' . (int) $edit['id'] . '">'; }
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th><label>Πάροχος</label></th><td><select name="provider_id"><option value="">— Γενικό / χωρίς πάροχο —</option>';
		foreach ( $provs as $p ) {
			echo '<option value="' . (int) $p['id'] . '" ' . selected( (int) ( $edit['provider_id'] ?? 0 ), (int) $p['id'], false ) . '>' . esc_html( $p['name'] ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th><label>Τίτλος</label></th><td><input type="text" name="title" class="large-text" required value="' . esc_attr( $edit['title'] ?? '' ) . '" placeholder="π.χ. Δικαιολογητικά ZeniΘ — Οικιακά (Γ1/Γ1Ν)"></td></tr>';

		echo '<tr><th><label>Ενέργεια</label></th><td><select name="energy_type">';
		foreach ( [ '' => 'Όλα', 'power' => 'Ρεύμα', 'gas' => 'Αέριο' ] as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '" ' . selected( (string) ( $edit['energy_type'] ?? '' ), $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th><label>Ενότητα</label></th><td><select name="section">';
		foreach ( $sections as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '" ' . selected( (string) ( $edit['section'] ?? 'docs' ), $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th><label>Τύπος πελάτη</label></th><td><select name="customer_type"><option value="">Όλα</option>';
		foreach ( $ctypes as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '" ' . selected( (string) ( $edit['customer_type'] ?? '' ), $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th><label>Περιεχόμενο</label></th><td>';
		wp_editor( $edit['body'] ?? '', 'ecrm_kb_body', [ 'textarea_name' => 'body', 'textarea_rows' => 12, 'media_buttons' => false ] );
		echo '<p class="description">Χρησιμοποίησε επικεφαλίδες/λίστες για υπο-ενότητες (π.χ. «Ίδιο ΑΦΜ», «Αλλαγή ΑΦΜ»).</p></td></tr>';

		echo '<tr><th><label>Σειρά</label></th><td><input type="number" name="sort_order" class="small-text" value="' . (int) ( $edit['sort_order'] ?? 0 ) . '"></td></tr>';

		echo '</table>';
		submit_button( $edit ? 'Ενημέρωση' : 'Προσθήκη' );
		if ( $edit ) { echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=energy-crm-kb' ) ) . '">Άκυρο</a>'; }
		echo '</form>';

		// --- list ---
		$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY provider_name, sort_order, id", ARRAY_A );
		echo '<hr><h2>Ενότητες (' . count( (array) $rows ) . ')</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Πάροχος</th><th>Τίτλος</th><th>Ενέργεια</th><th>Ενότητα</th><th>Τύπος</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) { echo '<tr><td colspan="6">Καμία ενότητα ακόμα.</td></tr>'; }
		$en = [ 'power' => 'Ρεύμα', 'gas' => 'Αέριο', '' => 'Όλα' ];
		foreach ( (array) $rows as $r ) {
			echo '<tr>';
			echo '<td>' . esc_html( $r['provider_name'] ?: '—' ) . '</td>';
			echo '<td><strong>' . esc_html( $r['title'] ) . '</strong></td>';
			echo '<td>' . esc_html( $en[ $r['energy_type'] ] ?? $r['energy_type'] ) . '</td>';
			echo '<td>' . esc_html( $sections[ $r['section'] ] ?? $r['section'] ) . '</td>';
			echo '<td>' . esc_html( $ctypes[ $r['customer_type'] ] ?? 'Όλα' ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=energy-crm-kb&edit=' . (int) $r['id'] ) ) . '">Επεξεργασία</a> ';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'Διαγραφή;\')">';
			wp_nonce_field( 'ecrm_kb_delete' );
			echo '<input type="hidden" name="action" value="ecrm_kb_delete"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
			echo '<button class="button button-small button-link-delete">Διαγραφή</button></form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// --- import / export ---
		echo '<hr><h2>Μαζική εισαγωγή / εξαγωγή (JSON)</h2>';
		echo '<p class="description">Μετάφερε χωρίς ρίσκο το περιεχόμενο από άλλο σύστημα: κάνε <strong>Εξαγωγή</strong> από εκεί και <strong>Εισαγωγή</strong> εδώ. Μορφή: πίνακας αντικειμένων με πεδία <code>provider_name, energy_type, section, customer_type, title, body, sort_order</code>.</p>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ecrm_kb_export' ), 'ecrm_kb_export' ) ) . '">⤓ Εξαγωγή JSON</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ecrm_kb_import' );
		echo '<input type="hidden" name="action" value="ecrm_kb_import">';
		echo '<p><label><input type="checkbox" name="replace" value="1"> Αντικατάσταση όλων (διαγραφή υπαρχόντων πριν την εισαγωγή)</label></p>';
		echo '<textarea name="json" class="large-text code" rows="10" placeholder=\'[{"provider_name":"ZeniΘ","energy_type":"power","section":"docs","customer_type":"home","title":"Δικαιολογητικά ZeniΘ — Οικιακά","body":"<h4>Ίδιο ΑΦΜ</h4><ol><li>Ταυτότητα…</li></ol>","sort_order":1}]\'></textarea>';
		submit_button( 'Εισαγωγή JSON', 'secondary' );
		echo '</form>';

		echo '</div>';
	}

	public static function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_kb_export' );
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT provider_id, provider_name, energy_type, section, customer_type, title, body, sort_order FROM " . ECRM_DB::table( 'kb_entries' ) . " ORDER BY provider_name, sort_order, id", ARRAY_A );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="kb-export-' . gmdate( 'Ymd' ) . '.json"' );
		echo wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore
		exit;
	}

	public static function import(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_kb_import' );
		global $wpdb;
		$t   = ECRM_DB::table( 'kb_entries' );
		$raw = wp_unslash( $_POST['json'] ?? '' );
		$arr = json_decode( (string) $raw, true );
		if ( ! is_array( $arr ) ) {
			wp_safe_redirect( add_query_arg( 'ecrm_msg', 'badjson', admin_url( 'admin.php?page=energy-crm-kb' ) ) );
			exit;
		}
		if ( ! empty( $_POST['replace'] ) ) {
			$wpdb->query( "DELETE FROM {$t}" );
		}
		// Map provider_name → provider_id where possible.
		$pmap = [];
		foreach ( (array) $wpdb->get_results( "SELECT id, name FROM " . ECRM_DB::table( 'providers' ), ARRAY_A ) as $p ) {
			$pmap[ mb_strtolower( trim( $p['name'] ) ) ] = (int) $p['id'];
		}
		$n = 0;
		foreach ( $arr as $e ) {
			if ( empty( $e['title'] ) ) { continue; }
			$pname = sanitize_text_field( (string) ( $e['provider_name'] ?? '' ) );
			$pid   = $pmap[ mb_strtolower( trim( $pname ) ) ] ?? null;
			$energy = in_array( ( $e['energy_type'] ?? '' ), [ 'power', 'gas' ], true ) ? $e['energy_type'] : null;
			$section = array_key_exists( ( $e['section'] ?? '' ), ECRM_KB::sections() ) ? $e['section'] : 'docs';
			$ctype = array_key_exists( ( $e['customer_type'] ?? '' ), ECRM_KB::customer_types() ) ? $e['customer_type'] : null;
			$wpdb->insert( $t, [
				'provider_id'   => $pid,
				'provider_name' => $pname ?: null,
				'energy_type'   => $energy,
				'section'       => $section,
				'customer_type' => $ctype,
				'title'         => sanitize_text_field( (string) $e['title'] ),
				'body'          => wp_kses_post( (string) ( $e['body'] ?? '' ) ),
				'sort_order'    => (int) ( $e['sort_order'] ?? 0 ),
				'active'        => 1,
			] );
			$n++;
		}
		wp_safe_redirect( add_query_arg( 'ecrm_msg', 'imp' . $n, admin_url( 'admin.php?page=energy-crm-kb' ) ) );
		exit;
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_kb_save' );
		global $wpdb;
		$t = ECRM_DB::table( 'kb_entries' );

		$provider_id = (int) ( $_POST['provider_id'] ?? 0 ) ?: null;
		$provider_name = '';
		if ( $provider_id ) {
			$provider_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM " . ECRM_DB::table( 'providers' ) . " WHERE id = %d", $provider_id ) );
		}
		$energy = in_array( ( $_POST['energy_type'] ?? '' ), [ 'power', 'gas' ], true ) ? $_POST['energy_type'] : null;
		$section = array_key_exists( ( $_POST['section'] ?? '' ), ECRM_KB::sections() ) ? $_POST['section'] : 'docs';
		$ctype = array_key_exists( ( $_POST['customer_type'] ?? '' ), ECRM_KB::customer_types() ) ? $_POST['customer_type'] : null;

		$data = [
			'provider_id'   => $provider_id,
			'provider_name' => $provider_name ?: null,
			'energy_type'   => $energy,
			'section'       => $section,
			'customer_type' => $ctype,
			'title'         => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'body'          => wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) ),
			'sort_order'    => (int) ( $_POST['sort_order'] ?? 0 ),
			'updated_at'    => current_time( 'mysql' ),
		];

		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id ) {
			$wpdb->update( $t, $data, [ 'id' => $id ] );
		} else {
			$data['active'] = 1;
			$wpdb->insert( $t, $data );
		}
		wp_safe_redirect( add_query_arg( 'ecrm_msg', '1', admin_url( 'admin.php?page=energy-crm-kb' ) ) );
		exit;
	}

	public static function delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_kb_delete' );
		global $wpdb;
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id ) { $wpdb->delete( ECRM_DB::table( 'kb_entries' ), [ 'id' => $id ] ); }
		wp_safe_redirect( admin_url( 'admin.php?page=energy-crm-kb' ) );
		exit;
	}
}
