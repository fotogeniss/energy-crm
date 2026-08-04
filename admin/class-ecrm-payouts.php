<?php
/**
 * Admin: commission payouts (settlements).
 *
 * Turns the per-contract commission amounts into trackable settlement batches:
 *   - See what is owed per partner (payable contracts not yet settled).
 *   - Create a payout batch → stamps payout_id on those contracts so they are
 *     never double-counted.
 *   - Mark a batch paid (records paid_at).
 *   - Print a statement PDF per batch.
 *   - Void a batch → releases its contracts back to "unsettled".
 *
 * Lives under Energy CRM → Εκκαθαρίσεις (admins only).
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Payouts {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 31 );
		add_action( 'admin_post_ecrm_create_payout', [ __CLASS__, 'create' ] );
		add_action( 'admin_post_ecrm_pay_payout',    [ __CLASS__, 'pay' ] );
		add_action( 'admin_post_ecrm_delete_payout', [ __CLASS__, 'remove' ] );
		add_action( 'admin_post_ecrm_payout_pdf',    [ __CLASS__, 'pdf' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'energy-crm', 'Εκκαθαρίσεις', 'Εκκαθαρίσεις', 'manage_options', 'energy-crm-payouts', [ __CLASS__, 'render' ] );
	}

	/** Payable, not-yet-settled contracts for a partner (rows needed for amount calc). */
	private static function unsettled_rows( ?int $partner = null ): array {
		global $wpdb;
		$ct      = ECRM_DB::table( 'contracts' );
		$payable = ECRM_DB::payable_statuses();
		$sph     = implode( ',', array_fill( 0, count( $payable ), '%s' ) );
		$where   = "status IN ($sph) AND (payout_id IS NULL OR payout_id = 0) AND partner_user_id IS NOT NULL";
		$args    = $payable;
		if ( $partner ) {
			$where .= ' AND partner_user_id = %d';
			$args[] = $partner;
		}
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, partner_user_id, provider_id, program_id, energy_type, category, status, updated_at FROM {$ct} WHERE {$where}",
			$args
		), ARRAY_A );
	}

	private static function amount( array $r ): float {
		return class_exists( 'ECRM_Commissions' ) ? ECRM_Commissions::amount_for( $r ) : 0.0;
	}

	// ---------------------------------------------------------------------
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb;
		$pt = ECRM_DB::table( 'payouts' );

		// Owed per partner.
		$owed = [];
		foreach ( self::unsettled_rows() as $r ) {
			$uid = (int) $r['partner_user_id'];
			if ( ! isset( $owed[ $uid ] ) ) { $owed[ $uid ] = [ 'cnt' => 0, 'amount' => 0.0 ]; }
			$owed[ $uid ]['cnt']++;
			$owed[ $uid ]['amount'] += self::amount( $r );
		}

		echo '<div class="wrap"><h1>Εκκαθαρίσεις Προμηθειών</h1>';
		if ( isset( $_GET['ecrm_msg'] ) ) {
			$m = sanitize_text_field( wp_unslash( $_GET['ecrm_msg'] ) );
			$txt = [ 'created' => 'Δημιουργήθηκε εκκαθάριση.', 'paid' => 'Σημειώθηκε ως πληρωμένη.', 'deleted' => 'Διαγράφηκε.' ][ $m ] ?? 'Έγινε.';
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $txt ) . '</p></div>';
		}

		// --- Owed table ---
		echo '<h2>Ανεξόφλητες προμήθειες ανά συνεργάτη</h2>';
		echo '<p>Συμβάσεις σε πληρωτέα κατάσταση (' . esc_html( implode( ', ', ECRM_DB::payable_statuses() ) ) . ') που δεν έχουν ενταχθεί σε εκκαθάριση.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Συνεργάτης</th><th>Συμβάσεις</th><th>Ποσό (€)</th><th></th></tr></thead><tbody>';
		if ( ! $owed ) {
			echo '<tr><td colspan="4">Δεν υπάρχουν ανεξόφλητες προμήθειες.</td></tr>';
		} else {
			// sort by amount desc
			uasort( $owed, function ( $a, $b ) { return $b['amount'] <=> $a['amount']; } );
			foreach ( $owed as $uid => $v ) {
				$u = get_userdata( $uid );
				echo '<tr>';
				echo '<td>' . esc_html( $u ? $u->display_name : ( '#' . $uid ) ) . '</td>';
				echo '<td>' . (int) $v['cnt'] . '</td>';
				echo '<td><strong>' . number_format( $v['amount'], 2 ) . '</strong></td>';
				echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'Δημιουργία εκκαθάρισης για ' . esc_attr( $u ? $u->display_name : ( '#' . $uid ) ) . ';\')">';
				wp_nonce_field( 'ecrm_create_payout' );
				echo '<input type="hidden" name="action" value="ecrm_create_payout"><input type="hidden" name="partner_user_id" value="' . (int) $uid . '">';
				echo '<button class="button button-primary button-small">Δημιουργία εκκαθάρισης</button></form></td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		// --- Batches table ---
		$batches = $wpdb->get_results( "SELECT * FROM {$pt} ORDER BY id DESC LIMIT 500", ARRAY_A );
		echo '<h2 style="margin-top:28px;">Εκκαθαρίσεις</h2>';
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Συνεργάτης</th><th>Περίοδος</th><th>Συμβάσεις</th><th>Ποσό (€)</th><th>Κατάσταση</th><th>Δημιουργία</th><th>Πληρωμή</th><th>Ενέργειες</th></tr></thead><tbody>';
		if ( ! $batches ) {
			echo '<tr><td colspan="9">Καμία εκκαθάριση ακόμα.</td></tr>';
		}
		foreach ( (array) $batches as $b ) {
			$u = get_userdata( (int) $b['partner_user_id'] );
			$paid = ( $b['status'] === 'paid' );
			echo '<tr>';
			echo '<td>' . (int) $b['id'] . '</td>';
			echo '<td>' . esc_html( $u ? $u->display_name : ( '#' . $b['partner_user_id'] ) ) . '</td>';
			echo '<td>' . esc_html( $b['period'] ?: '—' ) . '</td>';
			echo '<td>' . (int) $b['cnt'] . '</td>';
			echo '<td><strong>' . number_format( (float) $b['amount'], 2 ) . '</strong></td>';
			echo '<td>' . ( $paid ? '<span style="color:#1a7f37;font-weight:600">Πληρωμένη</span>' : '<span style="color:#bb6a00;font-weight:600">Εκκρεμεί</span>' ) . '</td>';
			echo '<td>' . esc_html( mysql2date( 'd/m/Y', $b['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( $b['paid_at'] ? mysql2date( 'd/m/Y', $b['paid_at'] ) : '—' ) . '</td>';
			echo '<td>';
			// statement pdf
			echo '<a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ecrm_payout_pdf&id=' . (int) $b['id'] ), 'ecrm_payout_pdf' ) ) . '" target="_blank">PDF</a> ';
			if ( ! $paid ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'Σήμανση ως πληρωμένη;\')">';
				wp_nonce_field( 'ecrm_pay_payout' );
				echo '<input type="hidden" name="action" value="ecrm_pay_payout"><input type="hidden" name="id" value="' . (int) $b['id'] . '">';
				echo '<button class="button button-small button-primary">Πληρώθηκε</button></form> ';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'Διαγραφή εκκαθάρισης; Οι συμβάσεις θα επιστρέψουν στις ανεξόφλητες.\')">';
			wp_nonce_field( 'ecrm_delete_payout' );
			echo '<input type="hidden" name="action" value="ecrm_delete_payout"><input type="hidden" name="id" value="' . (int) $b['id'] . '">';
			echo '<button class="button button-small button-link-delete">Διαγραφή</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ---------------------------------------------------------------------
	public static function create(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_create_payout' );
		global $wpdb;
		$partner = (int) ( $_POST['partner_user_id'] ?? 0 );
		if ( ! $partner ) { self::back(); }

		$rows = self::unsettled_rows( $partner );
		if ( ! $rows ) { self::back( 'created' ); }

		$ids = []; $total = 0.0;
		foreach ( $rows as $r ) { $ids[] = (int) $r['id']; $total += self::amount( $r ); }

		$pt = ECRM_DB::table( 'payouts' );
		$wpdb->insert( $pt, [
			'partner_user_id' => $partner,
			'period'          => gmdate( 'Y-m' ),
			'cnt'             => count( $ids ),
			'amount'          => round( $total, 2 ),
			'status'          => 'pending',
			'created_by'      => get_current_user_id(),
		] );
		$payout_id = (int) $wpdb->insert_id;

		if ( $payout_id && $ids ) {
			$ct  = ECRM_DB::table( 'contracts' );
			$in  = implode( ',', array_map( 'intval', $ids ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET payout_id = %d WHERE id IN ($in)", $payout_id ) );
		}
		self::back( 'created' );
	}

	public static function pay(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_pay_payout' );
		global $wpdb;
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id ) {
			$wpdb->update( ECRM_DB::table( 'payouts' ), [ 'status' => 'paid', 'paid_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
		}
		self::back( 'paid' );
	}

	public static function remove(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_delete_payout' );
		global $wpdb;
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id ) {
			$ct = ECRM_DB::table( 'contracts' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET payout_id = NULL WHERE payout_id = %d", $id ) );
			$wpdb->delete( ECRM_DB::table( 'payouts' ), [ 'id' => $id ] );
		}
		self::back( 'deleted' );
	}

	public static function pdf(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Δεν επιτρέπεται.' ); }
		check_admin_referer( 'ecrm_payout_pdf' );
		global $wpdb;
		$id = (int) ( $_GET['id'] ?? 0 );
		$pt = ECRM_DB::table( 'payouts' );
		$b  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pt} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $b ) { wp_die( 'Δεν βρέθηκε.' ); }

		$ct = ECRM_DB::table( 'contracts' );
		$cu = ECRM_DB::table( 'customers' );
		$pr = ECRM_DB::table( 'providers' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.code, c.provider_id, c.program_id, c.energy_type, c.category, c.status,
			        p.name AS provider_name, cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c
			 LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 LEFT JOIN {$pr} p  ON p.id  = c.provider_id
			 WHERE c.payout_id = %d ORDER BY c.code", $id
		), ARRAY_A );

		$lines = [];
		foreach ( $rows as $r ) {
			$name = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			$lines[] = [
				'code'     => $r['code'] ?: '—',
				'customer' => $name ?: '—',
				'provider' => $r['provider_name'] ?: '—',
				'amount'   => self::amount( $r ),
			];
		}
		$u = get_userdata( (int) $b['partner_user_id'] );

		try {
			$bytes = ECRM_PDF::build_statement( [
				'partner'  => $u ? $u->display_name : ( '#' . $b['partner_user_id'] ),
				'period'   => $b['period'],
				'status'   => $b['status'],
				'paid_at'  => $b['paid_at'],
				'payout_id'=> (int) $b['id'],
			], $lines );
		} catch ( \Throwable $e ) {
			wp_die( 'Σφάλμα PDF: ' . esc_html( $e->getMessage() ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="ekkatharisi-' . (int) $b['id'] . '.pdf"' );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function back( ?string $msg = null ): void {
		$url = admin_url( 'admin.php?page=energy-crm-payouts' );
		if ( $msg ) { $url = add_query_arg( 'ecrm_msg', $msg, $url ); }
		wp_safe_redirect( $url );
		exit;
	}
}
