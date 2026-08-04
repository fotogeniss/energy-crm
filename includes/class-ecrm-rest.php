<?php
/**
 * REST endpoints powering the front-end app.
 *
 *   GET  /providers   — providers + programs + statuses + activation types
 *   POST /extract     — upload docs, returns extracted fields (Claude)
 *   POST /contracts   — create/update a contract (draft or final)
 *   GET  /contracts   — list with status filter + search + per-status counts
 *   GET  /dashboard   — stats cards, monthly chart, per-provider, live feed
 *
 * Auth: logged-in users. Scope: "own" (partner_user_id = current user).
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnergyCRM\Access\Capability;
use EnergyCRM\Domain\Contract\ContractStatus;

class ECRM_REST {

	const NS = 'ecrm/v1';

	/** Sanitize the extended fields bag and JSON-encode it. */
	private static function sanitize_extra( $extra ): ?string {
		if ( ! is_array( $extra ) || ! $extra ) {
			return null;
		}
		$clean = [];
		foreach ( $extra as $k => $v ) {
			$key = sanitize_key( $k );
			if ( $key === '' ) { continue; }
			$clean[ $key ] = sanitize_text_field( (string) $v );
		}
		return $clean ? wp_json_encode( $clean ) : null;
	}

	const AUTO_PROCESS_HOOK = 'ecrm_auto_process';

	/** Seconds to wait after signing before auto-advancing to "processing". */
	public static function auto_process_delay(): int {
		return (int) apply_filters( 'ecrm_auto_process_delay', 5 * MINUTE_IN_SECONDS );
	}

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		// Make sure our REST responses are never cached (browser/proxy) so the
		// UI always reflects the latest data right after a save.
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'no_cache_headers' ], 10, 3 );

		// Auto-advance signed contracts to "processing" after a delay.
		add_action( self::AUTO_PROCESS_HOOK, [ __CLASS__, 'run_auto_process' ] );
		add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ] );
		// Self-healing safety sweep (in case a one-off event was missed).
		if ( ! wp_next_scheduled( self::AUTO_PROCESS_HOOK . '_sweep' ) ) {
			wp_schedule_event( time() + 300, 'ecrm_5min', self::AUTO_PROCESS_HOOK . '_sweep' );
		}
		add_action( self::AUTO_PROCESS_HOOK . '_sweep', [ __CLASS__, 'run_auto_process' ] );
	}

	/** Add a 5-minute cron interval. */
	public static function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['ecrm_5min'] ) ) {
			$schedules['ecrm_5min'] = [ 'interval' => 300, 'display' => 'Every 5 minutes (Energy CRM)' ];
		}
		return $schedules;
	}

	/**
	 * Promote contracts that were signed at least `auto_process_delay()` ago from
	 * "signed" to "processing". Only touches rows still in "signed", so it never
	 * overrides an agent who already moved the contract forward.
	 */
	public static function run_auto_process( $only_id = 0 ): void {
		global $wpdb;
		$ct    = ECRM_DB::table( 'contracts' );
		$delay = self::auto_process_delay();
		// signed_at is stored in site-local time via current_time('mysql'); compare locally.
		$cutoff_local = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $delay );

		$sql  = "SELECT id FROM {$ct} WHERE status = 'signed' AND signed_at IS NOT NULL AND signed_at <= %s";
		$args = [ $cutoff_local ];
		if ( (int) $only_id > 0 ) {
			$sql   .= ' AND id = %d';
			$args[] = (int) $only_id;
		}
		$sql .= ' LIMIT 200';

		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		foreach ( $ids as $id ) {
			self::transition( (int) $id, 'processing', [
				'from'    => 'signed',
				'message' => 'Αυτόματη μετάβαση σε επεξεργασία (' . round( $delay / 60 ) . ' λεπτά μετά την υπογραφή)',
			] );
		}
	}

	public static function no_cache_headers( $response, $server, $request ) {
		if ( $request instanceof WP_REST_Request && strpos( (string) $request->get_route(), '/' . self::NS ) === 0 && $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Expires', '0' );
		}
		return $response;
	}

	/**
	 * Permission callback requiring a specific capability.
	 *
	 * Every route keeps `can_use` as the floor; the ones that create, destroy
	 * or expose money on top of that name what they need. See
	 * EnergyCRM\Access\Capability.
	 */
	private static function needs( string $capability ): callable {
		return static function () use ( $capability ) {
			return self::can_use() && current_user_can( $capability );
		};
	}

	/** True when the current user holds the capability. */
	public static function allows( string $capability ): bool {
		return current_user_can( $capability );
	}

	public static function routes(): void {
		$auth = [ __CLASS__, 'can_use' ];

		register_rest_route( self::NS, '/providers',  [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'get_providers' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/quote/pdf', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'quote_pdf' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/lookup/afm', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'lookup_afm' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/search', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'global_search' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/team/live', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'team_live' ], 'permission_callback' => self::needs( Capability::MANAGE_TEAM ) ] );
		register_rest_route( self::NS, '/filters', [
			[ 'methods' => 'GET',  'callback' => [ __CLASS__, 'filters_list' ], 'permission_callback' => $auth ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'filters_save' ], 'permission_callback' => $auth ],
		] );
		register_rest_route( self::NS, '/filters/(?P<idx>\\d+)', [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'filters_delete' ], 'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/file/(?P<id>\\d+)', [
			'methods'             => 'GET',
			'callback'            => [ 'ECRM_Files', 'serve' ],
			'permission_callback' => '__return_true', // access enforced by signed token + ownership inside serve()
		] );
		register_rest_route( self::NS, '/extract',    [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'extract' ],       'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/dashboard',  [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'dashboard' ],     'permission_callback' => $auth ] );
		register_rest_route( self::NS, '/contracts',  [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ __CLASS__, 'contracts_router' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/contracts/bulk', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'bulk' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/contracts/duplicate', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'check_duplicate' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/contracts/(?P<id>\\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_contract' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/contracts/(?P<id>\\d+)/status', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'change_status' ],
			'permission_callback' => self::needs( Capability::CHANGE_STATUS ),
		] );

		register_rest_route( self::NS, '/contracts/(?P<id>\\d+)/pdf', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'contract_pdf' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/contracts/(?P<id>\\d+)/provider-form', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'contract_provider_form' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/contracts/(?P<id>\\d+)/files', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'upload_files' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/contracts/(?P<id>\\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ __CLASS__, 'delete_contract' ],
			'permission_callback' => self::needs( Capability::DELETE_CONTRACT ),
		] );

		register_rest_route( self::NS, '/contracts/export', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'export_contracts' ],
			'permission_callback' => self::needs( Capability::EXPORT_DATA ),
		] );

		register_rest_route( self::NS, '/team', [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ __CLASS__, 'team_router' ],
			'permission_callback' => self::needs( Capability::MANAGE_TEAM ),
		] );

		register_rest_route( self::NS, '/team/(?P<id>\\d+)', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'team_update' ],
			'permission_callback' => self::needs( Capability::MANAGE_TEAM ),
		] );

		register_rest_route( self::NS, '/network', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'network' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/import/parse', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'import_parse' ],
			'permission_callback' => self::needs( Capability::IMPORT_DATA ),
		] );

		register_rest_route( self::NS, '/import/apply', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'import_apply' ],
			'permission_callback' => self::needs( Capability::IMPORT_DATA ),
		] );

		register_rest_route( self::NS, '/commissions', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'commissions' ],
			'permission_callback' => self::needs( Capability::VIEW_COMMISSIONS ),
		] );
		register_rest_route( self::NS, '/analytics', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'analytics' ],
			'permission_callback' => self::needs( Capability::VIEW_ANALYTICS ),
		] );

		register_rest_route( self::NS, '/customers', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'customers_list' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/customers/check', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'customers_check' ],
			'permission_callback' => $auth,
		] );

		// /notifications, /notifications/read και /renewals μετακινήθηκαν σε
		// EnergyCRM\Http\NotificationsController και RenewalsController.
		// Δεν επανεγγράφονται εδώ: μια διαδρομή σε δύο σημεία σημαίνει ότι
		// κερδίζει σιωπηλά όποια δηλωθεί τελευταία.

		register_rest_route( self::NS, '/contracts/(?P<id>\\d+)/renew', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'renew_contract' ],
			'permission_callback' => $auth,
		] );

		register_rest_route( self::NS, '/contracts/(?P<id>\\d+)/sign-link', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'create_sign_link' ],
			'permission_callback' => $auth,
		] );

		// Public signing endpoints (token-guarded, no login).
		register_rest_route( self::NS, '/sign/(?P<token>[a-zA-Z0-9]+)', [
			[ 'methods' => 'GET',  'callback' => [ __CLASS__, 'sign_get' ],  'permission_callback' => '__return_true' ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'sign_post' ], 'permission_callback' => '__return_true' ],
		] );
	}

	public static function can_use(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$u = wp_get_current_user();
		$ecrm_roles = class_exists( 'ECRM_DB' ) ? array_keys( ECRM_DB::roles() ) : [];
		return (bool) array_intersect( $ecrm_roles, (array) $u->roles );
	}

	public static function contracts_router( WP_REST_Request $req ) {
		return $req->get_method() === 'POST' ? self::save_contract( $req ) : self::list_contracts( $req );
	}

	// ---------------------------------------------------------------------
	public static function bulk( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$p = $req->get_json_params() ?: $req->get_params();
		$ids    = array_values( array_filter( array_map( 'intval', (array) ( $p['ids'] ?? [] ) ) ) );
		$action = sanitize_text_field( (string) ( $p['action'] ?? '' ) );
		$value  = $p['value'] ?? '';
		if ( ! $ids ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν επιλέχθηκαν συμβάσεις.' ], 400 );
		}

		// One endpoint, several operations: the capability depends on which one
		// the request asked for, so it cannot be settled by the route alone.
		$required = [
			'status' => Capability::CHANGE_STATUS,
			'delete' => Capability::DELETE_CONTRACT,
			'assign' => Capability::ASSIGN_CONTRACT,
			'export' => Capability::EXPORT_DATA,
		];
		if ( isset( $required[ $action ] ) && ! current_user_can( $required[ $action ] ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν έχεις δικαίωμα για αυτή την ενέργεια.' ], 403 );
		}

		$uid        = get_current_user_id();
		$can_manage = self::can_manage_team();
		$scope_ids  = $can_manage ? ECRM_DB::visible_user_ids( $uid ) : [ $uid ];

		$ct  = ECRM_DB::table( 'contracts' );
		$in  = implode( ',', array_map( 'intval', $ids ) );
		$sin = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, status, activation_type, partner_user_id FROM {$ct} WHERE id IN ($in) AND partner_user_id IN ($sin)",
			$scope_ids
		), ARRAY_A );
		if ( ! $rows ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Καμία προσβάσιμη σύμβαση.' ], 403 );
		}

		if ( $action === 'status' ) {
			$to     = sanitize_text_field( (string) $value );
			$target = ContractStatus::tryFromSlug( $to );
			if ( $target === null ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Μη έγκυρη κατάσταση.' ], 400 );
			}
			$gated    = class_exists( 'ECRM_Docs' ) && in_array( $to, ECRM_Docs::gate_statuses(), true );
			$updated  = 0;
			$skipped  = 0;
			$rejected = [];
			foreach ( $rows as $r ) {
				$id = (int) $r['id'];
				if ( $r['status'] === $to ) { continue; }
				if ( $gated && class_exists( 'ECRM_Docs' ) && ECRM_Docs::missing_labels( $id, $r['activation_type'] ?? '' ) ) {
					$skipped++; continue;
				}

				// The pipeline may refuse the move; report that instead of
				// counting it as done.
				$source = ContractStatus::tryFromSlug( (string) $r['status'] );
				if ( $source !== null && ! $source->canMoveTo( $target ) ) {
					$rejected[] = $source->label();
					continue;
				}

				if ( self::transition( $id, $to, [
					'user_id' => (int) $r['partner_user_id'],
					'from'    => $r['status'],
					'message' => 'Μαζική αλλαγή κατάστασης',
				] ) ) {
					$updated++;
				} else {
					$skipped++;
				}
			}

			$response = [ 'ok' => true, 'updated' => $updated, 'skipped' => $skipped ];
			if ( $rejected ) {
				$response['rejected'] = count( $rejected );
				$response['notice']   = sprintf(
					'%d σύμβαση/εις δεν άλλαξαν: δεν επιτρέπεται μετάβαση από «%s» σε «%s».',
					count( $rejected ),
					implode( '», «', array_unique( $rejected ) ),
					$target->label()
				);
			}
			return new WP_REST_Response( $response, 200 );
		}

		if ( $action === 'delete' ) {
			$okids = array_map( function ( $r ) { return (int) $r['id']; }, $rows );
			$list  = implode( ',', $okids );

			// Documents first: the row is the only pointer to the file on disk.
			\EnergyCRM\Services::files()->purgeForContracts( $okids );

			$wpdb->query( "DELETE FROM " . ECRM_DB::table( 'events' )        . " WHERE contract_id IN ($list)" );
			$wpdb->query( "DELETE FROM " . ECRM_DB::table( 'signatures' )    . " WHERE contract_id IN ($list)" );
			$wpdb->query( "DELETE FROM " . ECRM_DB::table( 'notifications' ) . " WHERE contract_id IN ($list)" );
			$wpdb->query( "DELETE FROM {$ct} WHERE id IN ($list)" );
			return new WP_REST_Response( [ 'ok' => true, 'updated' => count( $okids ) ], 200 );
		}

		if ( $action === 'assign' ) {
			$target = (int) $value;
			if ( ! $can_manage || ! in_array( $target, ECRM_DB::visible_user_ids( $uid ), true ) ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Μη επιτρεπτή ανάθεση.' ], 403 );
			}
			$okids = implode( ',', array_map( function ( $r ) { return (int) $r['id']; }, $rows ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET partner_user_id = %d WHERE id IN ($okids)", $target ) );
			return new WP_REST_Response( [ 'ok' => true, 'updated' => count( $rows ) ], 200 );
		}

		if ( $action === 'export' ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Λείπει η επέκταση ZipArchive.' ], 500 );
			}
			$okids = array_map( function ( $r ) { return (int) $r['id']; }, $rows );
			$data  = ECRM_Export::contracts_dataset( '', '', $okids, $scope_ids );
			$bytes = ECRM_Export::build_xlsx( $data['headers'], $data['rows'] );
			return new WP_REST_Response( [
				'ok' => true, 'b64' => base64_encode( $bytes ),
				'filename' => 'symvaseis-epilogi-' . gmdate( 'Ymd-Hi' ) . '.xlsx',
				'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'count' => count( $data['rows'] ),
			], 200 );
		}

		return new WP_REST_Response( [ 'ok' => false, 'error' => 'Άγνωστη ενέργεια.' ], 400 );
	}

	/**
	 * ΑΦΜ → trader name/address via the EU VIES public REST service.
	 * No API key required. Validates the check digit first.
	 */
	/** Warn about possible duplicate contracts by ΑΦΜ or supply number. */
	public static function check_duplicate( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$afm    = preg_replace( '/\D+/', '', (string) $req->get_param( 'afm' ) );
		$supply = trim( (string) $req->get_param( 'supply' ) );
		$exclude = (int) $req->get_param( 'exclude' );
		if ( ( $afm === '' || strlen( $afm ) < 9 ) && $supply === '' ) {
			return new WP_REST_Response( [ 'ok' => true, 'matches' => [] ], 200 );
		}

		$ct = ECRM_DB::table( 'contracts' );
		$cu = ECRM_DB::table( 'customers' );
		$pr = ECRM_DB::table( 'providers' );

		$cond = []; $params = [];
		if ( $afm !== '' && strlen( $afm ) >= 9 ) { $cond[] = 'cu.afm = %s'; $params[] = $afm; }
		if ( $supply !== '' )                     { $cond[] = 'c.supply_number = %s'; $params[] = $supply; }
		$where = '( ' . implode( ' OR ', $cond ) . ' )';
		if ( $exclude ) { $where .= ' AND c.id <> %d'; $params[] = $exclude; }

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.code, c.status, c.supply_number, c.partner_user_id,
			        cu.first_name, cu.last_name, cu.company_name, cu.afm, p.name AS provider_name
			 FROM {$ct} c LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 LEFT JOIN {$pr} p ON p.id = c.provider_id
			 WHERE {$where}
			 ORDER BY c.updated_at DESC LIMIT 8",
			$params
		), ARRAY_A );

		$uid       = get_current_user_id();
		$scope_ids = self::can_manage_team() ? ECRM_DB::visible_user_ids( $uid ) : [ $uid ];
		$labels    = ECRM_DB::statuses();
		$out = [];
		foreach ( (array) $rows as $r ) {
			$owner   = (int) $r['partner_user_id'];
			$in_scope = in_array( $owner, $scope_ids, true );
			$is_mine  = ( $owner === $uid );
			$cust = $in_scope ? ( $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) ) ) : '';
			$ownr = '';
			if ( $in_scope && $owner ) { $u = get_userdata( $owner ); $ownr = $u ? $u->display_name : ''; }
			$out[] = [
				'id'           => $in_scope ? (int) $r['id'] : 0, // only openable if visible
				'code'         => $r['code'],
				'status'       => $r['status'],
				'status_label' => $labels[ $r['status'] ] ?? $r['status'],
				'provider'     => $r['provider_name'],
				'customer'     => $cust ?: ( $in_scope ? '—' : 'άλλος συνεργάτης δικτύου' ),
				'owner'        => $is_mine ? 'εσύ' : ( $ownr ?: ( $in_scope ? '—' : '' ) ),
				'is_mine'      => $is_mine,
				'in_scope'     => $in_scope,
				'match_on'     => ( $afm !== '' && $r['afm'] === $afm ) ? 'afm' : 'supply',
			];
		}
		return new WP_REST_Response( [ 'ok' => true, 'matches' => $out ], 200 );
	}

	/** Live team activity dashboard (manager-gated). */
	public static function team_live( WP_REST_Request $req ): WP_REST_Response {
		if ( ! self::can_manage_team() ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Μόνο για υπεύθυνους ομάδας.' ], 403 );
		}
		global $wpdb;
		$uid = get_current_user_id();
		$ids = ECRM_DB::visible_user_ids( $uid );
		if ( ! $ids ) { $ids = [ $uid ]; }
		$ct  = ECRM_DB::table( 'contracts' );
		$tk  = ECRM_DB::table( 'tasks' );

		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$today = current_time( 'Y-m-d' );
		$month = current_time( 'Y-m-01' );

		$crows = $wpdb->get_results( $wpdb->prepare(
			"SELECT partner_user_id AS uid,
			        SUM( DATE(created_at) = %s ) AS today,
			        SUM( created_at >= %s ) AS month,
			        SUM( status = 'pending' ) AS pending,
			        SUM( status = 'routed' ) AS routed,
			        SUM( status = 'active' ) AS active,
			        MAX( updated_at ) AS last_activity
			 FROM {$ct} WHERE partner_user_id IN ($in) GROUP BY partner_user_id",
			array_merge( [ $today, $month ], $ids )
		), ARRAY_A );
		$cmap = [];
		foreach ( (array) $crows as $r ) { $cmap[ (int) $r['uid'] ] = $r; }

		$trows = $wpdb->get_results( $wpdb->prepare(
			"SELECT assigned_to AS uid, COUNT(*) AS open_tasks FROM {$tk} WHERE status='open' AND assigned_to IN ($in) GROUP BY assigned_to",
			$ids
		), ARRAY_A );
		$tmap = [];
		foreach ( (array) $trows as $r ) { $tmap[ (int) $r['uid'] ] = (int) $r['open_tasks']; }

		$roles  = ECRM_DB::roles();
		$now_ts = current_time( 'timestamp' );
		$members = [];
		$tot = [ 'today' => 0, 'month' => 0, 'pending' => 0, 'routed' => 0, 'active' => 0, 'online' => 0 ];

		foreach ( $ids as $mid ) {
			$u = get_userdata( $mid );
			if ( ! $u ) { continue; }
			$role = '';
			foreach ( (array) $u->roles as $rr ) { if ( isset( $roles[ $rr ] ) ) { $role = $roles[ $rr ]; break; } }
			$c = $cmap[ $mid ] ?? [];
			$last = $c['last_activity'] ?? '';
			$last_ts = $last ? strtotime( $last ) : 0;
			$online = ( $last_ts && ( $now_ts - $last_ts ) < 1800 ); // active in last 30'
			$row = [
				'id'         => (int) $mid,
				'name'       => $u->display_name,
				'role'       => $role ?: '—',
				'is_self'    => ( $mid === $uid ),
				'today'      => (int) ( $c['today'] ?? 0 ),
				'month'      => (int) ( $c['month'] ?? 0 ),
				'pending'    => (int) ( $c['pending'] ?? 0 ),
				'routed'     => (int) ( $c['routed'] ?? 0 ),
				'active'     => (int) ( $c['active'] ?? 0 ),
				'open_tasks' => $tmap[ $mid ] ?? 0,
				'last'       => $last,
				'online'     => $online,
			];
			$members[] = $row;
			$tot['today']   += $row['today'];
			$tot['month']   += $row['month'];
			$tot['pending'] += $row['pending'];
			$tot['routed']  += $row['routed'];
			$tot['active']  += $row['active'];
			if ( $online ) { $tot['online']++; }
		}

		// Sort: most active today first, then month.
		usort( $members, function ( $a, $b ) {
			return ( $b['today'] <=> $a['today'] ) ?: ( $b['month'] <=> $a['month'] );
		} );

		return new WP_REST_Response( [
			'ok'      => true,
			'totals'  => $tot,
			'members' => $members,
			'count'   => count( $members ),
			'ts'      => current_time( 'H:i' ),
		], 200 );
	}

	/** Scope-aware quick search across contracts + customers (topbar). */
	public static function global_search( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( mb_strlen( $q ) < 2 ) {
			return new WP_REST_Response( [ 'ok' => true, 'results' => [] ], 200 );
		}
		$uid       = get_current_user_id();
		$scope_ids = self::can_manage_team() ? ECRM_DB::visible_user_ids( $uid ) : [ $uid ];
		$sin       = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
		$ct = ECRM_DB::table( 'contracts' );
		$cu = ECRM_DB::table( 'customers' );
		$pr = ECRM_DB::table( 'providers' );

		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$params = array_merge(
			$scope_ids,
			[ $like, $like, $like, $like, $like, $like, $like ]
		);
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.code, c.status, cu.first_name, cu.last_name, cu.company_name, cu.afm, p.name AS provider_name
			 FROM {$ct} c
			 LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 LEFT JOIN {$pr} p  ON p.id = c.provider_id
			 WHERE c.partner_user_id IN ($sin)
			   AND ( c.code LIKE %s OR c.supply_number LIKE %s OR cu.first_name LIKE %s OR cu.last_name LIKE %s
			         OR cu.company_name LIKE %s OR cu.afm LIKE %s OR cu.mobile LIKE %s )
			 ORDER BY c.updated_at DESC LIMIT 15",
			$params
		), ARRAY_A );

		$st  = ECRM_DB::statuses();
		$out = [];
		foreach ( (array) $rows as $r ) {
			$cust = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			$out[] = [
				'id'           => (int) $r['id'],
				'code'         => $r['code'],
				'customer'     => $cust ?: '—',
				'afm'          => $r['afm'],
				'provider'     => $r['provider_name'],
				'status'       => $r['status'],
				'status_label' => $st[ $r['status'] ] ?? $r['status'],
			];
		}
		return new WP_REST_Response( [ 'ok' => true, 'results' => $out ], 200 );
	}

	/** Per-user saved filters (stored in user meta). */
	public static function filters_list(): WP_REST_Response {
		$f = get_user_meta( get_current_user_id(), 'ecrm_saved_filters', true );
		return new WP_REST_Response( [ 'ok' => true, 'filters' => is_array( $f ) ? array_values( $f ) : [] ], 200 );
	}

	public static function filters_save( WP_REST_Request $req ): WP_REST_Response {
		$p   = $req->get_json_params() ?: $req->get_params();
		$uid = get_current_user_id();
		$f   = get_user_meta( $uid, 'ecrm_saved_filters', true );
		$f   = is_array( $f ) ? array_values( $f ) : [];
		$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		if ( $name === '' ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δώσε όνομα.' ], 400 );
		}
		if ( count( $f ) >= 20 ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Όριο 20 αποθηκευμένων φίλτρων.' ], 400 );
		}
		$f[] = [
			'name'   => mb_substr( $name, 0, 40 ),
			'status' => sanitize_text_field( (string) ( $p['status'] ?? '' ) ),
			'q'      => sanitize_text_field( (string) ( $p['q'] ?? '' ) ),
			'scope'  => ( ( $p['scope'] ?? '' ) === 'team' ) ? 'team' : 'own',
		];
		update_user_meta( $uid, 'ecrm_saved_filters', $f );
		return new WP_REST_Response( [ 'ok' => true, 'filters' => $f ], 200 );
	}

	public static function filters_delete( WP_REST_Request $req ): WP_REST_Response {
		$uid = get_current_user_id();
		$idx = (int) $req['idx'];
		$f   = get_user_meta( $uid, 'ecrm_saved_filters', true );
		$f   = is_array( $f ) ? array_values( $f ) : [];
		if ( isset( $f[ $idx ] ) ) {
			array_splice( $f, $idx, 1 );
			update_user_meta( $uid, 'ecrm_saved_filters', $f );
		}
		return new WP_REST_Response( [ 'ok' => true, 'filters' => $f ], 200 );
	}

	public static function lookup_afm( WP_REST_Request $req ): WP_REST_Response {
		if ( class_exists( 'ECRM_RateLimit' ) && ! ECRM_RateLimit::allow( 'afm', 30, 300 ) ) {
			return ECRM_RateLimit::too_many();
		}
		$afm = preg_replace( '/\D+/', '', (string) $req->get_param( 'afm' ) );
		if ( class_exists( 'ECRM_Validate' ) && ! ECRM_Validate::afm( $afm ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Μη έγκυρο ΑΦΜ.' ], 422 );
		}
		$url  = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/EL/vat/' . rawurlencode( $afm );
		$resp = wp_remote_get( $url, [ 'timeout' => 12, 'headers' => [ 'Accept' => 'application/json' ] ] );
		if ( is_wp_error( $resp ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Αποτυχία σύνδεσης στο μητρώο (VIES).' ], 502 );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code !== 200 || ! is_array( $data ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Το μητρώο VIES δεν απάντησε (HTTP ' . $code . ').' ], 502 );
		}
		if ( empty( $data['isValid'] ) ) {
			return new WP_REST_Response( [ 'ok' => true, 'valid' => false, 'afm' => $afm ], 200 );
		}

		$name    = trim( (string) ( $data['name'] ?? '' ) );
		$address = trim( (string) ( $data['address'] ?? '' ) );
		$name    = ( $name === '---' ) ? '' : $name;
		$address = ( $address === '---' ) ? '' : $address;

		// Best-effort parse of the Greek address string (e.g. "ΟΔΟΣ 12\n15124 ΠΟΛΗ").
		$parsed = [ 'street' => '', 'street_no' => '', 'postal_code' => '', 'city' => '' ];
		if ( $address !== '' ) {
			$flat = preg_replace( '/\s+/', ' ', str_replace( [ "\n", "\r" ], ' ', $address ) );
			if ( preg_match( '/(\d{5})\s+(.+)$/u', $flat, $mm ) ) {
				$parsed['postal_code'] = $mm[1];
				$parsed['city']        = trim( $mm[2] );
				$flat = trim( str_replace( $mm[0], '', $flat ) );
			}
			if ( preg_match( '/^(.*?)\s+(\d+[Α-Ω]?)\s*$/u', $flat, $sm ) ) {
				$parsed['street']    = trim( $sm[1] );
				$parsed['street_no'] = trim( $sm[2] );
			} else {
				$parsed['street'] = $flat;
			}
		}

		return new WP_REST_Response( [
			'ok'      => true,
			'valid'   => true,
			'afm'     => $afm,
			'name'    => $name,
			'address' => $address,
			'parsed'  => $parsed,
		], 200 );
	}

	public static function quote_pdf( WP_REST_Request $req ): WP_REST_Response {
		$p = $req->get_json_params() ?: $req->get_params();
		$f = function ( $k ) use ( $p ) { return isset( $p[ $k ] ) ? (float) $p[ $k ] : 0.0; };

		$consumption    = max( 0, $f( 'consumption' ) );     // annual kWh
		$current_price  = max( 0, $f( 'current_price' ) );   // €/kWh
		$current_fixed  = max( 0, $f( 'current_fixed' ) );   // €/month
		$offered_price  = max( 0, $f( 'offered_price' ) );
		$offered_fixed  = max( 0, $f( 'offered_fixed' ) );

		$current_annual = $consumption * $current_price + 12 * $current_fixed;
		$offered_annual = $consumption * $offered_price + 12 * $offered_fixed;
		$savings        = $current_annual - $offered_annual;
		$pct            = $current_annual > 0 ? ( 100 * $savings / $current_annual ) : 0;

		$meta = [
			'customer'       => sanitize_text_field( (string) ( $p['customer_name'] ?? '' ) ),
			'provider'       => sanitize_text_field( (string) ( $p['provider_name'] ?? '' ) ),
			'program'        => sanitize_text_field( (string) ( $p['program_name'] ?? '' ) ),
			'energy'         => ECRM_DB::energy_label( sanitize_text_field( (string) ( $p['energy'] ?? 'power' ) ) ),
			'consumption'    => $consumption,
			'current_price'  => $current_price,
			'current_fixed'  => $current_fixed,
			'offered_price'  => $offered_price,
			'offered_fixed'  => $offered_fixed,
			'current_annual' => $current_annual,
			'offered_annual' => $offered_annual,
			'savings'        => $savings,
			'pct'            => $pct,
		];

		try {
			$bytes = ECRM_PDF::build_quote( $meta );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'PDF: ' . $e->getMessage() ], 500 );
		}
		return new WP_REST_Response( [
			'ok'       => true,
			'b64'      => base64_encode( $bytes ),
			'filename' => 'prosfora.pdf',
			'mime'     => 'application/pdf',
			'savings'  => round( $savings, 2 ),
		], 200 );
	}

	public static function get_providers(): WP_REST_Response {
		global $wpdb;
		$pt = ECRM_DB::table( 'providers' );
		$gt = ECRM_DB::table( 'programs' );

		return new WP_REST_Response( [
			'providers'        => $wpdb->get_results( "SELECT id, slug, name, energy_types, logo_url FROM {$pt} WHERE active = 1 ORDER BY sort_order, name", ARRAY_A ),
			'programs'         => $wpdb->get_results( "SELECT id, provider_id, name, energy_type, category, price_type, price_kwh, fixed_charge FROM {$gt} WHERE active = 1 ORDER BY sort_order, name", ARRAY_A ),
			'statuses'         => ECRM_DB::statuses(),
			'activation_types' => ECRM_DB::activation_types(),
		], 200 );
	}

	// ---------------------------------------------------------------------
	// Extraction
	// ---------------------------------------------------------------------
	public static function extract( WP_REST_Request $req ): WP_REST_Response {
		$files = $req->get_file_params();
		$kinds = (array) $req->get_param( 'kinds' );

		if ( empty( $files['files'] ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν ανέβηκαν αρχεία.' ], 400 );
		}

		$incoming = self::normalize_files( $files['files'] );
		$allowed  = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf' ];
		$prepared = [];
		$i        = 0;

		foreach ( $incoming as $file ) {
			if ( ( $file['error'] ?? UPLOAD_ERR_OK ) !== UPLOAD_ERR_OK ) { $i++; continue; }
			$type = $file['type'] ?? '';
			$ft   = wp_check_filetype( $file['name'] ?? '' );
			$mime = in_array( $type, $allowed, true ) ? $type : ( $ft['type'] ?? '' );
			if ( ! in_array( $mime, $allowed, true ) ) { $i++; continue; }
			$prepared[] = [ 'path' => $file['tmp_name'], 'mime' => $mime, 'kind' => sanitize_text_field( $kinds[ $i ] ?? 'other' ) ];
			$i++;
		}

		if ( empty( $prepared ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Μη υποστηριζόμενα αρχεία (μόνο PDF/JPG/PNG).' ], 400 );
		}
		if ( count( $prepared ) > 10 ) {
			$prepared = array_slice( $prepared, 0, 10 );
		}

		$result = ECRM_Extractor::extract( $prepared );
		return new WP_REST_Response( $result, $result['ok'] ? 200 : 502 );
	}

	private static function normalize_files( array $f ): array {
		if ( ! is_array( $f['name'] ?? null ) ) {
			return [ $f ];
		}
		$out = [];
		foreach ( $f['name'] as $idx => $name ) {
			$out[] = [
				'name'     => $name,
				'type'     => $f['type'][ $idx ] ?? '',
				'tmp_name' => $f['tmp_name'][ $idx ] ?? '',
				'error'    => $f['error'][ $idx ] ?? UPLOAD_ERR_OK,
				'size'     => $f['size'][ $idx ] ?? 0,
			];
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Save contract (+ customer)
	// ---------------------------------------------------------------------
	public static function save_contract( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$p = $req->get_json_params();
		if ( empty( $p ) ) { $p = $req->get_params(); }

		// Authorisation is resolved once, up front, and every read/write below
		// goes through a repository that requires it. See src/Access/UserScope.
		try {
			$scope = \EnergyCRM\Services::scopeResolver()->forCurrentUser();
		} catch ( \EnergyCRM\Access\NotAuthenticated $e ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Απαιτείται σύνδεση.' ], 401 );
		}
		$contracts_repo = \EnergyCRM\Services::contracts();
		$customers_repo = \EnergyCRM\Services::customers();

		// Resolve the target contract *before* touching anything. A contract the
		// actor cannot see is indistinguishable from one that does not exist.
		$contract_id  = isset( $p['contract_id'] ) ? (int) $p['contract_id'] : 0;
		$old_contract = null;
		if ( $contract_id > 0 ) {
			$old_contract = $contracts_repo->find( $contract_id, $scope );
			if ( $old_contract === null ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Η σύμβαση δεν βρέθηκε.' ], 404 );
			}
		}

		$cust_cols = [ 'customer_type','afm','doy','first_name','last_name','father_name','company_name','adt','birth_date','region','city','street','street_no','postal_code','phone','mobile','email' ];
		$customer  = [];
		foreach ( $cust_cols as $c ) {
			if ( isset( $p[ $c ] ) && $p[ $c ] !== '' ) {
				$customer[ $c ] = sanitize_text_field( (string) $p[ $c ] );
			}
		}

		// Validate ΑΦΜ (when provided) — block obvious typos via the check digit.
		if ( ! empty( $customer['afm'] ) && class_exists( 'ECRM_Validate' ) && ! ECRM_Validate::afm( $customer['afm'] ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Μη έγκυρο ΑΦΜ (αποτυχία ελέγχου ψηφίου).', 'field' => 'afm' ], 422 );
		}

		// A customer id from the request is only honoured when it is already
		// attached to the contract being edited, or otherwise reachable.
		$customer_id = isset( $p['customer_id'] ) ? (int) $p['customer_id'] : 0;
		if ( $customer_id > 0 ) {
			$linked = $old_contract !== null && (int) $old_contract['customer_id'] === $customer_id;
			if ( ! $linked && ! $customers_repo->isReachable( $customer_id, $scope ) ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Ο πελάτης δεν βρέθηκε.' ], 404 );
			}
		}

		$old_customer = ( $customer_id && $customer ) ? $customers_repo->find( $customer_id, $scope ) : null;
		if ( $customer ) {
			if ( $customer_id ) {
				$customers_repo->update( $customer_id, $scope, $customer );
			} else {
				$customer_id = $customers_repo->create( $customer );
			}
		}

		$status = in_array( ( $p['status'] ?? '' ), array_keys( ECRM_DB::statuses() ), true ) ? $p['status'] : 'draft';

		$contract = [
			'customer_id'     => $customer_id ?: null,
			'provider_id'     => isset( $p['provider_id'] ) ? (int) $p['provider_id'] : null,
			'program_id'      => isset( $p['program_id'] ) ? (int) $p['program_id'] : null,
			'energy_type'     => sanitize_text_field( $p['energy_type'] ?? 'power' ),
			'category'        => sanitize_text_field( $p['category'] ?? 'home' ),
			'price_type'      => isset( $p['price_type'] ) ? sanitize_text_field( $p['price_type'] ) : null,
			'customer_type'   => sanitize_text_field( $p['customer_type'] ?? 'individual' ),
			'activation_type' => isset( $p['activation_type'] ) ? sanitize_text_field( $p['activation_type'] ) : null,
			'supply_number'   => isset( $p['supply_number'] ) ? sanitize_text_field( $p['supply_number'] ) : null,
			'meter_number'    => isset( $p['meter_number'] ) ? sanitize_text_field( $p['meter_number'] ) : null,
			'invoice_code'    => isset( $p['invoice_code'] ) ? sanitize_text_field( $p['invoice_code'] ) : null,
			'status'          => $status,
			'notes'           => isset( $p['notes'] ) ? sanitize_textarea_field( $p['notes'] ) : null,
			'extracted_json'  => isset( $p['extracted_json'] ) ? wp_kses_post( $p['extracted_json'] ) : null,
			'extra_json'      => self::sanitize_extra( $p['extra'] ?? null ),
		];

		// Duration / expiry: start + term -> auto end date if end not provided.
		$start = isset( $p['start_date'] ) ? sanitize_text_field( $p['start_date'] ) : '';
		$term  = isset( $p['term_months'] ) ? (int) $p['term_months'] : 0;
		$end   = isset( $p['end_date'] ) ? sanitize_text_field( $p['end_date'] ) : '';
		if ( $start === '' ) { $start = null; }
		if ( $end === '' && $start && $term > 0 ) {
			$end = gmdate( 'Y-m-d', strtotime( $start . ' +' . $term . ' months' ) );
		}
		$contract['start_date']  = $start;
		$contract['term_months'] = $term ?: null;
		$contract['end_date']    = $end ?: null;

		// GDPR consent capture (timestamp + IP) when the consent box is ticked.
		if ( ! empty( $p['consent'] ) ) {
			$contract['consent_at'] = current_time( 'mysql' );
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$contract['consent_ip'] = substr( $ip, 0, 64 );
		}

		// The scope is part of the UPDATE's WHERE clause, so the ownership check
		// and the write are one statement and cannot drift apart.
		$is_update = $old_contract !== null;
		if ( $is_update ) {
			$contracts_repo->update( $contract_id, $scope, $contract );
		} else {
			$contract_id = $contracts_repo->create( $contract, $scope );
			if ( $contract_id <= 0 ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Η αποθήκευση απέτυχε.' ], 500 );
			}
			$contracts_repo->update( $contract_id, $scope, [ 'code' => sprintf( 'APP-%04d', $contract_id ) ] );
		}

		// Audit trail: log field-level diffs on edits (not on first creation).
		if ( $is_update && class_exists( 'ECRM_Audit' ) ) {
			$changes = [];
			if ( $old_customer ) { $changes += ECRM_Audit::diff( $old_customer, $customer ); }
			if ( $old_contract ) { $changes += ECRM_Audit::diff( $old_contract, $contract ); }
			ECRM_Audit::log( $contract_id, $changes );
		}

		if ( ! $is_update ) {
			$wpdb->insert( ECRM_DB::table( 'events' ), [
				'contract_id' => $contract_id,
				'user_id'     => get_current_user_id(),
				'type'        => 'created',
				'to_status'   => $status,
				'message'     => 'Αποθήκευση αίτησης',
			] );
		}

		// Auto-generate & attach the contract PDF on every save (best-effort).
		self::store_contract_pdf( (int) $contract_id );

		return new WP_REST_Response( [ 'ok' => true, 'contract_id' => $contract_id, 'customer_id' => $customer_id, 'status' => $status ], 200 );
	}

	// ---------------------------------------------------------------------
	// List contracts + counts
	// ---------------------------------------------------------------------
	public static function list_contracts( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$pr  = ECRM_DB::table( 'providers' );
		$pg  = ECRM_DB::table( 'programs' );
		$uid = get_current_user_id();

		$status = sanitize_text_field( (string) $req->get_param( 'status' ) );
		$q      = sanitize_text_field( (string) $req->get_param( 'q' ) );

		$scope = sanitize_text_field( (string) $req->get_param( 'scope' ) );
		if ( $scope === 'team' ) {
			$ids = ECRM_DB::visible_user_ids( $uid );
			$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$where  = [ "c.partner_user_id IN ($ph)" ];
			$params = $ids;
		} else {
			$where  = [ 'c.partner_user_id = %d' ];
			$params = [ $uid ];
		}

		if ( $status && array_key_exists( $status, ECRM_DB::statuses() ) ) {
			$where[]  = 'c.status = %s';
			$params[] = $status;
		}
		if ( $q !== '' ) {
			$like     = '%' . $wpdb->esc_like( $q ) . '%';
			$where[]  = '( cu.first_name LIKE %s OR cu.last_name LIKE %s OR cu.company_name LIKE %s OR cu.afm LIKE %s OR c.supply_number LIKE %s OR c.code LIKE %s )';
			array_push( $params, $like, $like, $like, $like, $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT c.id, c.code, c.status, c.energy_type, c.category, c.invoice_code, c.supply_number, c.created_at, c.updated_at, c.partner_user_id,
				p.name AS provider_name, p.slug AS provider_slug, p.logo_url AS provider_logo,
				g.name AS program_name,
				cu.first_name, cu.last_name, cu.company_name, cu.afm, cu.phone
			FROM {$ct} c
			LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			LEFT JOIN {$pr} p  ON p.id  = c.provider_id
			LEFT JOIN {$pg} g  ON g.id  = c.program_id
			WHERE {$where_sql}
			ORDER BY c.updated_at DESC
			LIMIT 200";

		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		// Who owns each contract. Resolved in one query for the whole page —
		// a per-row get_userdata() would reintroduce the N+1 we just removed.
		$owner_ids = array_values( array_unique( array_filter( array_map(
			static function ( $r ) { return (int) ( $r['partner_user_id'] ?? 0 ); },
			$rows
		) ) ) );
		$owner_names = [];
		if ( $owner_ids ) {
			foreach ( get_users( [ 'include' => $owner_ids, 'fields' => [ 'ID', 'display_name' ] ] ) as $u ) {
				$owner_names[ (int) $u->ID ] = $u->display_name;
			}
		}
		foreach ( $rows as $i => $r ) {
			$rows[ $i ]['partner_name'] = $owner_names[ (int) ( $r['partner_user_id'] ?? 0 ) ] ?? '—';
		}

		// Per-status counts (own scope), for the filter tabs.
		$counts = [ 'all' => 0 ];
		foreach ( array_keys( ECRM_DB::statuses() ) as $s ) { $counts[ $s ] = 0; }
		if ( $scope === 'team' ) {
			$ids = ECRM_DB::visible_user_ids( $uid );
			$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$crows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) c FROM {$ct} WHERE partner_user_id IN ($ph) GROUP BY status", $ids ), ARRAY_A );
		} else {
			$crows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) c FROM {$ct} WHERE partner_user_id = %d GROUP BY status", $uid ), ARRAY_A );
		}
		foreach ( $crows as $cr ) {
			$counts[ $cr['status'] ] = (int) $cr['c'];
			$counts['all'] += (int) $cr['c'];
		}

		return new WP_REST_Response( [
			'rows'     => $rows,
			'counts'   => $counts,
			'statuses' => ECRM_DB::statuses(),
		], 200 );
	}


	// ---------------------------------------------------------------------
	// Contract detail (+ history + files), ownership-guarded
	// ---------------------------------------------------------------------
	public static function get_contract( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$id  = (int) $req['id'];
		$uid = get_current_user_id();
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$pr  = ECRM_DB::table( 'providers' );
		$pg  = ECRM_DB::table( 'programs' );
		$ev  = ECRM_DB::table( 'events' );
		$fl  = ECRM_DB::table( 'files' );
		$vis_ids = ECRM_DB::visible_user_ids( $uid );
		$vis_ph  = implode( ',', array_fill( 0, count( $vis_ids ), '%d' ) );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.*, p.name AS provider_name, g.name AS program_name,
				cu.first_name, cu.last_name, cu.father_name, cu.company_name, cu.afm, cu.doy,
				cu.adt, cu.birth_date, cu.region, cu.city, cu.street, cu.street_no, cu.postal_code,
				cu.phone, cu.mobile, cu.email
			FROM {$ct} c
			LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			LEFT JOIN {$pr} p  ON p.id  = c.provider_id
			LEFT JOIN {$pg} g  ON g.id  = c.program_id
			WHERE c.id = %d AND c.partner_user_id IN ($vis_ph)",
			array_merge( [ $id ], $vis_ids )
		), ARRAY_A );

		if ( ! $row ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.' ], 404 );
		}

		$row['events'] = $wpdb->get_results( $wpdb->prepare(
			"SELECT type, from_status, to_status, message, created_at FROM {$ev} WHERE contract_id = %d ORDER BY created_at DESC",
			$id
		), ARRAY_A );

		$files = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, doc_kind, filename, mime, attachment_id, path, protected FROM {$fl} WHERE contract_id = %d ORDER BY id",
			$id
		), ARRAY_A );
		foreach ( $files as &$ff ) {
			// Always serve through the authenticated, signed endpoint — never a public URL/path.
			$ff['url']      = class_exists( 'ECRM_Files' ) ? ECRM_Files::url( (int) $ff['id'] ) : '';
			$ff['is_image'] = ( strpos( (string) $ff['mime'], 'image/' ) === 0 );
			unset( $ff['path'], $ff['attachment_id'] ); // don't leak storage details to the client
		}
		unset( $ff );
		$row['files'] = $files;
		$row['extra'] = ! empty( $row['extra_json'] ) ? (array) json_decode( $row['extra_json'], true ) : [];
		$row['track_url'] = class_exists( 'ECRM_Tracking' ) ? ECRM_Tracking::url( $id ) : '';
		$row['doc_checklist'] = class_exists( 'ECRM_Docs' ) ? ECRM_Docs::checklist( $id, $row['activation_type'] ?? '' ) : null;
		$row['doc_kinds']     = class_exists( 'ECRM_Docs' ) ? ECRM_Docs::kinds() : [];

		return new WP_REST_Response( [
			'ok'               => true,
			'contract'         => $row,
			'statuses'         => ECRM_DB::statuses(),
			'activation_types' => ECRM_DB::activation_types(),
		], 200 );
	}

	// ---------------------------------------------------------------------
	// Change status (+ log event), ownership-guarded
	// ---------------------------------------------------------------------
	/**
	 * Centralized contract status transition.
	 *
	 * Every status change in the system should go through here so the lifecycle
	 * behaves identically everywhere: it updates the status (+ updated_at, plus any
	 * extra columns such as signature audit fields), records a status_change event,
	 * and fires the in-app + customer (SMS/Viber) notifications.
	 *
	 * @param int    $id   Contract id.
	 * @param string $to   Target status slug (must exist in ECRM_DB::statuses()).
	 * @param array  $opts user_id, from, message, extra(array of cols), inapp(bool),
	 *                     sms(bool), force(bool).
	 * @return bool True if applied (or already at target), false on invalid status.
	 */
	public static function transition( int $id, string $to, array $opts = [] ): bool {
		global $wpdb;
		$ct     = ECRM_DB::table( 'contracts' );
		$target = ContractStatus::tryFromSlug( $to );
		if ( $target === null ) {
			return false;
		}

		$from = array_key_exists( 'from', $opts )
			? (string) $opts['from']
			: (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$ct} WHERE id = %d", $id ) );

		if ( $from === $to && empty( $opts['force'] ) ) {
			return true;
		}

		// Refuse moves the pipeline does not allow — reviving a cancelled
		// contract, or rewinding a signed one past its own signature.
		$current = ContractStatus::tryFromSlug( $from );
		if ( $current !== null && ! $current->canMoveTo( $target ) ) {
			return false;
		}

		$data = [ 'status' => $to, 'updated_at' => current_time( 'mysql' ) ];
		if ( ! empty( $opts['extra'] ) && is_array( $opts['extra'] ) ) {
			$data = array_merge( $data, $opts['extra'] );
		}
		$wpdb->update( $ct, $data, [ 'id' => $id ] );

		$wpdb->insert( ECRM_DB::table( 'events' ), [
			'contract_id' => $id,
			'user_id'     => (int) ( $opts['user_id'] ?? 0 ),
			'type'        => 'status_change',
			'from_status' => ( $from !== '' ? $from : null ),
			'to_status'   => $to,
			'message'     => array_key_exists( 'message', $opts ) ? $opts['message'] : null,
		] );

		if ( ( $opts['inapp'] ?? true ) && class_exists( 'ECRM_Notifications' ) ) {
			ECRM_Notifications::notify_status_change( $id, $to, (int) ( $opts['user_id'] ?? 0 ) );
		}
		if ( ( $opts['sms'] ?? true ) && class_exists( 'ECRM_Messaging' ) ) {
			ECRM_Messaging::on_status_change( $id, $to );
		}

		// When a contract is signed, queue its automatic move to "processing" so it
		// lands in the back-office queue a few minutes later without manual action.
		// The contract id is passed as an arg so several signatures within the same
		// window each get their own event (WP de-dupes identical no-arg events).
		if ( $to === 'signed' ) {
			$delay = self::auto_process_delay();
			if ( $delay > 0 ) {
				wp_schedule_single_event( time() + $delay + 5, self::AUTO_PROCESS_HOOK, [ (int) $id ] );
			}
		}
		return true;
	}

	public static function change_status( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$id  = (int) $req['id'];
		$uid = get_current_user_id();
		$ct  = ECRM_DB::table( 'contracts' );
		$p   = $req->get_json_params();
		if ( empty( $p ) ) { $p = $req->get_params(); }

		$to     = sanitize_text_field( $p['status'] ?? '' );
		$target = ContractStatus::tryFromSlug( $to );
		if ( $target === null ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Άγνωστη κατάσταση.' ], 400 );
		}

		// Scoped lookup: a manager may move a team member's contract forward,
		// which the previous "partner_user_id = me" check made impossible.
		try {
			$scope = \EnergyCRM\Services::scopeResolver()->forCurrentUser();
		} catch ( \EnergyCRM\Access\NotAuthenticated $e ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Απαιτείται σύνδεση.' ], 401 );
		}
		$current = \EnergyCRM\Services::contracts()->find( $id, $scope );
		if ( $current === null ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.' ], 404 );
		}

		$from = (string) $current['status'];
		if ( $from === $to ) {
			return new WP_REST_Response( [ 'ok' => true, 'status' => $to ], 200 );
		}

		$source = ContractStatus::tryFromSlug( $from );
		if ( $source !== null && ! $source->canMoveTo( $target ) ) {
			return new WP_REST_Response( [
				'ok'    => false,
				'error' => sprintf(
					'Δεν επιτρέπεται μετάβαση από «%s» σε «%s».',
					$source->label(),
					$target->label()
				),
				'allowed' => array_map(
					static function ( ContractStatus $s ) {
						return [ 'status' => $s->value, 'label' => $s->label() ];
					},
					$source->allowedNext()
				),
			], 409 );
		}

		// Document gate: block advancing into routed/active unless required docs are present.
		if ( class_exists( 'ECRM_Docs' ) && in_array( $to, ECRM_Docs::gate_statuses(), true ) ) {
			$missing = ECRM_Docs::missing_labels( $id, $current['activation_type'] ?? '' );
			if ( $missing ) {
				return new WP_REST_Response( [
					'ok'      => false,
					'error'   => 'Λείπουν δικαιολογητικά: ' . implode( ', ', $missing ),
					'missing' => $missing,
				], 422 );
			}
		}

		self::transition( $id, $to, [
			'user_id' => $uid,
			'from'    => $from,
			'message' => isset( $p['message'] ) ? sanitize_textarea_field( $p['message'] ) : null,
		] );

		return new WP_REST_Response( [ 'ok' => true, 'status' => $to ], 200 );
	}


	// ---------------------------------------------------------------------
	// Export contracts to .xlsx (base64 payload; JS triggers the download)
	// ---------------------------------------------------------------------
	public static function export_contracts( WP_REST_Request $req ): WP_REST_Response {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Λείπει η επέκταση ZipArchive στον server.' ], 500 );
		}
		$status = sanitize_text_field( (string) $req->get_param( 'status' ) );
		$q      = sanitize_text_field( (string) $req->get_param( 'q' ) );
		$from   = sanitize_text_field( (string) $req->get_param( 'from' ) );
		$to     = sanitize_text_field( (string) $req->get_param( 'to' ) );
		$scope  = sanitize_text_field( (string) $req->get_param( 'scope' ) );
		$partner = (int) $req->get_param( 'partner' );

		$uid     = get_current_user_id();
		$visible = self::can_manage_team() ? ECRM_DB::visible_user_ids( $uid ) : [ $uid ];
		if ( $partner && in_array( $partner, $visible, true ) ) {
			$scope_ids = [ $partner ];                 // a specific seller within scope
		} elseif ( $scope === 'team' && self::can_manage_team() ) {
			$scope_ids = $visible;                     // whole team
		} else {
			$scope_ids = [ $uid ];                     // only me
		}

		// Validate date format (YYYY-MM-DD) loosely.
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '';
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : '';

		$data  = ECRM_Export::contracts_dataset( $status, $q, [], $scope_ids, $from, $to );
		$bytes = ECRM_Export::build_xlsx( $data['headers'], $data['rows'] );

		$name = 'symvaseis-' . gmdate( 'Ymd-Hi' ) . '.xlsx';
		return new WP_REST_Response( [
			'ok'       => true,
			'filename' => $name,
			'mime'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'count'    => count( $data['rows'] ),
			'b64'      => base64_encode( $bytes ),
		], 200 );
	}


	// ---------------------------------------------------------------------
	// Team: list / create members (sellers, registrars) under current user
	// ---------------------------------------------------------------------
	public static function team_router( WP_REST_Request $req ) {
		return $req->get_method() === 'POST' ? self::team_create( $req ) : self::team_list();
	}

	public static function can_manage_team(): bool {
		return current_user_can( 'ecrm_manage_team' ) || current_user_can( 'manage_options' );
	}

	public static function team_list(): WP_REST_Response {
		$uid = get_current_user_id();
		$members = get_users( [
			'meta_key'   => 'ecrm_parent',
			'meta_value' => $uid,
			'orderby'    => 'display_name',
		] );

		$roles = ECRM_DB::roles();
		$out   = [];
		foreach ( $members as $u ) {
			$role = '';
			foreach ( (array) $u->roles as $r ) {
				if ( isset( $roles[ $r ] ) ) { $role = $r; break; }
			}
			if ( $role === 'ecrm_partner' ) { continue; } // partners belong to "network", not "team"
			$out[] = [
				'id'           => $u->ID,
				'name'         => $u->display_name,
				'email'        => $u->user_email,
				'role'         => $role,
				'role_label'   => $roles[ $role ] ?? '—',
				'active'       => ! (bool) get_user_meta( $u->ID, 'ecrm_disabled', true ),
				'contracts'    => self::count_contracts_for( $u->ID ),
			];
		}

		return new WP_REST_Response( [ 'ok' => true, 'members' => $out, 'can_manage' => self::can_manage_team(), 'roles' => $roles ], 200 );
	}

	private static function count_contracts_for( int $uid ): int {
		global $wpdb;
		$ct = ECRM_DB::table( 'contracts' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id = %d", $uid ) );
	}

	public static function team_create( WP_REST_Request $req ): WP_REST_Response {
		if ( ! self::can_manage_team() ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν έχεις δικαίωμα διαχείρισης ομάδας.' ], 403 );
		}
		$p     = $req->get_json_params() ?: $req->get_params();
		$name  = sanitize_text_field( $p['name'] ?? '' );
		$email = sanitize_email( $p['email'] ?? '' );
		$role  = in_array( ( $p['role'] ?? '' ), [ 'ecrm_seller', 'ecrm_registrar', 'ecrm_partner' ], true ) ? $p['role'] : 'ecrm_seller';
		$pass  = (string) ( $p['password'] ?? '' );

		if ( ! $name || ! is_email( $email ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Συμπλήρωσε όνομα και έγκυρο email.' ], 400 );
		}
		if ( email_exists( $email ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Υπάρχει ήδη χρήστης με αυτό το email.' ], 409 );
		}
		if ( strlen( $pass ) < 6 ) {
			$pass = wp_generate_password( 12, true );
		}

		$username = sanitize_user( current( explode( '@', $email ) ) . '_' . wp_rand( 100, 999 ), true );
		$user_id  = wp_insert_user( [
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => $pass,
			'display_name' => $name,
			'role'         => $role,
		] );

		if ( is_wp_error( $user_id ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => $user_id->get_error_message() ], 400 );
		}

		update_user_meta( $user_id, 'ecrm_parent', get_current_user_id() );

		return new WP_REST_Response( [
			'ok'       => true,
			'id'       => $user_id,
			'username' => $username,
			'password' => $pass, // shown once so the partner can hand it over
		], 200 );
	}

	public static function team_update( WP_REST_Request $req ): WP_REST_Response {
		if ( ! self::can_manage_team() ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν έχεις δικαίωμα.' ], 403 );
		}
		$id = (int) $req['id'];
		// Ownership: the member must be a direct report of the current user.
		if ( (int) get_user_meta( $id, 'ecrm_parent', true ) !== get_current_user_id() ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν ανήκει στην ομάδα σου.' ], 403 );
		}
		$p  = $req->get_json_params() ?: $req->get_params();
		$op = sanitize_text_field( $p['op'] ?? '' );

		if ( $op === 'toggle' ) {
			$now = (bool) get_user_meta( $id, 'ecrm_disabled', true );
			update_user_meta( $id, 'ecrm_disabled', $now ? '' : '1' );
			return new WP_REST_Response( [ 'ok' => true, 'active' => $now ], 200 );
		}
		if ( $op === 'remove' ) {
			// Detach from the team (do not delete the WP account/data).
			delete_user_meta( $id, 'ecrm_parent' );
			update_user_meta( $id, 'ecrm_disabled', '1' );
			return new WP_REST_Response( [ 'ok' => true, 'removed' => true ], 200 );
		}
		return new WP_REST_Response( [ 'ok' => false, 'error' => 'Άγνωστη ενέργεια.' ], 400 );
	}

	// ---------------------------------------------------------------------
	// Network: sub-partners (role ecrm_partner) in the downline
	// ---------------------------------------------------------------------
	public static function network(): WP_REST_Response {
		$uid     = get_current_user_id();
		$members = get_users( [
			'meta_key'   => 'ecrm_parent',
			'meta_value' => $uid,
			'orderby'    => 'display_name',
		] );
		$out = [];
		foreach ( $members as $u ) {
			if ( ! in_array( 'ecrm_partner', (array) $u->roles, true ) ) { continue; }
			$out[] = [
				'id'        => $u->ID,
				'name'      => $u->display_name,
				'email'     => $u->user_email,
				'contracts' => self::count_contracts_for( $u->ID ),
				'team_size' => count( get_users( [ 'meta_key' => 'ecrm_parent', 'meta_value' => $u->ID, 'fields' => 'ID' ] ) ),
			];
		}
		return new WP_REST_Response( [ 'ok' => true, 'partners' => $out ], 200 );
	}


	// ---------------------------------------------------------------------
	// Generate a filled application PDF for a contract (base64 payload)
	// ---------------------------------------------------------------------
	public static function contract_pdf( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$id  = (int) $req['id'];
		$uid = get_current_user_id();
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$pr  = ECRM_DB::table( 'providers' );
		$pg  = ECRM_DB::table( 'programs' );

		// Allow viewing within the user's visible downline.
		$ids = ECRM_DB::visible_user_ids( $uid );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.*, p.name AS provider_name, g.name AS program_name,
				cu.first_name, cu.last_name, cu.father_name, cu.company_name, cu.afm, cu.doy,
				cu.adt, cu.birth_date, cu.region, cu.city, cu.street, cu.street_no, cu.postal_code,
				cu.phone, cu.mobile, cu.email
			FROM {$ct} c
			LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			LEFT JOIN {$pr} p  ON p.id  = c.provider_id
			LEFT JOIN {$pg} g  ON g.id  = c.program_id
			WHERE c.id = %d AND c.partner_user_id IN ($ph)",
			array_merge( [ $id ], $ids )
		), ARRAY_A );

		if ( ! $row ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.' ], 404 );
		}

		// "Δημιουργία PDF": generate & attach without sending the file for download.
		if ( (int) $req->get_param( 'store' ) === 1 ) {
			$ok = self::store_contract_pdf( $id );
			return new WP_REST_Response(
				$ok ? [ 'ok' => true, 'stored' => true, 'message' => 'Το PDF δημιουργήθηκε και αποθηκεύτηκε.' ]
				    : [ 'ok' => false, 'error' => 'Δεν ήταν δυνατή η δημιουργία του PDF.' ],
				$ok ? 200 : 500
			);
		}
		@set_time_limit( 60 );
		$er = error_reporting();
		error_reporting( 0 );
		try {
			ob_start();
			$bytes = ECRM_PDF::build( $row );
			ob_end_clean();
		} catch ( \Throwable $e ) {
			if ( ob_get_level() > 0 ) { ob_end_clean(); }
			error_reporting( $er );
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Σφάλμα δημιουργίας PDF: ' . $e->getMessage() ], 500 );
		}
		error_reporting( $er );

		// Strip any stray output (e.g. PHP notices) emitted before the PDF header.
		$pos = strpos( (string) $bytes, '%PDF-' );
		if ( $pos === false ) {
			$head = substr( (string) $bytes, 0, 300 );
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Το PDF δεν δημιουργήθηκε σωστά. Πρώτα bytes: ' . $head ], 500 );
		}
		if ( $pos > 0 ) { $bytes = substr( $bytes, $pos ); }

		$name = ( $row['code'] ?: ( 'symvasi-' . $id ) ) . '.pdf';
		return new WP_REST_Response( [
			'ok'       => true,
			'filename' => $name,
			'mime'     => 'application/pdf',
			'b64'      => base64_encode( $bytes ),
		], 200 );
	}

	/**
	 * Generate the contract PDF and store it as a 'contract' document attached to
	 * the contract (replacing any previous one). Best-effort: never throws.
	 *
	 * @return bool true on success.
	 */
	public static function store_contract_pdf( int $id ): bool {
		global $wpdb;
		$ct = ECRM_DB::table( 'contracts' );
		$cu = ECRM_DB::table( 'customers' );
		$pr = ECRM_DB::table( 'providers' );
		$pg = ECRM_DB::table( 'programs' );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.*, p.name AS provider_name, g.name AS program_name,
				cu.first_name, cu.last_name, cu.father_name, cu.company_name, cu.afm, cu.doy,
				cu.adt, cu.birth_date, cu.region, cu.city, cu.street, cu.street_no, cu.postal_code,
				cu.phone, cu.mobile, cu.email
			FROM {$ct} c
			LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			LEFT JOIN {$pr} p  ON p.id  = c.provider_id
			LEFT JOIN {$pg} g  ON g.id  = c.program_id
			WHERE c.id = %d", $id
		), ARRAY_A );
		if ( ! $row ) { return false; }

		@ini_set( 'memory_limit', '256M' );
		@set_time_limit( 60 );
		$er = error_reporting();
		error_reporting( 0 );

		// Prefer the official provider form (what the user prints/downloads); if the
		// provider has no template, fall back to the internal contract summary.
		$bytes = '';
		if ( class_exists( 'ECRM_FormFill' ) ) {
			try {
				ob_start();
				$ff = ECRM_FormFill::fill( $row );
				ob_end_clean();
				if ( ! empty( $ff['ok'] ) && ! empty( $ff['bytes'] ) ) {
					$bytes = $ff['bytes'];
				}
			} catch ( \Throwable $e ) {
				if ( ob_get_level() > 0 ) { ob_end_clean(); }
			}
		}
		if ( $bytes === '' ) {
			try {
				ob_start();
				$bytes = ECRM_PDF::build( $row );
				ob_end_clean();
			} catch ( \Throwable $e ) {
				if ( ob_get_level() > 0 ) { ob_end_clean(); }
				error_reporting( $er );
				return false;
			}
		}
		error_reporting( $er );

		$at = strpos( (string) $bytes, '%PDF-' );
		if ( $at === false ) { return false; }
		if ( $at > 0 ) { $bytes = substr( $bytes, $at ); }

		if ( ! class_exists( 'ECRM_Files' ) ) { return false; }
		$code  = $row['code'] ?: ( 'symvasi-' . $id );
		$saved = ECRM_Files::put_bytes( $bytes, 'pdf', 'application/pdf', $code . '.pdf' );
		if ( ! $saved ) { return false; }

		$fl = ECRM_DB::table( 'files' );
		// Replace any previous auto-generated contract PDF.
		$wpdb->delete( $fl, [ 'contract_id' => $id, 'doc_kind' => 'contract' ] );
		$wpdb->insert( $fl, [
			'contract_id' => $id,
			'doc_kind'    => 'contract',
			'filename'    => $saved['filename'],
			'mime'        => $saved['mime'],
			'path'        => $saved['path'],
			'protected'   => 1,
		] );
		return true;
	}
	public static function contract_provider_form( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$id  = (int) $req['id'];
		$uid = get_current_user_id();
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$pr  = ECRM_DB::table( 'providers' );
		$pg  = ECRM_DB::table( 'programs' );

		$ids = ECRM_DB::visible_user_ids( $uid );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.*, p.name AS provider_name, g.name AS program_name,
				cu.first_name, cu.last_name, cu.father_name, cu.company_name, cu.afm, cu.doy,
				cu.adt, cu.birth_date, cu.region, cu.city, cu.street, cu.street_no, cu.postal_code,
				cu.phone, cu.mobile, cu.email
			FROM {$ct} c
			LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			LEFT JOIN {$pr} p  ON p.id  = c.provider_id
			LEFT JOIN {$pg} g  ON g.id  = c.program_id
			WHERE c.id = %d AND c.partner_user_id IN ($ph)",
			array_merge( [ $id ], $ids )
		), ARRAY_A );

		if ( ! $row ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.' ], 404 );
		}

		// Latest signature image for this contract, if one exists.
		$sig_path = null;
		$fl = ECRM_DB::table( 'files' );
		$sig = $wpdb->get_var( $wpdb->prepare(
			"SELECT path FROM {$fl} WHERE contract_id = %d AND doc_kind = 'signature' ORDER BY id DESC LIMIT 1",
			$id
		) );
		if ( $sig && file_exists( $sig ) ) { $sig_path = $sig; }

		$res = ECRM_FormFill::fill( $row, $sig_path );
		if ( empty( $res['ok'] ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => $res['error'] ?? 'Αποτυχία.' ], 422 );
		}

		return new WP_REST_Response( [
			'ok'       => true,
			'filename' => $res['filename'],
			'mime'     => 'application/pdf',
			'b64'      => base64_encode( $res['bytes'] ),
		], 200 );
	}


	// ---------------------------------------------------------------------
	// Import provider Excel/CSV -> parse, then apply status updates
	// ---------------------------------------------------------------------
	private static function can_import(): bool {
		return current_user_can( 'ecrm_manage_team' ) || current_user_can( 'manage_options' );
	}

	public static function import_parse( WP_REST_Request $req ): WP_REST_Response {
		if ( ! self::can_import() ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν έχεις δικαίωμα εισαγωγής.' ], 403 );
		}
		$files = $req->get_file_params();
		$f = $files['file'] ?? null;
		if ( ! $f || ( $f['error'] ?? 1 ) !== UPLOAD_ERR_OK ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν ανέβηκε αρχείο.' ], 400 );
		}
		$res = ECRM_Import::parse( $f['tmp_name'], $f['name'] ?? 'file.xlsx' );
		return new WP_REST_Response( $res, $res['ok'] ? 200 : 400 );
	}

	public static function import_apply( WP_REST_Request $req ): WP_REST_Response {
		if ( ! self::can_import() ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν έχεις δικαίωμα εισαγωγής.' ], 403 );
		}
		$p     = $req->get_json_params() ?: $req->get_params();
		$pairs = is_array( $p['pairs'] ?? null ) ? $p['pairs'] : [];
		$dry   = ! empty( $p['dry'] );
		if ( ! $pairs ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν δόθηκαν εγγραφές.' ], 400 );
		}
		$report = ECRM_Import::apply( $pairs, $dry );
		return new WP_REST_Response( $report, 200 );
	}


	// ---------------------------------------------------------------------
	// Commissions: per-contract amounts + summary for own/team scope
	// ---------------------------------------------------------------------
	public static function commissions( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$pr  = ECRM_DB::table( 'providers' );
		$uid = get_current_user_id();

		$scope = sanitize_text_field( (string) $req->get_param( 'scope' ) );
		$ids   = $scope === 'team' ? ECRM_DB::visible_user_ids( $uid ) : [ $uid ];
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$payable = ECRM_DB::payable_statuses();
		$sph     = implode( ',', array_fill( 0, count( $payable ), '%s' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.code, c.status, c.provider_id, c.program_id, c.energy_type, c.category,
				c.updated_at, c.payout_id, po.status AS payout_status,
				p.name AS provider_name, cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c
			 LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 LEFT JOIN {$pr} p  ON p.id  = c.provider_id
			 LEFT JOIN " . ECRM_DB::table( 'payouts' ) . " po ON po.id = c.payout_id
			 WHERE c.partner_user_id IN ($ph) AND c.status IN ($sph)
			 ORDER BY c.updated_at DESC LIMIT 2000",
			array_merge( $ids, $payable )
		), ARRAY_A );

		$has_rules = class_exists( 'ECRM_Commissions' );
		$total = 0.0; $out = [];
		$paid_total = 0.0; $unpaid_total = 0.0;
		$months = []; // 'YYYY-MM' => [count, amount]
		foreach ( $rows as $r ) {
			$amt    = $has_rules ? ECRM_Commissions::amount_for( $r ) : 0.0;
			$total += $amt;
			$is_paid = ( ( $r['payout_status'] ?? '' ) === 'paid' );
			if ( $is_paid ) { $paid_total += $amt; } else { $unpaid_total += $amt; }
			$name   = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			$out[]  = [ 'code' => $r['code'], 'customer' => $name ?: '—', 'provider' => $r['provider_name'] ?: '—', 'amount' => round( $amt, 2 ), 'paid' => $is_paid ];
			$mk = substr( (string) $r['updated_at'], 0, 7 );
			if ( ! isset( $months[ $mk ] ) ) { $months[ $mk ] = [ 'count' => 0, 'amount' => 0.0 ]; }
			$months[ $mk ]['count']++; $months[ $mk ]['amount'] += $amt;
		}

		// Pending estimate: commission of not-yet-payable contracts.
		$pend_st = [ 'new', 'processing', 'pending_signature', 'pending' ];
		$psph    = implode( ',', array_fill( 0, count( $pend_st ), '%s' ) );
		$prows = $wpdb->get_results( $wpdb->prepare(
			"SELECT provider_id, program_id, energy_type, category FROM {$ct}
			 WHERE partner_user_id IN ($ph) AND status IN ($psph) LIMIT 2000",
			array_merge( $ids, $pend_st )
		), ARRAY_A );
		$pending_est = 0.0;
		foreach ( $prows as $r ) { $pending_est += $has_rules ? ECRM_Commissions::amount_for( $r ) : 0.0; }

		// Monthly list (newest first) with Greek labels.
		krsort( $months );
		$gr = [ 1=>'Ιανουάριος',2=>'Φεβρουάριος',3=>'Μάρτιος',4=>'Απρίλιος',5=>'Μάιος',6=>'Ιούνιος',7=>'Ιούλιος',8=>'Αύγουστος',9=>'Σεπτέμβριος',10=>'Οκτώβριος',11=>'Νοέμβριος',12=>'Δεκέμβριος' ];
		$month_list = []; $best = 0.0; $best_label = '';
		foreach ( $months as $mk => $v ) {
			$mn = (int) substr( $mk, 5, 2 );
			$lbl = ( $gr[ $mn ] ?? $mk ) . ' ' . substr( $mk, 0, 4 );
			$month_list[] = [ 'label' => $lbl, 'count' => $v['count'], 'amount' => round( $v['amount'], 2 ) ];
			if ( $v['amount'] > $best ) { $best = $v['amount']; $best_label = $gr[ $mn ] ?? $mk; }
		}

		return new WP_REST_Response( [
			'ok'           => true,
			'rows'         => $out,
			'total'        => round( $total, 2 ),
			'paid_total'   => round( $paid_total, 2 ),
			'unpaid_total' => round( $unpaid_total, 2 ),
			'count'        => count( $out ),
			'months'       => $month_list,
			'best'         => round( $best, 2 ),
			'best_label'   => $best_label,
			'pending_est'  => round( $pending_est, 2 ),
		], 200 );
	}

	// ---------------------------------------------------------------------
	// E-signature: create a public signing link
	// ---------------------------------------------------------------------
	public static function analytics( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$pr  = ECRM_DB::table( 'providers' );
		$ev  = ECRM_DB::table( 'events' );
		$uid = get_current_user_id();

		$scope = sanitize_text_field( (string) $req->get_param( 'scope' ) );
		$can_team = current_user_can( 'ecrm_manage_team' ) || current_user_can( 'manage_options' );
		$ids = ( $scope === 'team' && $can_team ) ? ECRM_DB::visible_user_ids( $uid ) : [ $uid ];
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// --- funnel by status ---
		$status_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) c FROM {$ct} WHERE partner_user_id IN ($ph) GROUP BY status",
			$ids
		), ARRAY_A );
		$by_status = []; $total = 0;
		foreach ( $status_rows as $r ) { $by_status[ $r['status'] ] = (int) $r['c']; $total += (int) $r['c']; }
		$labels = ECRM_DB::statuses();
		$funnel = [];
		foreach ( $labels as $slug => $lbl ) {
			$funnel[] = [ 'status' => $slug, 'label' => $lbl, 'count' => $by_status[ $slug ] ?? 0 ];
		}

		$won       = ( $by_status['routed'] ?? 0 ) + ( $by_status['active'] ?? 0 ) + ( $by_status['resolved'] ?? 0 );
		$lost      = ( $by_status['cancelled'] ?? 0 ) + ( $by_status['terminated'] ?? 0 );
		$conv_rate = $total ? round( 100 * $won / $total, 1 ) : 0.0;
		$canc_rate = $total ? round( 100 * $lost / $total, 1 ) : 0.0;

		// --- avg time to activation (days) from created_at → first 'active' event ---
		$avg_days = $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(DATEDIFF(e.activated, c.created_at))
			 FROM {$ct} c
			 JOIN ( SELECT contract_id, MIN(created_at) activated FROM {$ev}
			        WHERE to_status='active' GROUP BY contract_id ) e ON e.contract_id = c.id
			 WHERE c.partner_user_id IN ($ph)",
			$ids
		) );
		$avg_days = $avg_days !== null ? round( (float) $avg_days, 1 ) : null;

		// --- by provider (top) ---
		$by_provider = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.name, COUNT(*) c FROM {$ct} ct LEFT JOIN {$pr} p ON p.id=ct.provider_id
			 WHERE ct.partner_user_id IN ($ph) GROUP BY ct.provider_id ORDER BY c DESC LIMIT 8",
			$ids
		), ARRAY_A );

		// --- by energy type ---
		$by_energy_raw = $wpdb->get_results( $wpdb->prepare(
			"SELECT energy_type, COUNT(*) c FROM {$ct} WHERE partner_user_id IN ($ph) GROUP BY energy_type ORDER BY c DESC",
			$ids
		), ARRAY_A );
		$by_energy = [];
		foreach ( $by_energy_raw as $r ) { $by_energy[] = [ 'label' => ECRM_DB::energy_label( (string) $r['energy_type'] ), 'count' => (int) $r['c'] ]; }

		// --- by region (top) ---
		$by_region = $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(NULLIF(cu.region,''),'—') name, COUNT(*) c
			 FROM {$ct} ct LEFT JOIN {$cu} cu ON cu.id=ct.customer_id
			 WHERE ct.partner_user_id IN ($ph) GROUP BY name ORDER BY c DESC LIMIT 8",
			$ids
		), ARRAY_A );

		// --- monthly trend (current year) ---
		$year = (int) gmdate( 'Y' );
		$mrows = $wpdb->get_results( $wpdb->prepare(
			"SELECT MONTH(created_at) m, COUNT(*) c FROM {$ct} WHERE partner_user_id IN ($ph) AND YEAR(created_at)=%d GROUP BY MONTH(created_at)",
			array_merge( $ids, [ $year ] )
		), ARRAY_A );
		$monthly = array_fill( 1, 12, 0 );
		foreach ( $mrows as $r ) { $monthly[ (int) $r['m'] ] = (int) $r['c']; }

		// --- leaderboard (team scope): payable contracts + commission per partner ---
		$leaderboard = [];
		if ( $scope === 'team' && $can_team ) {
			$payable = ECRM_DB::payable_statuses();
			$sph     = implode( ',', array_fill( 0, count( $payable ), '%s' ) );
			$prows   = $wpdb->get_results( $wpdb->prepare(
				"SELECT partner_user_id, provider_id, program_id, energy_type, category, status
				 FROM {$ct} WHERE partner_user_id IN ($ph) AND status IN ($sph) LIMIT 5000",
				array_merge( $ids, $payable )
			), ARRAY_A );
			$has_rules = class_exists( 'ECRM_Commissions' );
			$agg = [];
			foreach ( $prows as $r ) {
				$pu = (int) $r['partner_user_id'];
				if ( ! isset( $agg[ $pu ] ) ) { $agg[ $pu ] = [ 'count' => 0, 'amount' => 0.0 ]; }
				$agg[ $pu ]['count']++;
				$agg[ $pu ]['amount'] += $has_rules ? ECRM_Commissions::amount_for( $r ) : 0.0;
			}
			uasort( $agg, function ( $a, $b ) { return $b['amount'] <=> $a['amount']; } );
			foreach ( $agg as $pu => $v ) {
				$u = get_userdata( $pu );
				$leaderboard[] = [
					'name'   => $u ? $u->display_name : ( '#' . $pu ),
					'count'  => $v['count'],
					'amount' => round( $v['amount'], 2 ),
				];
				if ( count( $leaderboard ) >= 15 ) { break; }
			}
		}

		return new WP_REST_Response( [
			'ok'          => true,
			'scope'       => ( $scope === 'team' && $can_team ) ? 'team' : 'own',
			'can_team'    => $can_team,
			'total'       => $total,
			'won'         => $won,
			'lost'        => $lost,
			'conv_rate'   => $conv_rate,
			'canc_rate'   => $canc_rate,
			'avg_days'    => $avg_days,
			'funnel'      => $funnel,
			'by_provider' => $by_provider,
			'by_energy'   => $by_energy,
			'by_region'   => $by_region,
			'monthly'     => array_values( $monthly ),
			'leaderboard' => $leaderboard,
		], 200 );
	}

	public static function create_sign_link( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$id  = (int) $req['id'];
		$uid = get_current_user_id();
		$ct  = ECRM_DB::table( 'contracts' );
		$ids = ECRM_DB::visible_user_ids( $uid );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$ct} WHERE id=%d AND partner_user_id IN ($ph)", array_merge( [ $id ], $ids ) ) );
		if ( ! $exists ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.' ], 404 );
		}

		// Unified e-signature: the customer signs straight from the tracking page.
		// Status uses the single canonical value the tracking page expects, and
		// the link we hand back / email is the stateless tracking URL.
		self::transition( $id, 'pending_signature', [
			'user_id' => $uid,
			'message' => 'Αποστολή για υπογραφή — αναμονή υπογραφής πελάτη',
		] );

		$url = class_exists( 'ECRM_Tracking' ) ? ECRM_Tracking::url( $id ) : add_query_arg( 'ecrm_track', '', home_url( '/' ) );

		// Optionally email the link to the customer.
		$p = $req->get_json_params() ?: $req->get_params();
		$emailed = false;
		if ( ! empty( $p['email'] ) ) {
			$cu = ECRM_DB::table( 'customers' );
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT cu.email, cu.first_name, cu.last_name, cu.company_name FROM {$ct} c LEFT JOIN {$cu} cu ON cu.id=c.customer_id WHERE c.id=%d", $id
			), ARRAY_A );
			$to = is_email( $row['email'] ?? '' ) ? $row['email'] : '';
			if ( $to ) {
				$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name', get_bloginfo( 'name' ) ) : get_bloginfo( 'name' );
				$name    = trim( ( $row['company_name'] ?: ( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ) ) );
				$subject = 'Υπογραφή σύμβασης - ' . $company;
				$body    = sprintf( "Αγαπητέ/ή %s,\n\nΠαρακαλούμε υπογράψτε τη σύμβασή σας ηλεκτρονικά στον παρακάτω σύνδεσμο:\n%s\n\nΜε εκτίμηση,\n%s", $name ?: 'πελάτη', $url, $company );
				$emailed = wp_mail( $to, $subject, $body );
			}
		}

		return new WP_REST_Response( [ 'ok' => true, 'url' => $url, 'emailed' => $emailed ], 200 );
	}

	// Public: fetch minimal contract info for the signing page.
	public static function sign_get( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $req['token'] );
		$sg = ECRM_DB::table( 'signatures' );
		$ct = ECRM_DB::table( 'contracts' );
		$cu = ECRM_DB::table( 'customers' );
		$pr = ECRM_DB::table( 'providers' );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.signed_at, c.code, p.name AS provider, cu.first_name, cu.last_name, cu.company_name
			 FROM {$sg} s LEFT JOIN {$ct} c ON c.id=s.contract_id
			 LEFT JOIN {$cu} cu ON cu.id=c.customer_id LEFT JOIN {$pr} p ON p.id=c.provider_id
			 WHERE s.token=%s", $token
		), ARRAY_A );

		if ( ! $row ) { return new WP_REST_Response( [ 'ok' => false, 'error' => 'Άκυρος σύνδεσμος.' ], 404 ); }
		$name = $row['company_name'] ?: trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
		return new WP_REST_Response( [
			'ok' => true,
			'signed' => ! empty( $row['signed_at'] ),
			'code' => $row['code'], 'provider' => $row['provider'], 'customer' => $name,
		], 200 );
	}

	// Public: store the signature image.
	public static function sign_post( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $req['token'] );
		$sg = ECRM_DB::table( 'signatures' );
		$ct = ECRM_DB::table( 'contracts' );

		$rec = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sg} WHERE token=%s", $token ), ARRAY_A );
		if ( ! $rec ) { return new WP_REST_Response( [ 'ok' => false, 'error' => 'Άκυρος σύνδεσμος.' ], 404 ); }
		if ( ! empty( $rec['signed_at'] ) ) { return new WP_REST_Response( [ 'ok' => false, 'error' => 'Έχει ήδη υπογραφεί.' ], 409 ); }

		$p     = $req->get_json_params() ?: $req->get_params();
		$image = (string) ( $p['image'] ?? '' );
		$name  = sanitize_text_field( $p['name'] ?? '' );
		if ( strpos( $image, 'data:image/png;base64,' ) !== 0 || strlen( $image ) < 200 ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Λείπει η υπογραφή.' ], 400 );
		}
		if ( strlen( $image ) > 600000 ) { $image = substr( $image, 0, 600000 ); }

		$wpdb->update( $sg, [
			'signer_name' => $name,
			'image'       => $image,
			'signed_at'   => current_time( 'mysql', true ),
			'ip'          => substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 60 ),
		], [ 'id' => (int) $rec['id'] ] );

		self::transition( (int) $rec['contract_id'], 'signed', [
			'from'    => null,
			'message' => 'Υπεγράφη από πελάτη' . ( $name ? ' (' . $name . ')' : '' ),
			'extra'   => [ 'signed_at' => current_time( 'mysql' ), 'signed_ip' => substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 64 ) ],
			'inapp'   => false, // notify_signed() below sends the agent in-app notification.
		] );

		$cid = (int) $rec['contract_id'];

		// Persist the signature as a PNG file so it can be stamped onto the provider form.
		if ( class_exists( 'ECRM_Files' ) ) {
			$bin = base64_decode( substr( $image, strlen( 'data:image/png;base64,' ) ), true );
			if ( $bin !== false && $bin !== '' ) {
				$saved = ECRM_Files::put_bytes( $bin, 'png', 'image/png', 'signature.png' );
				if ( $saved ) {
					$fl = ECRM_DB::table( 'files' );
					$wpdb->delete( $fl, [ 'contract_id' => $cid, 'doc_kind' => 'signature' ] );
					$wpdb->insert( $fl, [
						'contract_id' => $cid,
						'doc_kind'    => 'signature',
						'filename'    => 'signature.png',
						'mime'        => 'image/png',
						'path'        => $saved['path'],
						'protected'   => 1,
					] );
				}
			}
		}

		// Regenerate the attached document so it now carries the signature.
		self::store_contract_pdf( $cid );

		// In-app notification to the contract owner (and upline).
		self::notify_signed( $cid, $name );

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}


	// ---------------------------------------------------------------------
	// Attach uploaded documents (ID, bill) to a contract -> WP media library
	// ---------------------------------------------------------------------
	public static function upload_files( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$id  = (int) $req['id'];
		$uid = get_current_user_id();
		$ct  = ECRM_DB::table( 'contracts' );
		$ids = ECRM_DB::visible_user_ids( $uid );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$own = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$ct} WHERE id=%d AND partner_user_id IN ($ph)", array_merge( [ $id ], $ids ) ) );
		if ( ! $own ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.' ], 404 );
		}

		$fp = $req->get_file_params();
		if ( empty( $fp['files'] ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν ανέβηκαν αρχεία.' ], 400 );
		}
		$incoming = self::normalize_files( $fp['files'] );
		$kinds    = (array) $req->get_param( 'kinds' );

		$allowed = [ 'image/jpeg', 'image/png', 'image/webp', 'application/pdf' ];
		$fl      = ECRM_DB::table( 'files' );
		$saved   = [];
		$i       = 0;

		foreach ( $incoming as $file ) {
			$stored = ECRM_Files::store( $file, $allowed );
			if ( ! $stored ) { $i++; continue; }

			$kind = sanitize_text_field( $kinds[ $i ] ?? 'other' );
			$wpdb->insert( $fl, [
				'contract_id' => $id,
				'attachment_id' => null,
				'doc_kind'    => $kind,
				'filename'    => $stored['filename'],
				'mime'        => $stored['mime'],
				'path'        => $stored['path'],
				'protected'   => 1,
			] );
			$file_id = (int) $wpdb->insert_id;
			$saved[] = [ 'id' => $file_id, 'filename' => $stored['filename'], 'url' => ECRM_Files::url( $file_id ), 'kind' => $kind ];
			$i++;
		}

		return new WP_REST_Response( [ 'ok' => true, 'saved' => count( $saved ), 'files' => $saved ], 200 );
	}


	// ---------------------------------------------------------------------
	// Customers: distinct customers in visible scope + duplicate check
	// ---------------------------------------------------------------------
	public static function customers_list( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$cu  = ECRM_DB::table( 'customers' );
		$ct  = ECRM_DB::table( 'contracts' );
		$uid = get_current_user_id();
		$scope = sanitize_text_field( (string) $req->get_param( 'scope' ) );
		$ids   = $scope === 'team' ? ECRM_DB::visible_user_ids( $uid ) : [ $uid ];
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$q   = trim( (string) $req->get_param( 'q' ) );
		$args = $ids;
		$where = "c.partner_user_id IN ($ph)";
		if ( $q !== '' ) {
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			$where .= " AND ( CONCAT_WS(' ', cu.first_name, cu.last_name, cu.company_name) LIKE %s OR cu.afm LIKE %s OR cu.phone LIKE %s )";
			array_push( $args, $like, $like, $like );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT cu.id, cu.first_name, cu.last_name, cu.company_name, cu.afm, cu.phone, cu.email,
				COUNT(c.id) AS contracts, MAX(c.updated_at) AS last_at
			 FROM {$cu} cu JOIN {$ct} c ON c.customer_id = cu.id
			 WHERE {$where}
			 GROUP BY cu.id ORDER BY last_at DESC LIMIT 500",
			$args
		), ARRAY_A );

		$out = array_map( function ( $r ) {
			$name = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			return [
				'id' => (int) $r['id'], 'name' => $name ?: '—', 'afm' => $r['afm'],
				'phone' => $r['phone'], 'email' => $r['email'],
				'contracts' => (int) $r['contracts'], 'last_at' => $r['last_at'],
			];
		}, $rows );

		return new WP_REST_Response( [ 'ok' => true, 'rows' => $out, 'count' => count( $out ) ], 200 );
	}

	public static function customers_check( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$uid = get_current_user_id();
		$ids = ECRM_DB::visible_user_ids( $uid );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$afm    = trim( (string) $req->get_param( 'afm' ) );
		$supply = trim( (string) $req->get_param( 'supply' ) );
		if ( $afm === '' && $supply === '' ) {
			return new WP_REST_Response( [ 'ok' => true, 'matches' => [] ], 200 );
		}

		$conds = []; $args = $ids;
		if ( $afm !== '' )    { $conds[] = 'cu.afm = %s';            $args[] = $afm; }
		if ( $supply !== '' ) { $conds[] = 'c.supply_number = %s';   $args[] = $supply; }
		$cond = '(' . implode( ' OR ', $conds ) . ')';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.code, c.status, c.supply_number, cu.afm, cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 WHERE c.partner_user_id IN ($ph) AND {$cond}
			 ORDER BY c.updated_at DESC LIMIT 10",
			$args
		), ARRAY_A );

		$matches = array_map( function ( $r ) {
			$name = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			return [ 'code' => $r['code'], 'status' => $r['status'], 'name' => $name ?: '—', 'afm' => $r['afm'], 'supply' => $r['supply_number'] ];
		}, $rows );

		return new WP_REST_Response( [ 'ok' => true, 'matches' => $matches ], 200 );
	}


	// Delete a contract (drafts only, within visible scope).
	public static function delete_contract( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$id  = (int) $req['id'];
		$uid = get_current_user_id();
		$ct  = ECRM_DB::table( 'contracts' );
		$ids = ECRM_DB::visible_user_ids( $uid );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$ct} WHERE id=%d AND partner_user_id IN ($ph)", array_merge( [ $id ], $ids ) ), ARRAY_A );
		if ( ! $row ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν βρέθηκε η αίτηση.' ], 404 );
		}
		// Documents first: the row is the only pointer to the file on disk.
		\EnergyCRM\Services::files()->purgeForContracts( [ $id ] );

		$wpdb->delete( ECRM_DB::table( 'events' ),        [ 'contract_id' => $id ] );
		$wpdb->delete( ECRM_DB::table( 'signatures' ),    [ 'contract_id' => $id ] );
		$wpdb->delete( ECRM_DB::table( 'notifications' ), [ 'contract_id' => $id ] );
		$wpdb->delete( $ct, [ 'id' => $id ] );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}


	// ---------------------------------------------------------------------
	// Notifications / follow-ups
	// ---------------------------------------------------------------------
	public static function notifications( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$uid   = get_current_user_id();
		$scope = sanitize_text_field( (string) $req->get_param( 'scope' ) );
		$ids   = $scope === 'team' ? ECRM_DB::visible_user_ids( $uid ) : [ $uid ];
		$data  = ECRM_Notifications::followups_for( $ids );
		$data['ok'] = true;
		$data['threshold'] = ECRM_Notifications::threshold_days();

		// Stored event notifications (e.g. customer signatures) for this user.
		$nt   = ECRM_DB::table( 'notifications' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, contract_id, type, title, body, read_at, created_at
			 FROM {$nt} WHERE user_id = %d ORDER BY id DESC LIMIT 30", $uid
		), ARRAY_A );
		$data['notifs'] = array_map( function ( $r ) {
			return [
				'id' => (int) $r['id'], 'contract_id' => (int) $r['contract_id'],
				'type' => $r['type'], 'title' => $r['title'], 'body' => $r['body'],
				'read' => ! empty( $r['read_at'] ), 'created_at' => $r['created_at'],
			];
		}, $rows ?: [] );
		$data['unread'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$nt} WHERE user_id = %d AND read_at IS NULL", $uid
		) );
		return new WP_REST_Response( $data, 200 );
	}

	/** POST /notifications/read — mark notifications read (all, or one by id). */
	public static function notifications_read( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$uid = get_current_user_id();
		$nt  = ECRM_DB::table( 'notifications' );
		$p   = $req->get_json_params() ?: $req->get_params();
		$now = current_time( 'mysql', true );
		if ( ! empty( $p['id'] ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$nt} SET read_at=%s WHERE user_id=%d AND id=%d AND read_at IS NULL", $now, $uid, (int) $p['id'] ) );
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE {$nt} SET read_at=%s WHERE user_id=%d AND read_at IS NULL", $now, $uid ) );
		}
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/** Insert a single in-app notification. */
	public static function notify( int $user_id, string $type, string $title, string $body = '', int $contract_id = 0 ): void {
		if ( $user_id <= 0 ) { return; }
		global $wpdb;
		$wpdb->insert( ECRM_DB::table( 'notifications' ), [
			'user_id'     => $user_id,
			'contract_id' => $contract_id ?: null,
			'type'        => $type,
			'title'       => $title,
			'body'        => $body,
		] );
	}

	/** Upline user ids (managers) of a user, via the ecrm_parent meta chain. */
	private static function upline_of( int $uid ): array {
		$out = []; $cur = $uid; $g = 0;
		while ( $cur && $g < 50 ) {
			$g++;
			$pid = (int) get_user_meta( $cur, 'ecrm_parent', true );
			if ( ! $pid || in_array( $pid, $out, true ) ) { break; }
			$out[] = $pid; $cur = $pid;
		}
		return $out;
	}

	/** Notify the contract owner (and upline) that the customer signed. */
	public static function notify_document( int $contract_id, string $doc_label = '' ): void {
		global $wpdb;
		$ct = ECRM_DB::table( 'contracts' );
		$cu = ECRM_DB::table( 'customers' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.code, c.partner_user_id, cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c LEFT JOIN {$cu} cu ON cu.id = c.customer_id WHERE c.id = %d", $contract_id
		), ARRAY_A );
		if ( ! $row ) { return; }
		$owner = (int) $row['partner_user_id'];
		$name  = $row['company_name'] ?: trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
		$title = 'Νέο δικαιολογητικό — ' . ( $row['code'] ?: '' );
		$body  = ( $name ?: 'Ο πελάτης' ) . ' ανέβασε' . ( $doc_label ? ': ' . $doc_label : ' έγγραφο' ) . '.';
		self::notify( $owner, 'document', $title, $body, $contract_id );
		foreach ( self::upline_of( $owner ) as $mid ) {
			self::notify( $mid, 'document', $title, $body, $contract_id );
		}
	}

	public static function notify_signed( int $contract_id, string $signer = '' ): void {
		global $wpdb;
		$ct = ECRM_DB::table( 'contracts' );
		$cu = ECRM_DB::table( 'customers' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.code, c.partner_user_id, cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c LEFT JOIN {$cu} cu ON cu.id = c.customer_id WHERE c.id = %d", $contract_id
		), ARRAY_A );
		if ( ! $row ) { return; }
		$owner = (int) $row['partner_user_id'];
		$name  = $row['company_name'] ?: trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
		$title = 'Υπεγράφη σύμβαση ' . ( $row['code'] ?: '' );
		$body  = ( $name ?: 'Ο πελάτης' ) . ' υπέγραψε ηλεκτρονικά' . ( $signer ? ' (' . $signer . ')' : '' ) . '.';
		self::notify( $owner, 'signed', $title, $body, $contract_id );
		foreach ( self::upline_of( $owner ) as $mid ) {
			self::notify( $mid, 'signed', $title, $body, $contract_id );
		}
	}


	// ---------------------------------------------------------------------
	// Renewals / expiries
	// ---------------------------------------------------------------------
	public static function renewals( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$pr  = ECRM_DB::table( 'providers' );
		$uid = get_current_user_id();
		$scope = sanitize_text_field( (string) $req->get_param( 'scope' ) );
		$ids   = $scope === 'team' ? ECRM_DB::visible_user_ids( $uid ) : [ $uid ];
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$window = (int) ( $req->get_param( 'days' ) ?: 60 );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.code, c.status, c.end_date, c.term_months,
				DATEDIFF(c.end_date, NOW()) AS days_left,
				p.name AS provider_name, p.logo_url AS provider_logo,
				cu.first_name, cu.last_name, cu.company_name, cu.phone
			 FROM {$ct} c
			 LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 LEFT JOIN {$pr} p  ON p.id  = c.provider_id
			 WHERE c.partner_user_id IN ($ph) AND c.end_date IS NOT NULL
			   AND c.status NOT IN ('cancelled','draft')
			   AND DATEDIFF(c.end_date, NOW()) <= %d
			 ORDER BY c.end_date ASC LIMIT 300",
			array_merge( $ids, [ $window ] )
		), ARRAY_A );

		$out = []; $soon = 0;
		foreach ( $rows as $r ) {
			$dl = (int) $r['days_left'];
			if ( $dl <= 30 ) { $soon++; }
			$name = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			$out[] = [
				'id' => (int) $r['id'], 'code' => $r['code'], 'status' => $r['status'],
				'customer' => $name ?: '—', 'phone' => $r['phone'],
				'provider_name' => $r['provider_name'], 'provider_logo' => $r['provider_logo'],
				'end_date' => $r['end_date'], 'days_left' => $dl,
				'expired' => $dl < 0,
			];
		}
		return new WP_REST_Response( [ 'ok' => true, 'rows' => $out, 'count' => count( $out ), 'soon' => $soon, 'window' => $window ], 200 );
	}

	public static function renew_contract( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$id  = (int) $req['id'];
		$uid = get_current_user_id();
		$ct  = ECRM_DB::table( 'contracts' );
		$ids = ECRM_DB::visible_user_ids( $uid );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$src = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ct} WHERE id=%d AND partner_user_id IN ($ph)", array_merge( [ $id ], $ids ) ), ARRAY_A );
		if ( ! $src ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν βρέθηκε.' ], 404 );
		}

		// New term starts when the old one ends (or today if already past).
		$start = ( $src['end_date'] && strtotime( $src['end_date'] ) > time() ) ? $src['end_date'] : gmdate( 'Y-m-d' );
		$term  = (int) $src['term_months'];
		$end   = $term > 0 ? gmdate( 'Y-m-d', strtotime( $start . ' +' . $term . ' months' ) ) : null;

		$new = [
			'partner_user_id' => $uid,
			'customer_id'     => $src['customer_id'],
			'provider_id'     => $src['provider_id'],
			'program_id'      => $src['program_id'],
			'energy_type'     => $src['energy_type'],
			'category'        => $src['category'],
			'price_type'      => $src['price_type'],
			'customer_type'   => $src['customer_type'],
			'activation_type' => 'renewal',
			'supply_number'   => $src['supply_number'],
			'meter_number'    => $src['meter_number'],
			'invoice_code'    => $src['invoice_code'],
			'status'          => 'draft',
			'notes'           => 'Ανανέωση από ' . $src['code'],
			'extra_json'      => $src['extra_json'],
			'start_date'      => $start,
			'term_months'     => $term ?: null,
			'end_date'        => $end,
		];
		$wpdb->insert( $ct, $new );
		$new_id = (int) $wpdb->insert_id;
		$wpdb->update( $ct, [ 'code' => sprintf( 'APP-%04d', $new_id ) ], [ 'id' => $new_id ] );
		$wpdb->insert( ECRM_DB::table( 'events' ), [
			'contract_id' => $new_id, 'user_id' => $uid, 'type' => 'created',
			'to_status' => 'draft', 'message' => 'Ανανέωση από ' . $src['code'],
		] );

		return new WP_REST_Response( [ 'ok' => true, 'contract_id' => $new_id ], 200 );
	}

	// ---------------------------------------------------------------------
	// Dashboard
	// ---------------------------------------------------------------------
	public static function dashboard(): WP_REST_Response {
		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$pr  = ECRM_DB::table( 'providers' );
		$ev  = ECRM_DB::table( 'events' );
		$uid = get_current_user_id();

		$today_start = gmdate( 'Y-m-d 00:00:00' );
		$month_start = gmdate( 'Y-m-01 00:00:00' );

		$today   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id=%d AND created_at>=%s", $uid, $today_start ) );
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id=%d AND status='pending'", $uid ) );
		$routed  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id=%d AND status='routed'", $uid ) );
		$month   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id=%d AND created_at>=%s", $uid, $month_start ) );

		// Per-provider this month
		$by_provider = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.name, COUNT(*) c FROM {$ct} ct LEFT JOIN {$pr} p ON p.id=ct.provider_id
			 WHERE ct.partner_user_id=%d AND ct.created_at>=%s GROUP BY ct.provider_id ORDER BY c DESC",
			$uid, $month_start
		), ARRAY_A );

		// Monthly counts (current year)
		$year     = (int) gmdate( 'Y' );
		$rows     = $wpdb->get_results( $wpdb->prepare(
			"SELECT MONTH(created_at) m, COUNT(*) c FROM {$ct} WHERE partner_user_id=%d AND YEAR(created_at)=%d GROUP BY MONTH(created_at)",
			$uid, $year
		), ARRAY_A );
		$monthly = array_fill( 1, 12, 0 );
		foreach ( $rows as $r ) { $monthly[ (int) $r['m'] ] = (int) $r['c']; }

		// Live feed (recent events)
		$feed = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.type, e.to_status, e.message, e.created_at, c.code
			 FROM {$ev} e LEFT JOIN {$ct} c ON c.id=e.contract_id
			 WHERE c.partner_user_id=%d ORDER BY e.created_at DESC LIMIT 8",
			$uid
		), ARRAY_A );

		// Gamification: tiers by monthly volume
		$tiers = [ 'Bronze' => 15, 'Silver' => 40, 'Gold' => 80, 'Platinum' => 150, 'Diamond' => 300 ];
		$current_tier = 'Χωρίς level';
		$next_tier    = 'Bronze';
		$next_at      = 15;
		foreach ( $tiers as $name => $th ) {
			if ( $month >= $th ) { $current_tier = $name; } else { $next_tier = $name; $next_at = $th; break; }
		}

		return new WP_REST_Response( [
			'user'        => wp_get_current_user()->display_name,
			'cards'       => [ 'today' => $today, 'pending' => $pending, 'routed' => $routed, 'month' => $month ],
			'by_provider' => $by_provider,
			'monthly'     => array_values( $monthly ),
			'feed'        => $feed,
			'level'       => [ 'current' => $current_tier, 'next' => $next_tier, 'next_at' => $next_at, 'remaining' => max( 0, $next_at - $month ) ],
		], 200 );
	}
}
