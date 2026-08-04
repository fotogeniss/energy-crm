<?php
/**
 * Tasks & callbacks.
 *
 * Lightweight task list: a reminder/callback optionally tied to a contract,
 * assignable to a downline member, with a due date and priority. Managers see
 * their whole team's tasks; everyone sees what is assigned to them.
 *
 * REST (namespace ECRM_REST::NS):
 *   GET    /tasks?scope=own|team&filter=open|today|overdue|done
 *   POST   /tasks                      create
 *   POST   /tasks/{id}                 update (status/snooze/edit)
 *   DELETE /tasks/{id}                 delete
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Tasks {

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		// Piggyback on the existing daily digest cron.
		add_action( ECRM_Notifications::CRON_HOOK, [ __CLASS__, 'generate_renewal_reminders' ] );
		add_action( ECRM_Notifications::CRON_HOOK, [ __CLASS__, 'run_sla_escalation' ] );
	}

	/**
	 * Escalate contracts stuck in a non-final status beyond the SLA threshold:
	 * creates a high-priority task for the owner's manager (ecrm_parent) and
	 * emails them once. Idempotent via note='sla_escalation'.
	 */
	public static function run_sla_escalation(): void {
		global $wpdb;
		$days = class_exists( 'ECRM_Admin' ) ? (int) ECRM_Admin::get( 'sla_escalation_days', 10 ) : 10;
		if ( $days <= 0 ) { return; }

		$ct = ECRM_DB::table( 'contracts' );
		$t  = ECRM_DB::table( 'tasks' );
		$cu = ECRM_DB::table( 'customers' );

		// Non-final, "stuck in pipeline" statuses (drafts excluded — seller's own WIP).
		$open   = [ 'new', 'pending_signature', 'processing', 'pending' ];
		$ph     = implode( ',', array_fill( 0, count( $open ), '%s' ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.code, c.status, c.partner_user_id, c.customer_id,
			        DATEDIFF(%s, c.updated_at) AS age,
			        cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 WHERE c.status IN ($ph) AND c.updated_at < %s
			   AND c.partner_user_id IS NOT NULL
			   AND NOT EXISTS (
			       SELECT 1 FROM {$t} t WHERE t.contract_id = c.id AND t.status='open' AND t.note='sla_escalation'
			   )
			 LIMIT 300",
			array_merge( [ current_time( 'mysql' ) ], $open, [ $cutoff ] )
		), ARRAY_A );

		$labels = ECRM_DB::statuses();
		foreach ( (array) $rows as $r ) {
			$manager = (int) get_user_meta( (int) $r['partner_user_id'], 'ecrm_parent', true );
			if ( ! $manager ) { continue; } // no upline to escalate to

			$cust = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			$age  = (int) $r['age'];
			$wpdb->insert( $t, [
				'contract_id' => (int) $r['id'],
				'customer_id' => $r['customer_id'] ? (int) $r['customer_id'] : null,
				'assigned_to' => $manager,
				'created_by'  => 0,
				'title'       => sprintf( 'SLA: %s καθυστερεί %d ημέρες (%s)', $r['code'], $age, $labels[ $r['status'] ] ?? $r['status'] ),
				'note'        => 'sla_escalation',
				'due_at'      => current_time( 'Y-m-d' ) . ' 10:00:00',
				'priority'    => 'high',
				'status'      => 'open',
			] );

			$wpdb->insert( ECRM_DB::table( 'events' ), [
				'contract_id' => (int) $r['id'],
				'user_id'     => 0,
				'type'        => 'sla_escalation',
				'message'     => sprintf( 'Κλιμάκωση SLA (>%d ημέρες) στον υπεύθυνο ομάδας', $days ),
			] );

			$mu = get_userdata( $manager );
			if ( $mu && is_email( $mu->user_email ) ) {
				$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : 'Energy CRM';
				wp_mail(
					$mu->user_email,
					sprintf( '⚠️ Κλιμάκωση SLA: %s', $r['code'] ),
					sprintf(
						"Καλημέρα %s,\n\nΗ σύμβαση %s (%s) παραμένει σε «%s» για %d ημέρες και κλιμακώθηκε σε εσένα.\nΔημιουργήθηκε εργασία υψηλής προτεραιότητας στο CRM.\n\n%s",
						$mu->display_name, $r['code'], $cust ?: '—', $labels[ $r['status'] ] ?? $r['status'], $age, $company
					)
				);
			}
		}
	}

	/**
	 * Create callback tasks for contracts approaching their end_date.
	 * Idempotent: marks generated tasks with note='auto_renewal' and skips
	 * contracts that already have an open one.
	 */
	public static function generate_renewal_reminders(): void {
		global $wpdb;
		$days = class_exists( 'ECRM_Admin' ) ? (int) ECRM_Admin::get( 'renewal_reminder_days', 30 ) : 30;
		if ( $days <= 0 ) { return; }

		$ct = ECRM_DB::table( 'contracts' );
		$t  = ECRM_DB::table( 'tasks' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.code, c.customer_id, c.partner_user_id, c.end_date,
			        DATEDIFF(c.end_date, CURDATE()) AS days_left
			 FROM {$ct} c
			 WHERE c.end_date IS NOT NULL
			   AND c.end_date >= CURDATE()
			   AND c.end_date <= DATE_ADD(CURDATE(), INTERVAL %d DAY)
			   AND c.status NOT IN ('cancelled','terminated')
			   AND c.partner_user_id IS NOT NULL
			   AND NOT EXISTS (
			       SELECT 1 FROM {$t} t WHERE t.contract_id = c.id AND t.status='open' AND t.note='auto_renewal'
			   )
			 LIMIT 500",
			$days
		), ARRAY_A );

		foreach ( $rows as $r ) {
			$left = (int) $r['days_left'];
			$wpdb->insert( $t, [
				'contract_id' => (int) $r['id'],
				'customer_id' => $r['customer_id'] ? (int) $r['customer_id'] : null,
				'assigned_to' => (int) $r['partner_user_id'],
				'created_by'  => 0,
				'title'       => sprintf( 'Λήξη σύμβασης %s σε %d ημέρες — επανάκληση ανανέωσης', $r['code'], max( 0, $left ) ),
				'note'        => 'auto_renewal',
				'due_at'      => $r['end_date'] . ' 10:00:00',
				'priority'    => ( $left <= 7 ) ? 'high' : 'normal',
				'status'      => 'open',
			] );
		}
	}

	/**
	 * Οι διαδρομές /tasks μετακινήθηκαν στο EnergyCRM\Http\TasksController.
	 * Η κλάση κρατά τα cron και το due_count() για το badge του μενού.
	 */
	public static function routes(): void {
	}

	private static function can_manage(): bool {
		return current_user_can( 'ecrm_manage_team' ) || current_user_can( 'manage_options' );
	}

	/** User IDs whose tasks the current user may see/assign. */
	private static function scope_ids( string $scope ): array {
		$uid = get_current_user_id();
		if ( $scope === 'team' && self::can_manage() ) {
			return ECRM_DB::visible_user_ids( $uid );
		}
		return [ $uid ];
	}

	/** Count of open tasks due today-or-earlier for a user (nav badge). */
	public static function due_count( int $uid ): int {
		global $wpdb;
		$t = ECRM_DB::table( 'tasks' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE assigned_to = %d AND status = 'open' AND ( due_at IS NULL OR due_at <= %s )",
			$uid, current_time( 'mysql' )
		) );
	}

	/** Open tasks due today-or-earlier for a user (for the daily digest). */
	public static function due_list( int $uid, int $limit = 20 ): array {
		global $wpdb;
		$t  = ECRM_DB::table( 'tasks' );
		$ct = ECRM_DB::table( 'contracts' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT tk.title, tk.due_at, c.code AS contract_code
			 FROM {$t} tk LEFT JOIN {$ct} c ON c.id = tk.contract_id
			 WHERE tk.assigned_to = %d AND tk.status = 'open' AND ( tk.due_at IS NULL OR tk.due_at <= %s )
			 ORDER BY ( tk.due_at IS NULL ), tk.due_at ASC LIMIT %d",
			$uid, current_time( 'mysql' ), $limit
		), ARRAY_A );
		return $rows ?: [];
	}

	public static function list_tasks( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$t  = ECRM_DB::table( 'tasks' );
		$ct = ECRM_DB::table( 'contracts' );
		$cu = ECRM_DB::table( 'customers' );

		$scope  = sanitize_text_field( (string) $req->get_param( 'scope' ) ) === 'team' ? 'team' : 'own';
		$filter = sanitize_text_field( (string) $req->get_param( 'filter' ) ) ?: 'open';
		$ids    = self::scope_ids( $scope );
		$ph     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$where = "t.assigned_to IN ($ph)";
		$args  = $ids;
		$now   = current_time( 'mysql' );
		if ( $filter === 'done' ) {
			$where .= " AND t.status = 'done'";
		} else {
			$where .= " AND t.status = 'open'";
			if ( $filter === 'today' ) {
				$where .= ' AND DATE(t.due_at) = %s';
				$args[] = current_time( 'Y-m-d' );
			} elseif ( $filter === 'overdue' ) {
				$where .= ' AND t.due_at IS NOT NULL AND t.due_at < %s';
				$args[] = $now;
			}
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.*, c.code AS contract_code,
			        cu.first_name, cu.last_name, cu.company_name
			 FROM {$t} t
			 LEFT JOIN {$ct} c  ON c.id = t.contract_id
			 LEFT JOIN {$cu} cu ON cu.id = t.customer_id
			 WHERE {$where}
			 ORDER BY ( t.due_at IS NULL ), t.due_at ASC, t.id DESC
			 LIMIT 500",
			$args
		), ARRAY_A );

		$out = [];
		foreach ( $rows as $r ) {
			$u = get_userdata( (int) $r['assigned_to'] );
			$cust = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			$overdue = ( $r['status'] === 'open' && ! empty( $r['due_at'] ) && $r['due_at'] < $now );
			$out[] = [
				'id'          => (int) $r['id'],
				'title'       => $r['title'],
				'note'        => $r['note'],
				'due_at'      => $r['due_at'],
				'priority'    => $r['priority'],
				'status'      => $r['status'],
				'overdue'     => $overdue,
				'contract_id' => $r['contract_id'] ? (int) $r['contract_id'] : null,
				'contract_code' => $r['contract_code'],
				'customer'    => $cust ?: '',
				'assignee'    => $u ? $u->display_name : '',
			];
		}

		return new WP_REST_Response( [
			'ok'       => true,
			'tasks'    => $out,
			'count'    => count( $out ),
			'can_team' => self::can_manage(),
			'team'     => $scope === 'team',
		], 200 );
	}

	public static function create( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$p = $req->get_json_params() ?: $req->get_params();
		$uid = get_current_user_id();

		$title = sanitize_text_field( (string) ( $p['title'] ?? '' ) );
		if ( $title === '' ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Συμπλήρωσε τίτλο.' ], 400 );
		}

		// Assignee: self by default; managers may assign within their downline.
		$assigned = (int) ( $p['assigned_to'] ?? 0 ) ?: $uid;
		if ( $assigned !== $uid ) {
			if ( ! self::can_manage() || ! in_array( $assigned, ECRM_DB::visible_user_ids( $uid ), true ) ) {
				$assigned = $uid;
			}
		}

		$contract_id = (int) ( $p['contract_id'] ?? 0 ) ?: null;
		$customer_id = null;
		if ( $contract_id ) {
			$customer_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT customer_id FROM " . ECRM_DB::table( 'contracts' ) . " WHERE id = %d", $contract_id
			) ) ?: null;
		}

		$priority = in_array( ( $p['priority'] ?? '' ), [ 'low', 'normal', 'high' ], true ) ? $p['priority'] : 'normal';
		$due = self::clean_due( $p['due_at'] ?? '' );

		$wpdb->insert( ECRM_DB::table( 'tasks' ), [
			'contract_id' => $contract_id,
			'customer_id' => $customer_id,
			'assigned_to' => $assigned,
			'created_by'  => $uid,
			'title'       => $title,
			'note'        => isset( $p['note'] ) ? sanitize_textarea_field( (string) $p['note'] ) : null,
			'due_at'      => $due,
			'priority'    => $priority,
			'status'      => 'open',
		] );

		return new WP_REST_Response( [ 'ok' => true, 'id' => (int) $wpdb->insert_id ], 200 );
	}

	public static function update( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$t   = ECRM_DB::table( 'tasks' );
		$id  = (int) $req['id'];
		$uid = get_current_user_id();
		$p   = $req->get_json_params() ?: $req->get_params();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν βρέθηκε.' ], 404 );
		}
		// Visible if assigned to me, created by me, or within my team (managers).
		$allowed = ( (int) $row['assigned_to'] === $uid ) || ( (int) $row['created_by'] === $uid )
			|| ( self::can_manage() && in_array( (int) $row['assigned_to'], ECRM_DB::visible_user_ids( $uid ), true ) );
		if ( ! $allowed ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν επιτρέπεται.' ], 403 );
		}

		$data = [];
		if ( isset( $p['status'] ) ) {
			$st = ( $p['status'] === 'done' ) ? 'done' : 'open';
			$data['status']  = $st;
			$data['done_at'] = ( $st === 'done' ) ? current_time( 'mysql' ) : null;
		}
		if ( isset( $p['title'] ) && trim( (string) $p['title'] ) !== '' ) { $data['title'] = sanitize_text_field( (string) $p['title'] ); }
		if ( array_key_exists( 'note', $p ) ) { $data['note'] = sanitize_textarea_field( (string) $p['note'] ); }
		if ( array_key_exists( 'due_at', $p ) ) { $data['due_at'] = self::clean_due( $p['due_at'] ); }
		if ( isset( $p['priority'] ) && in_array( $p['priority'], [ 'low', 'normal', 'high' ], true ) ) { $data['priority'] = $p['priority']; }

		if ( $data ) {
			$wpdb->update( $t, $data, [ 'id' => $id ] );
		}
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	public static function remove( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$t   = ECRM_DB::table( 'tasks' );
		$id  = (int) $req['id'];
		$uid = get_current_user_id();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT assigned_to, created_by FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν βρέθηκε.' ], 404 );
		}
		$allowed = ( (int) $row['assigned_to'] === $uid ) || ( (int) $row['created_by'] === $uid )
			|| ( self::can_manage() && in_array( (int) $row['assigned_to'], ECRM_DB::visible_user_ids( $uid ), true ) );
		if ( ! $allowed ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν επιτρέπεται.' ], 403 );
		}
		$wpdb->delete( $t, [ 'id' => $id ] );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/** Normalize a due value (datetime-local or date) to MySQL datetime, or null. */
	private static function clean_due( $v ): ?string {
		$v = trim( (string) $v );
		if ( $v === '' ) { return null; }
		$v  = str_replace( 'T', ' ', $v ); // datetime-local uses 'Y-m-dTH:i'
		$ts = strtotime( $v );
		// Parse and reformat in the same tz so the wall-clock value is preserved,
		// matching current_time('mysql') used in comparisons.
		return $ts ? date( 'Y-m-d H:i:s', $ts ) : null; // phpcs:ignore WordPress.DateTime.RestrictedFunctions
	}
}
