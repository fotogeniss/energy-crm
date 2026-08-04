<?php
/**
 * Admin: commission rules + a matching helper.
 *
 * A rule sets a flat euro amount per successful contract, optionally scoped
 * by provider / program / energy type / category. The most specific matching
 * rule wins. Rules live under Energy CRM → Προμήθειες.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Commissions {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 30 );
		add_action( 'admin_post_ecrm_save_rule',   [ __CLASS__, 'save_rule' ] );
		add_action( 'admin_post_ecrm_delete_rule', [ __CLASS__, 'delete_rule' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'energy-crm', 'Προμήθειες', 'Προμήθειες', 'manage_options', 'energy-crm-commissions', [ __CLASS__, 'render' ] );
	}

	/**
	 * Best-matching commission amount for a contract row.
	 *
	 * @param array $c Row with provider_id, program_id, energy_type, category, status.
	 */
	public static function amount_for( array $c ): float {
		global $wpdb;
		$rt    = ECRM_DB::table( 'commission_rules' );
		$rules = $wpdb->get_results( "SELECT * FROM {$rt} WHERE active = 1", ARRAY_A );
		$best  = null; $best_score = -1;

		foreach ( $rules as $r ) {
			$score = 0;
			if ( $r['provider_id'] ) { if ( (int) $r['provider_id'] !== (int) ( $c['provider_id'] ?? 0 ) ) { continue; } $score += 8; }
			if ( $r['program_id'] )  { if ( (int) $r['program_id']  !== (int) ( $c['program_id'] ?? 0 ) )  { continue; } $score += 4; }
			if ( $r['energy_type'] ) { if ( $r['energy_type'] !== ( $c['energy_type'] ?? '' ) ) { continue; } $score += 2; }
			if ( $r['category'] )    { if ( $r['category'] !== ( $c['category'] ?? '' ) ) { continue; } $score += 1; }
			if ( $score > $best_score ) { $best_score = $score; $best = $r; }
		}
		return $best ? (float) $best['amount'] : 0.0;
	}

	// ---------------------------------------------------------------------
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb;
		$rt = ECRM_DB::table( 'commission_rules' );
		$pt = ECRM_DB::table( 'providers' );
		$pg = ECRM_DB::table( 'programs' );

		$providers = $wpdb->get_results( "SELECT id, name FROM {$pt} ORDER BY name", ARRAY_A );
		$programs  = $wpdb->get_results( "SELECT id, name, provider_id FROM {$pg} ORDER BY name", ARRAY_A );
		$pmap = []; foreach ( $providers as $p ) { $pmap[ $p['id'] ] = $p['name']; }
		$gmap = []; foreach ( $programs as $g ) { $gmap[ $g['id'] ] = $g['name']; }

		$rules = $wpdb->get_results( "SELECT * FROM {$rt} ORDER BY id DESC", ARRAY_A );
		$cats  = [ 'home' => 'Οικιακό', 'business' => 'Επαγγελματικό', 'communal' => 'Κοινόχρηστο' ];

		echo '<div class="wrap"><h1>Προμήθειες</h1>';
		if ( isset( $_GET['ecrm_msg'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>Αποθηκεύτηκε.</p></div>'; }
		echo '<p>Όρισε ποσό προμήθειας (€) ανά επιτυχημένη σύμβαση. Άφησε κενά τα φίλτρα για γενικό κανόνα· ο πιο ειδικός κανόνας υπερισχύει.</p>';

		echo '<table class="widefat striped"><thead><tr><th>Πάροχος</th><th>Πρόγραμμα</th><th>Είδος</th><th>Κατηγορία</th><th>Ποσό (€)</th><th>Ενεργός</th><th></th></tr></thead><tbody>';
		if ( ! $rules ) { echo '<tr><td colspan="7">Δεν υπάρχουν κανόνες.</td></tr>'; }
		foreach ( (array) $rules as $r ) {
			echo '<tr>';
			echo '<td>' . esc_html( $r['provider_id'] ? ( $pmap[ $r['provider_id'] ] ?? '#' . $r['provider_id'] ) : 'Όλοι' ) . '</td>';
			echo '<td>' . esc_html( $r['program_id'] ? ( $gmap[ $r['program_id'] ] ?? '#' . $r['program_id'] ) : 'Όλα' ) . '</td>';
			echo '<td>' . esc_html( $r['energy_type'] ? ECRM_DB::energy_label( (string) $r['energy_type'] ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $r['category'] ? ( $cats[ $r['category'] ] ?? $r['category'] ) : '—' ) . '</td>';
			echo '<td><strong>' . number_format( (float) $r['amount'], 2 ) . '</strong></td>';
			echo '<td>' . ( $r['active'] ? '✔' : '—' ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Διαγραφή;\')" style="display:inline">';
			wp_nonce_field( 'ecrm_delete_rule' );
			echo '<input type="hidden" name="action" value="ecrm_delete_rule"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
			echo '<button class="button button-small button-link-delete">Διαγραφή</button></form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// add form
		echo '<h2 style="margin-top:24px;">Νέος κανόνας</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ecrm_save_rule' );
		echo '<input type="hidden" name="action" value="ecrm_save_rule">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>Πάροχος</th><td><select name="provider_id"><option value="">Όλοι</option>';
		foreach ( $providers as $p ) { echo '<option value="' . (int) $p['id'] . '">' . esc_html( $p['name'] ) . '</option>'; }
		echo '</select></td></tr>';
		echo '<tr><th>Πρόγραμμα</th><td><select name="program_id"><option value="">Όλα</option>';
		foreach ( $programs as $g ) { echo '<option value="' . (int) $g['id'] . '">' . esc_html( $g['name'] ) . '</option>'; }
		echo '</select></td></tr>';
		echo '<tr><th>Είδος</th><td><select name="energy_type"><option value="">Όλα</option><option value="power">Ηλεκτρισμός</option><option value="gas">Φυσικό Αέριο</option><option value="mobile">Κινητή Τηλεφωνία</option></select></td></tr>';
		echo '<tr><th>Κατηγορία</th><td><select name="category"><option value="">Όλες</option>';
		foreach ( $cats as $v => $l ) { echo '<option value="' . esc_attr( $v ) . '">' . esc_html( $l ) . '</option>'; }
		echo '</select></td></tr>';
		echo '<tr><th>Ποσό (€)</th><td><input type="number" step="0.01" min="0" name="amount" value="0" class="small-text" required></td></tr>';
		echo '</table>';
		submit_button( 'Προσθήκη κανόνα' );
		echo '</form></div>';
	}

	public static function save_rule(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_save_rule' );
		global $wpdb;
		$wpdb->insert( ECRM_DB::table( 'commission_rules' ), [
			'provider_id' => ! empty( $_POST['provider_id'] ) ? (int) $_POST['provider_id'] : null,
			'program_id'  => ! empty( $_POST['program_id'] ) ? (int) $_POST['program_id'] : null,
			'energy_type' => in_array( ( $_POST['energy_type'] ?? '' ), [ 'power', 'gas', 'mobile' ], true ) ? $_POST['energy_type'] : null,
			'category'    => ! empty( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : null,
			'amount'      => (float) ( $_POST['amount'] ?? 0 ),
			'active'      => 1,
		] );
		wp_safe_redirect( admin_url( 'admin.php?page=energy-crm-commissions&ecrm_msg=1' ) );
		exit;
	}

	public static function delete_rule(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_delete_rule' );
		global $wpdb;
		$wpdb->delete( ECRM_DB::table( 'commission_rules' ), [ 'id' => (int) ( $_POST['id'] ?? 0 ) ] );
		wp_safe_redirect( admin_url( 'admin.php?page=energy-crm-commissions&ecrm_msg=1' ) );
		exit;
	}
}
