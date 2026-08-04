<?php
/**
 * "Λίτσα" — the in-app AI assistant.
 *
 * A REST endpoint that relays a short conversation to the Claude Messages API
 * with a CRM-aware system prompt and the current user's live numbers, so it
 * can both explain how to use the app and answer "how many pending do I have".
 * The API key never leaves the server.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Assistant {

	public static function init(): void {
		add_action( 'rest_api_init', function () {
			register_rest_route( ECRM_REST::NS, '/assistant', [
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'chat' ],
				'permission_callback' => [ 'ECRM_REST', 'can_use' ],
			] );
		} );
	}

	public static function chat( WP_REST_Request $req ): WP_REST_Response {
		$key = ECRM_Extractor::api_key();
		if ( empty( $key ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν έχει οριστεί Claude API key. Ρυθμίσεις → Energy CRM.' ], 400 );
		}

		$p   = $req->get_json_params() ?: $req->get_params();
		$msgs = is_array( $p['messages'] ?? null ) ? $p['messages'] : [];

		// Sanitise + keep only the last 20 turns.
		$clean = [];
		foreach ( $msgs as $m ) {
			$role = ( ( $m['role'] ?? '' ) === 'assistant' ) ? 'assistant' : 'user';
			$text = trim( (string) ( $m['content'] ?? '' ) );
			if ( $text !== '' ) {
				$clean[] = [ 'role' => $role, 'content' => mb_substr( $text, 0, 4000 ) ];
			}
		}
		$clean = array_slice( $clean, -20 );
		if ( ! $clean ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Κενό μήνυμα.' ], 400 );
		}
		// The API requires the first message to be from the user.
		if ( $clean[0]['role'] !== 'user' ) {
			array_shift( $clean );
		}
		if ( ! $clean ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Κενό μήνυμα.' ], 400 );
		}

		// Pull relevant Knowledge Base content for the latest user question so the
		// bubble can answer provider / documents / guarantee questions from it.
		$kb_context = '';
		if ( class_exists( 'ECRM_KB' ) ) {
			for ( $i = count( $clean ) - 1; $i >= 0; $i-- ) {
				if ( $clean[ $i ]['role'] === 'user' ) {
					$kb_context = ECRM_KB::context_for( $clean[ $i ]['content'] );
					break;
				}
			}
		}

		$system = self::system_prompt();
		if ( $kb_context !== '' ) {
			$system .= "\n\n=== ΒΑΣΗ ΓΝΩΣΗΣ (δικαιολογητικά / εγγυήσεις / χρεώσεις ανά πάροχο) ===\n"
				. "Για ερωτήσεις σχετικά με παρόχους, δικαιολογητικά, εγγυήσεις ή χρεώσεις, απάντησε ΑΠΟΚΛΕΙΣΤΙΚΑ από το παρακάτω περιεχόμενο. "
				. "Αν η συγκεκριμένη πληροφορία δεν υπάρχει εδώ, πες το ξεκάθαρα και μην εφευρίσκεις ποσά/κανόνες. Ανέφερε πάντα τον πάροχο και την περίπτωση.\n\n"
				. $kb_context;
		}

		$body = [
			'model'      => ECRM_Extractor::model(),
			'max_tokens' => 1024,
			'system'     => $system,
			'messages'   => $clean,
		];

		$resp = wp_remote_post( ECRM_Extractor::API_URL, [
			'timeout' => 45,
			'headers' => [
				'content-type'      => 'application/json',
				'x-api-key'         => $key,
				'anthropic-version' => ECRM_Extractor::API_VER,
			],
			'body'    => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $resp ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Σφάλμα σύνδεσης: ' . $resp->get_error_message() ], 502 );
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code !== 200 ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => $json['error']['message'] ?? ( 'HTTP ' . $code ) ], 502 );
		}

		$reply = '';
		foreach ( ( $json['content'] ?? [] ) as $blk ) {
			if ( ( $blk['type'] ?? '' ) === 'text' ) {
				$reply .= $blk['text'];
			}
		}

		return new WP_REST_Response( [ 'ok' => true, 'reply' => trim( $reply ) ], 200 );
	}

	/** CRM-aware persona + live user numbers. */
	private static function system_prompt(): string {
		$u     = wp_get_current_user();
		$stats = self::live_stats();

		return "Είσαι η «Λίτσα», η βοηθός μέσα στην πλατφόρμα Energy CRM για ενεργειακούς συνεργάτες (πωλήσεις συμβολαίων ρεύματος/αερίου). "
			. "Μιλάς ελληνικά, ζεστά και σύντομα, σαν έμπειρη συνάδελφος. Βοηθάς τον χρήστη να χρησιμοποιεί την εφαρμογή και απαντάς ερωτήσεις για τα νούμερά του.\n\n"
			. "Τι μπορεί να κάνει ο χρήστης στην εφαρμογή:\n"
			. "- «Νέα Σύμβαση»: διαλέγει πάροχο/πρόγραμμα/τύπο και στην ενότητα «AI Εξαγωγή» σύρει ταυτότητα + λογαριασμό παρόχου ώστε να συμπληρωθούν αυτόματα τα στοιχεία πελάτη. Μετά «Οριστικοποίηση».\n"
			. "- «Συμβάσεις»: λίστα με φίλτρα ανά κατάσταση, αναζήτηση, διακόπτη «Δικά μου/Ομάδας», και «Export Excel». Κλικ σε γραμμή ανοίγει την καρτέλα.\n"
			. "- Καρτέλα σύμβασης: αλλαγή κατάστασης, ιστορικό, και «Δημιουργία PDF» αίτησης.\n"
			. "- «Η ομάδα μου»: προσθήκη πωλητών/καταχωρητών. «Το δίκτυό μου»: οι συνεργάτες κάτω από τον χρήστη.\n"
			. "- «Εισαγωγή Excel»: ανεβάζει το Excel του παρόχου για μαζική ενημέρωση καταστάσεων βάσει αριθμού παροχής.\n"
			. "- «Βάση Γνώσης»: δικαιολογητικά, εγγυήσεις και χρεώσεις ανά πάροχο. Μπορείς να απαντάς τέτοιες ερωτήσεις αν σου δοθεί το σχετικό περιεχόμενο παρακάτω.\n\n"
			. "Τρέχοντα νούμερα του χρήστη ({$u->display_name}) — χρησιμοποίησέ τα μόνο αν ρωτηθούν:\n"
			. "- Σήμερα: {$stats['today']} · Εκκρεμότητες: {$stats['pending']} · Δρομολογήθηκαν: {$stats['routed']} · Αυτόν τον μήνα: {$stats['month']}\n\n"
			. "Κανόνες: Μην εφευρίσκεις νούμερα ή δυνατότητες. Αν δεν ξέρεις κάτι ή ζητείται ενέργεια που δεν υπάρχει, πες το ειλικρινά. Κράτα τις απαντήσεις σύντομες και πρακτικές.";
	}

	private static function live_stats(): array {
		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$uid = get_current_user_id();
		$today_start = gmdate( 'Y-m-d 00:00:00' );
		$month_start = gmdate( 'Y-m-01 00:00:00' );
		return [
			'today'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id=%d AND created_at>=%s", $uid, $today_start ) ),
			'pending' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id=%d AND status='pending'", $uid ) ),
			'routed'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id=%d AND status='routed'", $uid ) ),
			'month'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id=%d AND created_at>=%s", $uid, $month_start ) ),
		];
	}
}
