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

class ECRM_REST {

	const NS = 'ecrm/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		// Make sure our REST responses are never cached (browser/proxy) so the
		// UI always reflects the latest data right after a save.
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'no_cache_headers' ], 10, 3 );

		// The auto-processing cron moved to AutoProcess, registered by Plugin.
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
	 * What is left in this class is not REST either: the notification helpers,
	 * and can_manage_team(). The status transition, the auto-processing cron and
	 * the document build all left in step 10 — see
	 * EnergyCRM\Domain\Contract\ContractLifecycle, AutoProcess and
	 * EnergyCRM\Infrastructure\ContractDocuments. The notifications are next,
	 * and they are a merge into the existing ECRM_Notifications rather than a
	 * move; after that this file is deleted.
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

	public static function can_manage_team(): bool {
		return current_user_can( 'ecrm_manage_team' ) || current_user_can( 'manage_options' );
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
