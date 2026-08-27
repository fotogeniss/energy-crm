<?php
/**
 * Knowledge base — provider/program reference entries for sellers.
 *
 * Entries are grouped by provider and tagged by energy type, section
 * (documents / guarantees / charges) and customer type. Sellers browse,
 * search and filter them from the "Βάση Γνώσης" screen; admins author them
 * from Energy CRM → Βάση Γνώσης.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnergyCRM\Http\Guards;

class ECRM_KB {

	/**
	 * Η ενότητα των μαθημάτων της οθόνης «Εκπαίδευση».
	 *
	 * Ζει στον ΙΔΙΟ πίνακα με τις καρτέλες παρόχων επίτηδες: ίδιο σχήμα
	 * (τίτλος, σώμα, σειρά), ίδια φόρμα admin, ίδιο import/export JSON. Ό,τι
	 * χώριζε τα δύο ήταν μια οθόνη, όχι ένας πίνακας.
	 */
	const SECTION_TRAINING = 'training';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		// ECRM_REST::can_use() went away with the routes that moved to
		// src/Http, and this reference was left pointing at nothing: PHP 8
		// throws from call_user_func, so both endpoints answered 500 to
		// everyone. Guards::crmUser() is the same floor, defined once.
		register_rest_route( \EnergyCRM\Http\Router::NAMESPACE, '/kb', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_entries' ],
			'permission_callback' => Guards::crmUser(),
		] );
		register_rest_route( \EnergyCRM\Http\Router::NAMESPACE, '/kb/ask', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'ask' ],
			'permission_callback' => Guards::crmUser(),
		] );
		register_rest_route( \EnergyCRM\Http\Router::NAMESPACE, '/kb/read', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'mark_read' ],
			'permission_callback' => Guards::crmUser(),
		] );
	}

	/**
	 * Το πλήρες λεξιλόγιο ενοτήτων — ό,τι μπορεί να γράψει ο admin.
	 */
	public static function sections(): array {
		return [ 'docs' => 'Δικαιολογητικά', 'guarantees' => 'Εγγυήσεις', 'charges' => 'Χρεώσεις', 'other' => 'Άλλο', self::SECTION_TRAINING => 'Εκπαίδευση' ];
	}

	/**
	 * Οι ενότητες που εμφανίζονται ως φίλτρα στη «Βάση Γνώσης».
	 *
	 * Χωρίς αυτό, το «Εκπαίδευση» θα έβγαινε ως chip σε μια οθόνη που δεν
	 * δείχνει ποτέ μαθήματα — φίλτρο που πάντα επιστρέφει μηδέν.
	 */
	public static function browsable_sections(): array {
		$s = self::sections();
		unset( $s[ self::SECTION_TRAINING ] );

		return $s;
	}

	public static function customer_types(): array {
		return [ 'home' => 'Οικιακά', 'business' => 'Επαγγελματικά' ];
	}

	public static function section_label( string $s ): string {
		return self::sections()[ $s ] ?? $s;
	}

	/**
	 * Answer a seller's question using ONLY the knowledge base content (Λίτσα).
	 */
	public static function ask( WP_REST_Request $req ): WP_REST_Response {
		if ( class_exists( 'ECRM_RateLimit' ) && ! ECRM_RateLimit::allow( 'kb_ask', 20, 300 ) ) {
			return ECRM_RateLimit::too_many();
		}
		$key = class_exists( 'ECRM_Extractor' ) ? ECRM_Extractor::api_key() : '';
		if ( empty( $key ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν έχει οριστεί Claude API key (Ρυθμίσεις → Energy CRM).' ], 400 );
		}

		$p = $req->get_json_params() ?: $req->get_params();
		$q = trim( (string) ( $p['q'] ?? '' ) );
		if ( $q === '' ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Κενή ερώτηση.' ], 400 );
		}

		$context = self::context_for( $q );
		if ( $context === '' ) {
			return new WP_REST_Response( [ 'ok' => true, 'reply' => 'Δεν υπάρχει ακόμη περιεχόμενο στη Βάση Γνώσης για να απαντήσω.' ], 200 );
		}

		$system = "Είσαι η «Λίτσα», βοηθός για ενεργειακούς συνεργάτες. Μιλάς ελληνικά, σύντομα και πρακτικά. "
			. "Απαντάς ΑΠΟΚΛΕΙΣΤΙΚΑ με βάση τη «Βάση Γνώσης» που ακολουθεί — δικαιολογητικά, εγγυήσεις και χρεώσεις ανά πάροχο. "
			. "Αν η πληροφορία δεν υπάρχει στη Βάση Γνώσης, πες το ξεκάθαρα («Δεν το βρήκα στη Βάση Γνώσης») και μην εφευρίσκεις ποσά ή κανόνες. "
			. "Όταν αναφέρεις ποσά/όρια, πες σε ποιον πάροχο και ποια περίπτωση αφορούν.\n\n"
			. "=== ΒΑΣΗ ΓΝΩΣΗΣ ===\n" . $context;

		$body = [
			'model'      => ECRM_Extractor::model(),
			'max_tokens' => 1024,
			'system'     => $system,
			'messages'   => [ [ 'role' => 'user', 'content' => mb_substr( $q, 0, 2000 ) ] ],
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
			if ( ( $blk['type'] ?? '' ) === 'text' ) { $reply .= $blk['text']; }
		}
		return new WP_REST_Response( [ 'ok' => true, 'reply' => trim( $reply ) ], 200 );
	}

	/** Build a plain-text KB context relevant to the question (capped). */
	public static function context_for( string $q ): string {
		global $wpdb;
		$t = ECRM_DB::table( 'kb_entries' );

		$words = array_filter( preg_split( '/\s+/', mb_strtolower( $q ) ), function ( $w ) { return mb_strlen( $w ) > 3; } );
		$rows  = [];
		if ( $words ) {
			$conds = []; $params = [];
			foreach ( array_slice( $words, 0, 8 ) as $w ) {
				$like = '%' . $wpdb->esc_like( $w ) . '%';
				$conds[] = '( title LIKE %s OR body LIKE %s OR provider_name LIKE %s )';
				array_push( $params, $like, $like, $like );
			}
			$sql  = "SELECT provider_name, title, body FROM {$t} WHERE active = 1 AND ( " . implode( ' OR ', $conds ) . ' ) ORDER BY provider_name, sort_order LIMIT 14';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		}
		if ( ! $rows ) {
			$rows = $wpdb->get_results( "SELECT provider_name, title, body FROM {$t} WHERE active = 1 ORDER BY provider_name, sort_order LIMIT 18", ARRAY_A );
		}

		$parts = [];
		$total = 0;
		foreach ( (array) $rows as $r ) {
			$text  = mb_substr( trim( wp_strip_all_tags( (string) $r['body'] ) ), 0, 1800 );
			$chunk = '## ' . ( $r['provider_name'] ?: '—' ) . ' — ' . $r['title'] . "\n" . $text;
			$total += mb_strlen( $chunk );
			if ( $total > 13000 ) { break; }
			$parts[] = $chunk;
		}
		return implode( "\n\n", $parts );
	}

	/**
	 * Filtered, provider-grouped list of active entries.
	 */
	public static function list_entries( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$t  = ECRM_DB::table( 'kb_entries' );

		$q       = trim( (string) $req->get_param( 'q' ) );
		$energy  = sanitize_text_field( (string) $req->get_param( 'energy' ) );
		$section = sanitize_text_field( (string) $req->get_param( 'section' ) );
		$type    = sanitize_text_field( (string) $req->get_param( 'type' ) );
		$prov    = (int) $req->get_param( 'provider' );

		$where  = [ 'active = 1' ];
		$params = [];

		/*
		 * Τα μαθήματα και οι καρτέλες παρόχων μοιράζονται πίνακα, όχι οθόνη.
		 * Χωρίς αυτόν τον διαχωρισμό, 12 μαθήματα για το ίδιο το CRM θα
		 * κάθονταν ανάμεσα στα δικαιολογητικά της ZeniΘ, κάτω από την ομάδα
		 * «—», επειδή δεν έχουν πάροχο. Τα ζητάς ρητά και τα παίρνεις· δεν
		 * τα ζητάς και δεν εμφανίζονται πουθενά.
		 */
		$training = ( self::SECTION_TRAINING === $section );
		$where[]  = $training ? 'section = %s' : 'section <> %s';
		$params[] = self::SECTION_TRAINING;

		if ( $energy && in_array( $energy, [ 'power', 'gas' ], true ) ) { $where[] = 'energy_type = %s'; $params[] = $energy; }
		if ( ! $training && $section && array_key_exists( $section, self::sections() ) ) { $where[] = 'section = %s'; $params[] = $section; }
		if ( $type && array_key_exists( $type, self::customer_types() ) ) { $where[] = 'customer_type = %s'; $params[] = $type; }
		if ( $prov ) { $where[] = 'provider_id = %d'; $params[] = $prov; }
		if ( $q !== '' ) {
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '( title LIKE %s OR body LIKE %s OR provider_name LIKE %s )';
			array_push( $params, $like, $like, $like );
		}
		$sql = "SELECT id, provider_id, provider_name, energy_type, section, customer_type, title, body, sort_order
				FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY provider_name, sort_order, id';
		// Το $params δεν είναι ποτέ πια άδειο (το φίλτρο ενότητας μπαίνει
		// πάντα), οπότε ο κλάδος χωρίς prepare() έφυγε αντί να μείνει νεκρός.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$sections = self::sections();
		$ctypes   = self::customer_types();
		$energies = [ 'power' => 'Ρεύμα', 'gas' => 'Αέριο' ];

		// Ποια μαθήματα έχει δηλώσει διαβασμένα ΑΥΤΟΣ ο χρήστης. Ένα ερώτημα,
		// και μόνο για την «Εκπαίδευση»: η «Βάση Γνώσης» δεν έχει έννοια
		// προόδου και δεν πληρώνει γι' αυτήν.
		$read = $training ? self::read_ids( get_current_user_id() ) : [];

		// Group by provider (preserve order).
		$groups = [];
		foreach ( (array) $rows as $r ) {
			$key = $r['provider_name'] ?: '—';
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [ 'provider' => $key, 'provider_id' => (int) $r['provider_id'], 'entries' => [] ];
			}
			$groups[ $key ]['entries'][] = [
				'id'            => (int) $r['id'],
				'title'         => $r['title'],
				'body'          => wpautop( wp_kses_post( (string) $r['body'] ) ),
				'energy'        => $r['energy_type'],
				'energy_label'  => $energies[ $r['energy_type'] ] ?? '',
				'section'       => $r['section'],
				'section_label' => $sections[ $r['section'] ] ?? $r['section'],
				'type'          => $r['customer_type'],
				'type_label'    => $ctypes[ $r['customer_type'] ] ?? '',
				'read'          => isset( $read[ (int) $r['id'] ] ),
			];
		}

		return new WP_REST_Response( [
			'ok'       => true,
			'groups'   => array_values( $groups ),
			'count'    => count( $rows ),
			'sections' => self::browsable_sections(),
			'types'    => $ctypes,
			'progress' => $training ? self::progress( get_current_user_id() ) : null,
		], 200 );
	}

	/**
	 * «Το διάβασα» / «δεν το διάβασα» για ένα μάθημα.
	 *
	 * Ρητή δήλωση του πωλητή, ΟΧΙ συμπέρασμα από το ότι άνοιξε την καρτέλα.
	 * Το αυτόματο («την άνοιξε άρα τη διάβασε») λέει ψέματα, και μια πρόοδος
	 * που λέει ψέματα δεν την κοιτάει κανείς — ούτε ο ίδιος. Γι' αυτό
	 * ξεπατιέται κιόλας: αν κάτι δεν το θυμάται, το ξαναβάζει στη λίστα του.
	 */
	public static function mark_read( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;

		$uid = get_current_user_id();
		$p   = $req->get_json_params() ?: $req->get_params();
		$id  = (int) ( $p['id'] ?? 0 );
		$on  = ! empty( $p['read'] );

		$entries = ECRM_DB::table( 'kb_entries' );
		$reads   = ECRM_DB::table( 'kb_read' );

		/*
		 * Το id πρέπει να είναι ΕΝΕΡΓΟ ΜΑΘΗΜΑ, όχι οποιαδήποτε καρτέλα.
		 * Αλλιώς ο πίνακας προόδου γεμίζει ids δικαιολογητικών και το
		 * «4 από 12» παύει να σημαίνει οτιδήποτε.
		 */
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$entries} WHERE id = %d AND active = 1 AND section = %s",
			$id,
			self::SECTION_TRAINING
		) );
		if ( ! $exists ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Άγνωστο μάθημα.' ], 404 );
		}

		if ( $on ) {
			/*
			 * INSERT IGNORE, όχι SELECT-μετά-INSERT: δύο γρήγορα κλικ από το
			 * ίδιο χέρι είναι δύο αιτήματα, και το unique key (user_id,
			 * entry_id) είναι ο μόνος φύλακας που δεν χάνει την κούρσα.
			 */
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$reads} (user_id, entry_id, read_at) VALUES (%d, %d, %s)",
				$uid,
				$id,
				current_time( 'mysql' )
			) );
		} else {
			$wpdb->delete( $reads, [ 'user_id' => $uid, 'entry_id' => $id ], [ '%d', '%d' ] );
		}

		return new WP_REST_Response( [ 'ok' => true, 'read' => $on ] + self::progress( $uid ), 200 );
	}

	/**
	 * Πόσα από πόσα — μετρημένο στη βάση, όχι στον browser.
	 *
	 * Ο browser ξέρει μόνο όσα μαθήματα κατέβασε· αν κάποιο απενεργοποιηθεί
	 * ή προστεθεί όσο η οθόνη είναι ανοιχτή, το δικό του άθροισμα γίνεται
	 * λάθος σιωπηλά. Η βάση δεν έχει αυτό το πρόβλημα.
	 *
	 * @return array{done:int,total:int}
	 */
	public static function progress( int $user_id ): array {
		global $wpdb;

		$entries = ECRM_DB::table( 'kb_entries' );
		$reads   = ECRM_DB::table( 'kb_read' );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$entries} WHERE active = 1 AND section = %s",
			self::SECTION_TRAINING
		) );
		$done = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$reads} r INNER JOIN {$entries} e ON e.id = r.entry_id WHERE r.user_id = %d AND e.active = 1 AND e.section = %s",
			$user_id,
			self::SECTION_TRAINING
		) );

		return [ 'done' => $done, 'total' => $total ];
	}

	/**
	 * @return array<int,true> Τα ids που ο χρήστης έχει σημειώσει «το διάβασα».
	 */
	private static function read_ids( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return [];
		}

		$t   = ECRM_DB::table( 'kb_read' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT entry_id FROM {$t} WHERE user_id = %d", $user_id ) );

		$out = [];
		foreach ( (array) $ids as $id ) {
			$out[ (int) $id ] = true;
		}

		return $out;
	}
}
