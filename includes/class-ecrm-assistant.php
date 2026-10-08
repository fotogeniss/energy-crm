<?php
/**
 * AlfrAId (πρώην «Λίτσα») — the in-app AI assistant.
 *
 * A REST endpoint that relays a short conversation to the Claude Messages API
 * with a CRM-aware system prompt and the current user's live numbers, so it
 * can both explain how to use the app and answer "how many pending do I have".
 * The API key never leaves the server.
 *
 * Από 05/10/2026 διαβάζει και τις αιτήσεις που βλέπει ο χρήστης, χωρίς κανένα
 * στοιχείο πελάτη: δες `EnergyCRM\Infrastructure\AssistantContractContext`.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnergyCRM\Http\Guards;
use EnergyCRM\Persistence\AssistantHistoryRepository;

class ECRM_Assistant {

	public static function init(): void {
		add_action( 'rest_api_init', function () {
			// See the note in ECRM_KB::routes(): ECRM_REST::can_use() no longer
			// exists, so this route answered 500 rather than checking anything.
			register_rest_route( \EnergyCRM\Http\Router::NAMESPACE, '/assistant', [
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'chat' ],
				'permission_callback' => Guards::crmUser(),
			] );
		} );
	}

	public static function chat( WP_REST_Request $req ): WP_REST_Response {
		// Έλλειψη rate limit εδώ, εντοπίστηκε στην εσωτερική επισκόπηση 30/08:
		// το /duplicate, το /assistant/kb (kb_ask) και το track_upload έχουν
		// όλα προϋπολογισμό, ενώ αυτό το endpoint -- που καλεί πραγματικό Claude
		// API, με πραγματικό κόστος ανά αίτημα -- δεν είχε κανέναν. Ίδιο όριο
		// με το kb_ask, γιατί είναι το ίδιο είδος κλήσης (LLM, ανά χρήστη).
		if ( class_exists( 'ECRM_RateLimit' ) && ! ECRM_RateLimit::allow( 'assistant_chat', 20, 300 ) ) {
			return ECRM_RateLimit::too_many();
		}

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

		// Η νεότερη γραμμή του χρήστη -- πάντα η τελευταία, γιατί το send() του
		// ecrm-litsa.js την προσθέτει πριν καλέσει αυτό εδώ. Μόνο αυτή είναι
		// πραγματικά καινούρια· ό,τι προηγείται είτε ήρθε ήδη αποθηκευμένο από
		// το /assistant/history είτε γράφτηκε σε προηγούμενη κλήση (build queue
		// 14 -- βλ. AssistantHistoryRepository, θέση του παλιού localStorage).
		$history = new AssistantHistoryRepository();
		$latest  = end( $clean );
		$history->append( get_current_user_id(), 'user', $latest['content'] );

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

		$dynamic = self::stats_section();

		// 05/10/2026: ο AlfrAId διαβάζει τις αιτήσεις που βλέπει ο χρήστης,
		// χωρίς κανένα στοιχείο πελάτη (βλ. AssistantContractContext).
		try {
			$contracts = ( new \EnergyCRM\Infrastructure\AssistantContractContext(
				\EnergyCRM\Services::contractQueries(),
				\EnergyCRM\Services::events()
			) )->forMessage( \EnergyCRM\Services::scopeResolver()->forCurrentUser(), (string) $latest['content'] );
		} catch ( \Throwable $e ) {
			$contracts = '';
		}
		if ( $contracts !== '' ) {
			$dynamic .= "\n\n=== ΑΙΤΗΣΕΙΣ ΤΟΥ ΧΡΗΣΤΗ (χωρίς στοιχεία πελατών) ===\n" . $contracts;
		}

		if ( $kb_context !== '' ) {
			$dynamic .= "\n\n=== ΒΑΣΗ ΓΝΩΣΗΣ (δικαιολογητικά / εγγυήσεις / χρεώσεις ανά πάροχο) ===\n"
				. "Για ερωτήσεις σχετικά με παρόχους, δικαιολογητικά, εγγυήσεις ή χρεώσεις, απάντησε ΑΠΟΚΛΕΙΣΤΙΚΑ από το παρακάτω περιεχόμενο. "
				. "Αν η συγκεκριμένη πληροφορία δεν υπάρχει εδώ, πες το ξεκάθαρα και μην εφευρίσκεις ποσά/κανόνες. Ανέφερε πάντα τον πάροχο και την περίπτωση.\n\n"
				. $kb_context;
		}

		$body = [
			// Το chat τρέχει σε μικρότερο, φθηνότερο μοντέλο από την εξαγωγή
			// εγγράφων (βλ. ECRM_Extractor::chat_model()).
			'model'      => ECRM_Extractor::chat_model(),
			// 1024 έκοβε τις πιο μεγάλες απαντήσεις στη μέση της πρότασης.
			'max_tokens' => 2048,
			// Δύο κομμάτια: οι σταθερές οδηγίες με cache_control (διαβάζονται
			// φθηνότερα στα επόμενα μηνύματα), και μετά ό,τι αλλάζει. Αν οι
			// οδηγίες είναι κάτω από το ελάχιστο του μοντέλου, το API απλώς
			// αγνοεί το cache_control.
			'system'     => [
				[ 'type' => 'text', 'text' => self::system_prompt(), 'cache_control' => [ 'type' => 'ephemeral' ] ],
				[ 'type' => 'text', 'text' => $dynamic ],
			],
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

		$reply = trim( $reply );

		if ( $reply !== '' ) {
			$history->append( get_current_user_id(), 'assistant', $reply );
		}

		return new WP_REST_Response( [ 'ok' => true, 'reply' => $reply ], 200 );
	}

	/** CRM-aware persona + live user numbers. */
	/**
	 * Το σταθερό κομμάτι των οδηγιών: ίδιο για όλους και σε κάθε μήνυμα, άρα
	 * το μόνο που αξίζει cache (βλ. chat()). Ό,τι αλλάζει ανά χρήστη ή ανά
	 * στιγμή (νούμερα, αιτήσεις, Βάση Γνώσης) πάει σε ξεχωριστό κομμάτι ΜΕΤΑ.
	 */
	private static function system_prompt(): string {
		return "Είσαι ο «AlfrAId», ο βοηθός μέσα στην πλατφόρμα Energy CRM για ενεργειακούς συνεργάτες (πωλήσεις συμβολαίων ρεύματος/αερίου). "
			. "Μιλάς ελληνικά, ζεστά και σύντομα, σαν έμπειρος συνάδελφος. Είσαι τεχνητή νοημοσύνη και το λες ξεκάθαρα αν σε ρωτήσουν, δεν προσποιείσαι άνθρωπο. Βοηθάς τον χρήστη να χρησιμοποιεί την εφαρμογή και απαντάς ερωτήσεις για τα νούμερά του.\n\n"
			. "Τι μπορεί να κάνει ο χρήστης στην εφαρμογή:\n"
			. "- «Νέα Σύμβαση»: διαλέγει πάροχο/πρόγραμμα/τύπο και στην ενότητα «AI Εξαγωγή» σύρει ταυτότητα + λογαριασμό παρόχου ώστε να συμπληρωθούν αυτόματα τα στοιχεία πελάτη. Στο τέλος πατάει το κουμπί «Οριστικοποίηση» της φόρμας, που υποβάλλει την αίτηση (προσοχή: είναι άλλο πράγμα από την ΚΑΤΑΣΤΑΣΗ «Οριστικοποίηση», βλ. παρακάτω).\n"
			. "- «Συμβάσεις»: λίστα με φίλτρα ανά κατάσταση, αναζήτηση, διακόπτη «Δικά μου/Ομάδας», και «Export Excel». Κλικ σε γραμμή ανοίγει την καρτέλα.\n"
			. "- Καρτέλα σύμβασης: αλλαγή κατάστασης, ιστορικό, και «Δημιουργία PDF» αίτησης.\n"
			. "- «Η ομάδα μου»: προσθήκη πωλητών/καταχωρητών. «Το δίκτυό μου»: οι συνεργάτες κάτω από τον χρήστη.\n"
			. "- «Εισαγωγή Excel»: ανεβάζει το Excel του παρόχου για μαζική ενημέρωση καταστάσεων βάσει αριθμού παροχής.\n"
			. "- «Βάση Γνώσης»: δικαιολογητικά, εγγυήσεις και χρεώσεις ανά πάροχο. Μπορείς να απαντάς τέτοιες ερωτήσεις αν σου δοθεί το σχετικό περιεχόμενο παρακάτω.\n\n"
			. "Καταστάσεις αίτησης ρεύματος (το αέριο ίδιες, χωρίς τα ΘΑΛΗΣ): Presale (υποβλήθηκε, λείπουν δικαιολογητικά), Καταχώρηση (την καταχωρεί το back office), Αναμονή υπογραφής (στάλθηκε στον πελάτη), Προς οριστικοποίηση (ο πελάτης υπέγραψε), Οριστικοποίηση (τελείωσε από τη δική μας πλευρά, περιμένουμε τον πάροχο), Επιβεβαίωση ΘΑΛΗΣ / Απόρριψη ΘΑΛΗΣ (η απάντηση του ΘΑΛΗΣ), Εκκρεμότητα (κάτι λείπει ή θέλει διευκρίνιση), Επανέλεγχος (η αίτηση ξαναελέγχεται), Ενεργός.\n"
			. "Καταστάσεις κινητής Orizon: Presale, Καταχώρηση, Αναμονή υπογραφής, Ολοκλήρωση υπογραφής, Αναμονή παράδοσης SIM, Παράδοση SIM, Οριστικοποίηση, Εκκρεμότητα, Οφειλή (ο πελάτης έχει οφειλή), Ενεργός, For review (σε έλεγχο), MP reject (απορρίφθηκε η φορητότητα).\n"
			. "Και στις δύο: Πρόχειρο (δεν έχει υποβληθεί) και Ακυρώθηκε (τέλος). Η μόνη πληρωτέα κατάσταση είναι η Ενεργός· εκεί κλειδώνει η προμήθεια.\n\n"
			. "ΑΙΤΗΣΕΙΣ: στο τέλος αυτών των οδηγιών, στην ενότητα «ΑΙΤΗΣΕΙΣ ΤΟΥ ΧΡΗΣΤΗ», έχεις τις αιτήσεις που βλέπει ο χρήστης: κωδικό, είδος, πάροχο, πρόγραμμα, κατάσταση, μέρες χωρίς αλλαγή, υπογραφή, ποια δικαιολογητικά λείπουν και ιστορικό καταστάσεων. Απάντα με βάση αυτά, συγκεκριμένα (π.χ. «η ORIZON-7166 είναι 3 μέρες σε Οριστικοποίηση και δεν της λείπει τίποτα, περιμένουμε τον πάροχο»). "
			. "Αν ρωτήσει για αίτηση που δεν είναι εκεί, πες ότι δεν τη βρίσκεις στις αιτήσεις του και να ελέγξει τον κωδικό. "
			. "Δεν έχεις στοιχεία πελατών (ονόματα, ΑΦΜ, ΑΔΤ, τηλέφωνα, διευθύνσεις, αριθμό παροχής), επίτηδες για προστασία δεδομένων. Μην τα ζητάς και μην τα μαντεύεις· για αυτά να ανοίξει την καρτέλα της αίτησης. "
			. "Για «Οριστικοποίηση»: περιμένουμε τον πάροχο· αν περάσουν αρκετές μέρες, να ρωτήσει το back office ή τον υπεύθυνο του δικτύου του.\n\n"
			. "Κανόνες: Μην εφευρίσκεις νούμερα ή δυνατότητες. Αν δεν ξέρεις κάτι ή ζητείται ενέργεια που δεν υπάρχει, πες το ειλικρινά. Κράτα τις απαντήσεις σύντομες και πρακτικές.";
	}

	/** Τα νούμερα του χρήστη: αλλάζουν ανά χρήστη, γι' αυτό έξω από το cache. */
	private static function stats_section(): string {
		$u     = wp_get_current_user();
		$stats = self::live_stats();

		return "Τρέχοντα νούμερα του χρήστη ({$u->display_name}) — χρησιμοποίησέ τα μόνο αν ρωτηθούν:\n"
			. "- Σήμερα: {$stats['today']} · Presale: {$stats['presale']} · Οριστικοποίηση: {$stats['finalisation']} · Αυτόν τον μήνα: {$stats['month']}";
	}

	private static function live_stats(): array {
		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$uid = get_current_user_id();
		$today_start = gmdate( 'Y-m-d 00:00:00' );
		$month_start = gmdate( 'Y-m-01 00:00:00' );
		return [
			'today'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id=%d AND created_at>=%s", $uid, $today_start ) ),
			'presale' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id=%d AND status='presale'", $uid ) ),
			'finalisation' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id=%d AND status='finalisation'", $uid ) ),
			'month'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE partner_user_id=%d AND created_at>=%s", $uid, $month_start ) ),
		];
	}
}
