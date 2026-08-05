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
		// Οι διαδρομές /leads ζουν στο EnergyCRM\Http\LeadsController.
		// Εδώ μένουν τα λεξιλόγια stages() και sources().
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
}
