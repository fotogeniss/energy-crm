<?php
/**
 * Admin: κανόνες εγγύησης -- πρόταση ποσού (€) ανά σύμβαση, όχι υποχρέωση.
 *
 * Δίδυμο του ECRM_Commissions, με μία διαφορά σχήματος (η κλίμακα kVA) και μία
 * διαφορά νοήματος: όπου το commission_rules άδειο σημαίνει «κανείς δεν
 * πληρώνεται» (σιωπηλή αποτυχία, έχει δικό του health check), το
 * guarantee_rules άδειο σημαίνει «ο πωλητής γράφει το ποσό μόνος του» -- η
 * σημερινή συμπεριφορά, όχι βλάβη. Η απόφαση επιλογής ζει στο
 * EnergyCRM\Domain\Guarantee\GuaranteeMatch (βλ. CHANGELOG (210)).
 *
 * Κανόνες υπό Energy CRM → Κανόνες εγγύησης.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Guarantees {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 31 );
		add_action( 'admin_post_ecrm_save_guarantee_rule',   [ __CLASS__, 'save_rule' ] );
		add_action( 'admin_post_ecrm_delete_guarantee_rule', [ __CLASS__, 'delete_rule' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'energy-crm', 'Κανόνες εγγύησης', 'Κανόνες εγγύησης', 'manage_options', 'energy-crm-guarantee-rules', [ __CLASS__, 'render' ] );
	}

	/**
	 * Προτεινόμενο ποσό εγγύησης για μια σύμβαση, ή null αν κανένας κανόνας
	 * δεν ταιριάζει -- δες γιατί το null είναι δικό του νόημα στο
	 * GuaranteeMatch, όχι απλή απουσία.
	 *
	 * @param array<string, mixed> $c Γραμμή με provider_id, program_id, energy_type, category, agreed_power.
	 */
	public static function amount_for( array $c ): ?float {
		return \EnergyCRM\Domain\Guarantee\GuaranteeMatch::amountFor( self::active_rules(), $c );
	}

	/**
	 * Οι ενεργοί κανόνες, διαβασμένοι μία φορά ανά αίτημα -- ίδιο σχήμα cache
	 * με το ECRM_Commissions::active_rules(), για τον ίδιο λόγο: αποφεύγει ένα
	 * SELECT ανά κλήση όταν η πρόταση ζητηθεί μέσα σε βρόχο.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function active_rules(): array {
		static $rules = null;

		if ( $rules === null ) {
			$rules = ( new \EnergyCRM\Persistence\GuaranteeRuleRepository() )->active();
		}

		return $rules;
	}

	// ---------------------------------------------------------------------
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb;
		$rt = ECRM_DB::table( 'guarantee_rules' );
		$pt = ECRM_DB::table( 'providers' );
		$pg = ECRM_DB::table( 'programs' );

		$providers = $wpdb->get_results( "SELECT id, name FROM {$pt} ORDER BY name", ARRAY_A );
		$programs  = $wpdb->get_results( "SELECT id, name, provider_id FROM {$pg} ORDER BY name", ARRAY_A );
		$pmap = []; foreach ( $providers as $p ) { $pmap[ $p['id'] ] = $p['name']; }
		$gmap = []; foreach ( $programs as $g ) { $gmap[ $g['id'] ] = $g['name']; }

		$rules = $wpdb->get_results( "SELECT * FROM {$rt} ORDER BY id DESC", ARRAY_A );
		$cats  = [ 'home' => 'Οικιακό', 'business' => 'Επαγγελματικό', 'communal' => 'Κοινόχρηστο' ];

		echo '<div class="wrap"><h1>Κανόνες εγγύησης</h1>';
		if ( isset( $_GET['ecrm_msg'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>Αποθηκεύτηκε.</p></div>'; }
		echo '<p>Όρισε το ποσό εγγύησης (€) που προτείνεται στον πωλητή. Άφησε κενά τα φίλτρα ';
		echo 'για γενικό κανόνα· ο πιο ειδικός κανόνας υπερισχύει. <strong>Χωρίς κανόνα που να ταιριάζει, ';
		echo 'η φόρμα δεν προτείνει τίποτα</strong> -- ο πωλητής γράφει το ποσό μόνος του, όπως γίνεται σήμερα.</p>';

		echo '<table class="widefat striped"><thead><tr><th>Πάροχος</th><th>Πρόγραμμα</th><th>Είδος</th><th>Κατηγορία</th><th>Ισχύς (kVA)</th><th>Ποσό (€)</th><th>Ενεργός</th><th></th></tr></thead><tbody>';
		if ( ! $rules ) { echo '<tr><td colspan="8">Δεν υπάρχουν κανόνες.</td></tr>'; }
		foreach ( (array) $rules as $r ) {
			echo '<tr>';
			echo '<td>' . esc_html( $r['provider_id'] ? ( $pmap[ $r['provider_id'] ] ?? '#' . $r['provider_id'] ) : 'Όλοι' ) . '</td>';
			echo '<td>' . esc_html( $r['program_id'] ? ( $gmap[ $r['program_id'] ] ?? '#' . $r['program_id'] ) : 'Όλα' ) . '</td>';
			echo '<td>' . esc_html( $r['energy_type'] ? ECRM_DB::energy_label( (string) $r['energy_type'] ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $r['category'] ? ( $cats[ $r['category'] ] ?? $r['category'] ) : '—' ) . '</td>';
			echo '<td>' . esc_html( self::power_range_label( $r['kva_min'], $r['kva_max'] ) ) . '</td>';
			echo '<td><strong>' . number_format( (float) $r['amount'], 2 ) . '</strong></td>';
			echo '<td>' . ( $r['active'] ? '✔' : '—' ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Διαγραφή;\')" style="display:inline">';
			wp_nonce_field( 'ecrm_delete_guarantee_rule' );
			echo '<input type="hidden" name="action" value="ecrm_delete_guarantee_rule"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
			echo '<button class="button button-small button-link-delete">Διαγραφή</button></form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// add form
		echo '<h2 style="margin-top:24px;">Νέος κανόνας</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ecrm_save_guarantee_rule' );
		echo '<input type="hidden" name="action" value="ecrm_save_guarantee_rule">';
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
		echo '<tr><th>kVA από</th><td><input type="number" step="0.01" min="0" name="kva_min" value="" class="small-text" placeholder="—">';
		echo '<p class="description">Προαιρετικό. Κενό = χωρίς κάτω όριο.</p></td></tr>';
		echo '<tr><th>kVA έως</th><td><input type="number" step="0.01" min="0" name="kva_max" value="" class="small-text" placeholder="—">';
		echo '<p class="description">Προαιρετικό. Κενό = χωρίς πάνω όριο. Και τα δύο όρια είναι συμπεριληπτικά.</p></td></tr>';
		echo '<tr><th>Ποσό (€)</th><td><input type="number" step="0.01" min="0" name="amount" value="0" class="small-text" required></td></tr>';
		echo '</table>';
		submit_button( 'Προσθήκη κανόνα' );
		echo '</form></div>';
	}

	/**
	 * «έως 8», «8 – 15», «από 15», «—» -- ίδια μορφή με τη μακέτα §1.8
	 * (docs/UI-GUARANTEE-RULES.html), εγκεκριμένη 01/09/2026.
	 *
	 * @param mixed $min
	 * @param mixed $max
	 */
	private static function power_range_label( $min, $max ): string {
		$min = ( null === $min || '' === $min ) ? null : (float) $min;
		$max = ( null === $max || '' === $max ) ? null : (float) $max;

		if ( null === $min && null === $max ) {
			return '—';
		}

		if ( null === $min ) {
			return 'έως ' . self::fmt_kva( $max );
		}

		if ( null === $max ) {
			return 'από ' . self::fmt_kva( $min );
		}

		return self::fmt_kva( $min ) . ' – ' . self::fmt_kva( $max );
	}

	private static function fmt_kva( float $v ): string {
		return rtrim( rtrim( number_format( $v, 2, ',', '' ), '0' ), ',' );
	}

	public static function save_rule(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_save_guarantee_rule' );
		global $wpdb;
		$wpdb->insert( ECRM_DB::table( 'guarantee_rules' ), [
			'provider_id' => ! empty( $_POST['provider_id'] ) ? (int) $_POST['provider_id'] : null,
			'program_id'  => ! empty( $_POST['program_id'] ) ? (int) $_POST['program_id'] : null,
			'energy_type' => in_array( ( $_POST['energy_type'] ?? '' ), [ 'power', 'gas', 'mobile' ], true ) ? $_POST['energy_type'] : null,
			'category'    => ! empty( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : null,
			'kva_min'     => ( isset( $_POST['kva_min'] ) && '' !== $_POST['kva_min'] ) ? (float) $_POST['kva_min'] : null,
			'kva_max'     => ( isset( $_POST['kva_max'] ) && '' !== $_POST['kva_max'] ) ? (float) $_POST['kva_max'] : null,
			'amount'      => (float) ( $_POST['amount'] ?? 0 ),
			'active'      => 1,
		] );
		wp_safe_redirect( admin_url( 'admin.php?page=energy-crm-guarantee-rules&ecrm_msg=1' ) );
		exit;
	}

	public static function delete_rule(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_delete_guarantee_rule' );
		global $wpdb;
		$wpdb->delete( ECRM_DB::table( 'guarantee_rules' ), [ 'id' => (int) ( $_POST['id'] ?? 0 ) ] );
		wp_safe_redirect( admin_url( 'admin.php?page=energy-crm-guarantee-rules&ecrm_msg=1' ) );
		exit;
	}
}
