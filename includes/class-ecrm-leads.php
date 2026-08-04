<?php
/**
 * Leads / pre-contract funnel.
 *
 * A lightweight pipeline that sits before a contract: capture an interested
 * prospect, schedule callbacks, move them through stages, and convert a won
 * lead into a draft contract (prefilled with the contact details).
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Leads {

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		$auth = [ 'ECRM_REST', 'can_use' ];
		register_rest_route( ECRM_REST::NS, '/leads', [
			[ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_leads' ], 'permission_callback' => $auth ],
			[ 'methods' => 'POST', 'callback' => [ __CLASS__, 'save_lead' ], 'permission_callback' => $auth ],
		] );
		register_rest_route( ECRM_REST::NS, '/leads/(?P<id>\d+)', [
			[ 'methods' => 'POST',   'callback' => [ __CLASS__, 'save_lead' ],   'permission_callback' => $auth ],
			[ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_lead' ], 'permission_callback' => $auth ],
		] );
		register_rest_route( ECRM_REST::NS, '/leads/(?P<id>\d+)/convert', [
			'methods' => 'POST', 'callback' => [ __CLASS__, 'convert_lead' ], 'permission_callback' => $auth,
		] );
	}

	public static function stages(): array {
		return [
			'new'       => 'Νέο',
			'contacted' => 'Επικοινωνία',
			'callback'  => 'Επανάκληση',
			'qualified' => 'Έτοιμο',
			'won'       => 'Κερδισμένο',
			'lost'      => 'Χαμένο',
		];
	}

	public static function sources(): array {
		return [ 'phone' => 'Τηλέφωνο', 'chatbot' => 'Chatbot', 'referral' => 'Σύσταση', 'walk_in' => 'Επίσκεψη', 'social' => 'Social', 'other' => 'Άλλο' ];
	}

	/** User ids in scope for the current user. */
	private static function scope_ids(): array {
		$uid = get_current_user_id();
		return ECRM_REST::can_manage_team() ? ECRM_DB::visible_user_ids( $uid ) : [ $uid ];
	}

	public static function list_leads( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$t   = ECRM_DB::table( 'leads' );
		$ids = self::scope_ids();
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$stage = sanitize_text_field( (string) $req->get_param( 'stage' ) );
		$q     = trim( (string) $req->get_param( 'q' ) );

		$where  = [ "partner_user_id IN ($in)" ];
		$params = $ids;
		if ( $stage && array_key_exists( $stage, self::stages() ) ) { $where[] = 'stage = %s'; $params[] = $stage; }
		if ( $q !== '' ) {
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '( name LIKE %s OR phone LIKE %s OR email LIKE %s OR interest LIKE %s )';
			array_push( $params, $like, $like, $like, $like );
		}
		$sql  = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY (callback_at IS NULL), callback_at ASC, updated_at DESC LIMIT 500';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		// Stage counts (within scope, ignoring stage filter).
		$counts = [];
		$cr = $wpdb->get_results( $wpdb->prepare( "SELECT stage, COUNT(*) c FROM {$t} WHERE partner_user_id IN ($in) GROUP BY stage", $ids ), ARRAY_A );
		foreach ( (array) $cr as $r ) { $counts[ $r['stage'] ] = (int) $r['c']; }

		$stages  = self::stages();
		$sources = self::sources();
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'id'          => (int) $r['id'],
				'name'        => $r['name'],
				'phone'       => $r['phone'],
				'email'       => $r['email'],
				'source'      => $r['source'],
				'source_label'=> $sources[ $r['source'] ] ?? '',
				'energy_type' => $r['energy_type'],
				'stage'       => $r['stage'],
				'stage_label' => $stages[ $r['stage'] ] ?? $r['stage'],
				'callback_at' => $r['callback_at'],
				'interest'    => $r['interest'],
				'notes'       => $r['notes'],
				'contract_id' => $r['contract_id'] ? (int) $r['contract_id'] : 0,
				'lost_reason' => $r['lost_reason'],
				'updated_at'  => $r['updated_at'],
			];
		}
		return new WP_REST_Response( [ 'ok' => true, 'leads' => $out, 'counts' => $counts, 'stages' => $stages, 'sources' => $sources ], 200 );
	}

	public static function save_lead( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$t   = ECRM_DB::table( 'leads' );
		$p   = $req->get_json_params() ?: $req->get_params();
		$id  = (int) $req['id'];
		$uid = get_current_user_id();

		// Ownership check on update.
		if ( $id ) {
			$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT partner_user_id FROM {$t} WHERE id = %d", $id ) );
			if ( ! $owner || ! in_array( $owner, self::scope_ids(), true ) ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν επιτρέπεται.' ], 403 );
			}
		}

		$stage  = array_key_exists( ( $p['stage'] ?? '' ), self::stages() ) ? $p['stage'] : null;
		$source = array_key_exists( ( $p['source'] ?? '' ), self::sources() ) ? $p['source'] : null;
		$energy = in_array( ( $p['energy_type'] ?? '' ), [ 'power', 'gas', 'mobile' ], true ) ? $p['energy_type'] : null;
		$cb     = trim( (string) ( $p['callback_at'] ?? '' ) );
		$cb     = $cb !== '' ? gmdate( 'Y-m-d H:i:s', strtotime( $cb ) ) : null;

		$data = [
			'name'        => sanitize_text_field( (string) ( $p['name'] ?? '' ) ),
			'phone'       => sanitize_text_field( (string) ( $p['phone'] ?? '' ) ),
			'email'       => sanitize_email( (string) ( $p['email'] ?? '' ) ),
			'source'      => $source,
			'energy_type' => $energy,
			'interest'    => sanitize_text_field( (string) ( $p['interest'] ?? '' ) ),
			'notes'       => sanitize_textarea_field( (string) ( $p['notes'] ?? '' ) ),
			'callback_at' => $cb,
			'lost_reason' => isset( $p['lost_reason'] ) ? sanitize_text_field( (string) $p['lost_reason'] ) : null,
			'updated_at'  => current_time( 'mysql' ),
		];
		if ( $stage ) { $data['stage'] = $stage; }

		if ( $id ) {
			$wpdb->update( $t, $data, [ 'id' => $id ] );
		} else {
			if ( $data['name'] === '' ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Το όνομα είναι υποχρεωτικό.' ], 400 );
			}
			$data['partner_user_id'] = $uid;
			if ( empty( $data['stage'] ) ) { $data['stage'] = 'new'; }
			$wpdb->insert( $t, $data );
			$id = (int) $wpdb->insert_id;
		}
		return new WP_REST_Response( [ 'ok' => true, 'id' => $id ], 200 );
	}

	public static function delete_lead( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$t  = ECRM_DB::table( 'leads' );
		$id = (int) $req['id'];
		$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT partner_user_id FROM {$t} WHERE id = %d", $id ) );
		if ( ! $owner || ! in_array( $owner, self::scope_ids(), true ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν επιτρέπεται.' ], 403 );
		}
		$wpdb->delete( $t, [ 'id' => $id ] );
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/** Convert a lead into a draft contract prefilled with its contact details. */
	public static function convert_lead( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$t   = ECRM_DB::table( 'leads' );
		$id  = (int) $req['id'];
		$uid = get_current_user_id();
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $lead || ! in_array( (int) $lead['partner_user_id'], self::scope_ids(), true ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν βρέθηκε.' ], 404 );
		}
		if ( ! empty( $lead['contract_id'] ) ) {
			return new WP_REST_Response( [ 'ok' => true, 'contract_id' => (int) $lead['contract_id'], 'existing' => true ], 200 );
		}

		// Split the contact name into first/last (best-effort).
		$name  = trim( (string) $lead['name'] );
		$parts = preg_split( '/\s+/', $name, 2 );
		$first = $parts[0] ?? '';
		$last  = $parts[1] ?? '';

		$customers_t = ECRM_DB::table( 'customers' );
		$wpdb->insert( $customers_t, [
			'customer_type' => 'individual',
			'first_name'    => $first,
			'last_name'     => $last,
			'mobile'        => $lead['phone'],
			'email'         => $lead['email'],
		] );
		$customer_id = (int) $wpdb->insert_id;

		$contracts_t = ECRM_DB::table( 'contracts' );
		$wpdb->insert( $contracts_t, [
			'partner_user_id' => $uid,
			'customer_id'     => $customer_id ?: null,
			'energy_type'     => in_array( $lead['energy_type'], [ 'power', 'gas', 'mobile' ], true ) ? $lead['energy_type'] : 'power',
			'category'        => 'home',
			'customer_type'   => 'individual',
			'status'          => 'draft',
			'notes'           => $lead['interest'] ? ( 'Από lead: ' . $lead['interest'] ) : null,
		] );
		$contract_id = (int) $wpdb->insert_id;
		$wpdb->update( $contracts_t, [ 'code' => sprintf( 'APP-%04d', $contract_id ) ], [ 'id' => $contract_id ] );

		$wpdb->update( $t, [ 'stage' => 'won', 'contract_id' => $contract_id, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

		return new WP_REST_Response( [ 'ok' => true, 'contract_id' => $contract_id ], 200 );
	}
}
