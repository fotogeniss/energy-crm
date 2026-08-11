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
	 * What is left in this class is not REST either, and is down to one thing:
	 * NS. The status transition, the auto-processing cron, the document build
	 * and the notifications all left in step 10 — see
	 * EnergyCRM\Domain\Contract\ContractLifecycle, AutoProcess,
	 * EnergyCRM\Infrastructure\ContractDocuments and
	 * EnergyCRM\Infrastructure\ContractNotices.
	 *
	 * The notifications went into classes of their own rather than merging into
	 * ECRM_Notifications: that one sends email, these write the rows behind the
	 * bell. notify() is now NotificationRepository::add().
	 *
	 * can_manage_team() did not move anywhere: it was dead. Its only caller was
	 * ECRM_Leads::scope_ids(), itself private and called by nothing since the
	 * /leads routes became LeadsController. The live answer to the same question
	 * is Access\WordPressScopeResolver, which asks the same two capabilities and
	 * returns a UserScope instead of a bare bool.
	 *
	 * NS outlives the rest of this file. Six register_rest_route calls across
	 * ECRM_Tracking, ECRM_KB and ECRM_Assistant still read it, plus four
	 * rest_url callers — all ten have to point at Router::NAMESPACE (same value)
	 * before this file can be deleted. See HANDOVER.md §6β.3.
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

}
