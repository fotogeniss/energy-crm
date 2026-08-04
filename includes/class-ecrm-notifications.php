<?php
/**
 * Notifications & follow-up.
 *
 * - REST GET /notifications: live list of contracts that need attention
 *   (stale items sitting too long in a non-final status, in visible scope).
 * - Email on status change to "pending" (εκκρεμότητα) to the owner.
 * - Daily WP-Cron digest emailing each partner their open follow-ups.
 *
 * Threshold (days) and the email toggles live in Energy CRM → Ρυθμίσεις.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Notifications {

	const CRON_HOOK = 'ecrm_daily_followups';

	/** Statuses that are "open" and may need follow-up. */
	public static function open_statuses(): array {
		return [ 'draft', 'new', 'pending', 'processing', 'pending_signature', 'awaiting_signature' ];
	}

	public static function threshold_days(): int {
		$d = class_exists( 'ECRM_Admin' ) ? (int) ECRM_Admin::get( 'followup_days', 5 ) : 5;
		return max( 1, $d ?: 5 );
	}

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_digest' ] );
	}

	/** Schedule/unschedule the daily digest (called on activation/deactivation). */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// 08:00 server time, daily.
			wp_schedule_event( strtotime( 'tomorrow 8:00' ), 'daily', self::CRON_HOOK );
		}
	}
	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Follow-up rows for a set of partner user IDs.
	 *
	 * @return array{rows: array, count: int, stale: int}
	 */
	public static function followups_for( array $ids ): array {
		global $wpdb;
		if ( ! $ids ) {
			return [ 'rows' => [], 'count' => 0, 'stale' => 0 ];
		}
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$open = self::open_statuses();
		$sph  = implode( ',', array_fill( 0, count( $open ), '%s' ) );
		$days = self::threshold_days();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.code, c.status, c.updated_at,
				DATEDIFF(NOW(), c.updated_at) AS age_days,
				cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 WHERE c.partner_user_id IN ($ph) AND c.status IN ($sph)
			 ORDER BY c.updated_at ASC LIMIT 200",
			array_merge( $ids, $open )
		), ARRAY_A );

		$out = []; $stale = 0;
		$labels = ECRM_DB::statuses();
		foreach ( $rows as $r ) {
			$age = (int) $r['age_days'];
			$is_stale = $age >= $days;
			if ( $is_stale ) { $stale++; }
			$name = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			$out[] = [
				'id'       => (int) $r['id'],
				'code'     => $r['code'],
				'customer' => $name ?: '—',
				'status'   => $r['status'],
				'status_label' => $labels[ $r['status'] ] ?? $r['status'],
				'age_days' => $age,
				'stale'    => $is_stale,
			];
		}
		// Stale first.
		usort( $out, function ( $a, $b ) { return $b['age_days'] <=> $a['age_days']; } );

		return [ 'rows' => $out, 'count' => count( $out ), 'stale' => $stale ];
	}

	/** Email the owner when a contract enters "pending" (εκκρεμότητα). */
	public static function notify_status_change( int $contract_id, string $to, int $owner_id ): void {
		if ( $to !== 'pending' ) {
			return;
		}
		if ( class_exists( 'ECRM_Admin' ) && ! ECRM_Admin::get( 'notify_email', '1' ) ) {
			return;
		}
		$user = get_userdata( $owner_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.code, cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c LEFT JOIN {$cu} cu ON cu.id=c.customer_id WHERE c.id=%d", $contract_id
		), ARRAY_A );
		$name    = $row ? ( $row['company_name'] ?: trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ) ) : '';
		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name', get_bloginfo( 'name' ) ) : get_bloginfo( 'name' );
		$subject = '⚠ Εκκρεμότητα: ' . ( $row['code'] ?? '' ) . ' - ' . $name;
		$body    = sprintf(
			"Η σύμβαση %s (%s) μπήκε σε κατάσταση «Εκκρεμότητα» και χρειάζεται ενέργεια.\n\nΣυνδέσου στο CRM για λεπτομέρειες.\n\n%s",
			$row['code'] ?? '', $name ?: 'πελάτης', $company
		);
		wp_mail( $user->user_email, $subject, $body );
	}

	/** Daily digest: email each partner with open follow-ups. */
	public static function run_digest(): void {
		if ( class_exists( 'ECRM_Admin' ) && ! ECRM_Admin::get( 'notify_digest', '1' ) ) {
			return;
		}
		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name', get_bloginfo( 'name' ) ) : get_bloginfo( 'name' );
		$days    = self::threshold_days();

		$users = get_users( [ 'role__in' => array_keys( ECRM_DB::roles() ), 'fields' => [ 'ID', 'user_email', 'display_name' ] ] );
		foreach ( $users as $u ) {
			if ( ! is_email( $u->user_email ) ) { continue; }
			$data  = self::followups_for( [ (int) $u->ID ] );
			$tasks = class_exists( 'ECRM_Tasks' ) ? ECRM_Tasks::due_list( (int) $u->ID ) : [];
			if ( ! $data['stale'] && ! $tasks ) { continue; } // nothing to report

			$sections = [];

			if ( $data['stale'] ) {
				$lines = [];
				foreach ( $data['rows'] as $r ) {
					if ( ! $r['stale'] ) { continue; }
					$lines[] = sprintf( '• %s — %s (%s, %d ημέρες)', $r['code'], $r['customer'], $r['status_label'], $r['age_days'] );
				}
				$sections[] = sprintf( "Συμβάσεις που εκκρεμούν πάνω από %d ημέρες:\n\n%s", $days, implode( "\n", $lines ) );
			}

			if ( $tasks ) {
				$tlines = [];
				foreach ( $tasks as $tk ) {
					$when = $tk['due_at'] ? mysql2date( 'd/m H:i', $tk['due_at'] ) : 'χωρίς ημ/νία';
					$code = $tk['contract_code'] ? ' [' . $tk['contract_code'] . ']' : '';
					$tlines[] = sprintf( '• %s — %s%s', $tk['title'], $when, $code );
				}
				$sections[] = sprintf( "Εργασίες προς διεκπεραίωση (%d):\n\n%s", count( $tasks ), implode( "\n", $tlines ) );
			}

			$counts  = [];
			if ( $data['stale'] ) { $counts[] = $data['stale'] . ' εκκρεμότητες'; }
			if ( $tasks ) { $counts[] = count( $tasks ) . ' εργασίες'; }
			$subject = sprintf( '📋 %s - %s', implode( ' & ', $counts ), $company );
			$body    = sprintf(
				"Καλημέρα %s,\n\n%s\n\nΣυνδέσου στο CRM για να τα διαχειριστείς.\n\n%s",
				$u->display_name, implode( "\n\n", $sections ), $company
			);
			wp_mail( $u->user_email, $subject, $body );
		}
	}
}
