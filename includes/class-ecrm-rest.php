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

use EnergyCRM\Domain\Contract\ContractStatus;

class ECRM_REST {

	const NS = 'ecrm/v1';

	/**
	 * Public entry point for the extended-fields bag, used by
	 * EnergyCRM\Http\ContractSaveController. Moves under src/ with the rest of
	 * the contract-writing logic.
	 */
	public static function sanitize_extra_bag( $extra ): ?string {
		return self::sanitize_extra( $extra );
	}

	/** Record the "contract created" entry in the event log. */
	public static function log_creation( int $contract_id, int $user_id, string $status ): void {
		\EnergyCRM\Services::events()->record( $contract_id, $user_id, 'created', [
			'to_status' => $status,
			'message'   => 'Αποθήκευση αίτησης',
		] );
	}

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
	 * No routes remain here.
	 *
	 * Every endpoint now lives in a controller under src/Http, registered by
	 * EnergyCRM\Http\Router. The map below is kept as a finding aid — it is the
	 * only place that lists the whole HTTP surface next to its old home.
	 *
	 * What is left in this class is not REST at all: transition(), the
	 * notification helpers and store_contract_pdf() are the contract lifecycle,
	 * and they are the next thing to move.
	 */
	public static function routes(): void {
		// GET /providers -> EnergyCRM\Http\CatalogueController
		// POST /quote/pdf -> EnergyCRM\Http\QuoteController
		// GET /lookup/afm -> EnergyCRM\Http\VatLookupController
		// GET /search -> EnergyCRM\Http\CatalogueController
		// GET /team/live -> EnergyCRM\Http\TeamActivityController
		// /filters -> EnergyCRM\Http\SavedFiltersController
		// GET /file/{id} -> EnergyCRM\Http\DocumentsController
		// POST /extract -> EnergyCRM\Http\ExtractionController
		// GET /dashboard -> EnergyCRM\Http\DashboardController
		// GET/POST /contracts -> ContractsReadController / ContractSaveController

		// POST /contracts/bulk -> EnergyCRM\Http\ContractsBulkController

		// GET /contracts/duplicate -> EnergyCRM\Http\DuplicateCheckController

		// GET /contracts/{id} -> EnergyCRM\Http\ContractsReadController::show

		// POST /contracts/{id}/status -> EnergyCRM\Http\ContractStatusController

		// GET /contracts/{id}/pdf -> EnergyCRM\Http\ContractDocumentsController

		// GET /contracts/{id}/provider-form -> ContractDocumentsController

		// POST /contracts/{id}/files -> EnergyCRM\Http\DocumentsController

		// DELETE /contracts/{id} -> EnergyCRM\Http\ContractStatusController

		// GET /contracts/export -> ContractDocumentsController

		// GET/POST /team -> EnergyCRM\Http\TeamController

		// POST /team/{id} -> EnergyCRM\Http\TeamController

		// GET /network -> EnergyCRM\Http\TeamController

		// POST /import/parse -> EnergyCRM\Http\ImportController

		// POST /import/apply -> EnergyCRM\Http\ImportController

		// GET /commissions -> EnergyCRM\Http\CommissionsController

		// GET /analytics -> EnergyCRM\Http\AnalyticsController

		// /customers και /customers/check -> EnergyCRM\Http\CustomersController

		// /notifications, /notifications/read και /renewals μετακινήθηκαν σε
		// EnergyCRM\Http\NotificationsController και RenewalsController.
		// Δεν επανεγγράφονται εδώ: μια διαδρομή σε δύο σημεία σημαίνει ότι
		// κερδίζει σιωπηλά όποια δηλωθεί τελευταία.

		// POST /contracts/{id}/renew -> EnergyCRM\Http\RenewalsController::renew

		// POST /contracts/{id}/sign-link -> EnergyCRM\Http\SignLinkController

		// GET/POST /sign/{token} -> EnergyCRM\Http\SigningController
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

	public static function can_manage_team(): bool {
		return current_user_can( 'ecrm_manage_team' ) || current_user_can( 'manage_options' );
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
			"SELECT c.*, p.name AS provider_name, g.name AS program_name, g.code AS program_code,
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
		$bytes        = '';
		$extra_sheets = [];
		if ( class_exists( 'ECRM_FormFill' ) ) {
			try {
				ob_start();
				$sheets = ECRM_FormFill::fill_all( $row );
				ob_end_clean();
				foreach ( $sheets as $sheet ) {
					if ( empty( $sheet['ok'] ) || empty( $sheet['bytes'] ) ) { continue; }
					// The first sheet is the contract; the rest ride with it.
					if ( $bytes === '' ) {
						$bytes = $sheet['bytes'];
						continue;
					}
					$extra_sheets[] = $sheet;
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
		$code = $row['code'] ?: ( 'symvasi-' . $id );

		self::forget_generated_documents( $id );

		if ( ! self::keep_generated_document( $id, 'contract', $code . '.pdf', $bytes ) ) {
			return false;
		}

		// Mobile applications travel as a set: the contract plus whatever the
		// customer's choices added. Those extra sheets are stored beside it so
		// the agent prints the whole application, not just its first form.
		foreach ( $extra_sheets as $sheet ) {
			$kind = substr( 'form_' . $sheet['key'], 0, 24 );
			self::keep_generated_document( $id, $kind, $code . '-' . $sheet['key'] . '.pdf', $sheet['bytes'] );
		}

		return true;
	}

	/**
	 * Remove the documents a previous save generated, bytes included.
	 *
	 * Only ever the generated ones: anything the customer or the agent
	 * uploaded has its own doc_kind and must survive a re-save untouched.
	 */
	private static function forget_generated_documents( int $contract_id ): void {
		global $wpdb;
		$fl = ECRM_DB::table( 'files' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, path FROM {$fl}
			 WHERE contract_id = %d AND ( doc_kind = 'contract' OR doc_kind LIKE 'form\\_%' )",
			$contract_id
		), ARRAY_A );

		foreach ( (array) $rows as $r ) {
			// Deleting the row without the file is how the orphans this plugin
			// had to sweep up were made in the first place.
			$path = (string) ( $r['path'] ?? '' );
			if ( $path !== '' && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			$wpdb->delete( $fl, [ 'id' => (int) $r['id'] ] );
		}
	}

	/** Store one generated PDF against the contract. */
	private static function keep_generated_document( int $contract_id, string $kind, string $filename, string $bytes ): bool {
		global $wpdb;

		$saved = ECRM_Files::put_bytes( $bytes, 'pdf', 'application/pdf', $filename );
		if ( ! $saved ) { return false; }

		$wpdb->insert( ECRM_DB::table( 'files' ), [
			'contract_id' => $contract_id,
			'doc_kind'    => $kind,
			'filename'    => $saved['filename'],
			'mime'        => $saved['mime'],
			'path'        => $saved['path'],
			'protected'   => 1,
		] );

		return true;
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
}
