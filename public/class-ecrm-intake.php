<?php
/**
 * «Ο σύνδεσμός μου» — δημόσια είσοδος όπου ο πελάτης στέλνει ο ίδιος τα στοιχεία του.
 *
 * Ο πωλητής στέλνει έναν σύνδεσμο (SMS/WhatsApp). Ο πελάτης ανοίγει
 * `?ecrm_intake=TOKEN` χωρίς λογαριασμό, γράφει ΔΥΟ πεδία (όνομα, κινητό),
 * φωτογραφίζει λογαριασμό και ταυτότητα, δίνει συναίνεση. Στον πωλητή
 * εμφανίζεται Υποψήφιος με τα αρχεία ήδη μέσα.
 *
 * ΤΟ AI ΔΕΝ ΤΡΕΧΕΙ ΕΔΩ. Δημόσιο endpoint που καλεί πληρωμένο API είναι ανοιχτή
 * στρόφιγγα κόστους: όποιος έχει τον σύνδεσμο ανεβάζει εικόνες και ξοδεύει τα
 * credits του ιδιοκτήτη. Η δημόσια πλευρά ΜΟΝΟ αποθηκεύει· η εξαγωγή τρέχει
 * αργότερα, από τον συνδεδεμένο πωλητή, με ρητό κλικ.
 *
 * Δύο αιτήματα και όχι ένα: πρώτα τα στοιχεία επικοινωνίας (δημιουργείται ο
 * υποψήφιος), μετά ένα αρχείο τη φορά. Ένα ενιαίο POST με τέσσερις
 * φωτογραφίες σε base64 ξεπερνά τα προεπιλεγμένα όρια της PHP — και, το πιο
 * σημαντικό, έτσι ο υποψήφιος υπάρχει ΑΚΟΜΑ ΚΑΙ ΑΝ ο πελάτης τα παρατήσει
 * στη μέση: μένει τουλάχιστον ένα τηλέφωνο να πάρεις.
 *
 * Τα token αντιγράφουν το σχήμα του ECRM_Tracking: {id}-{hmac20} με κλειδί
 * που ζει στη βάση, άρα ανακλήσιμο χωρίς να πειραχτεί το salt του site.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Intake {

	/** Το κλειδί του πωλητή, στο user_meta. Η ανάκληση το σβήνει. */
	const META_KEY = 'ecrm_intake_key';

	/** Τα μόνα είδη εγγράφου που δέχεται η δημόσια σελίδα. */
	const KINDS = [ 'provider_bill', 'id_card' ];

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		// Αυστηρό pattern και όχι [A-Za-z0-9-]+: αλλιώς το /intake/link θα
		// ταίριαζε ΚΑΙ ως token, και δύο διαδρομές με διαφορετικό φύλακα θα
		// διεκδικούσαν το ίδιο URL.
		$t = '(?P<token>\d+\-[a-f0-9]{20})';

		register_rest_route( \EnergyCRM\Http\Router::NAMESPACE, '/intake/' . $t, [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_submit' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( \EnergyCRM\Http\Router::NAMESPACE, '/intake/' . $t . '/file', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_file' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( \EnergyCRM\Http\Router::NAMESPACE, '/intake/link', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'rest_link' ],
			'permission_callback' => \EnergyCRM\Http\Guards::crmUser(),
		] );
		register_rest_route( \EnergyCRM\Http\Router::NAMESPACE, '/intake/link/revoke', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_revoke' ],
			'permission_callback' => \EnergyCRM\Http\Guards::crmUser(),
		] );
	}

	// --- Token ανά πωλητή, ανακλητό ------------------------------------------

	public static function token( int $uid ): string {
		$key = self::key_for( $uid );

		return $key === '' ? '' : self::sign( 'ecrm_intake_', $uid, $key );
	}

	/**
	 * Ο πωλητής πίσω από ένα token, ή null.
	 *
	 * **Ποτέ δεν παράγει κλειδί** — ίδιος κανόνας με το ECRM_Tracking::verify().
	 * Η διαδρομή είναι ανώνυμη· αν παρήγαγε, αίτημα σε τυχαίο id θα έγραφε
	 * user_meta, δηλαδή θα έδινε σε οποιονδήποτε τρόπο να γεμίσει τη βάση.
	 */
	public static function verify( string $token ): ?int {
		if ( ! preg_match( '/^(\d+)-([a-f0-9]{20})$/', $token, $m ) ) {
			return null;
		}
		$uid = (int) $m[1];
		$key = self::stored_key( $uid );

		return $key !== '' && hash_equals( self::sign( 'ecrm_intake_', $uid, $key ), $token ) ? $uid : null;
	}

	public static function revoke( int $uid ): void {
		delete_user_meta( $uid, self::META_KEY );
	}

	public static function url( int $uid ): string {
		$token = self::token( $uid );

		return $token === '' ? '' : add_query_arg( 'ecrm_intake', $token, home_url( '/' ) );
	}

	private static function sign( string $prefix, int $id, string $key ): string {
		$sig = substr( hash_hmac( 'sha256', $prefix . $id . '|' . $key, wp_salt( 'auth' ) ), 0, 20 );

		return $id . '-' . $sig;
	}

	private static function stored_key( int $uid ): string {
		return $uid > 0 ? (string) get_user_meta( $uid, self::META_KEY, true ) : '';
	}

	private static function key_for( int $uid ): string {
		$key = self::stored_key( $uid );
		if ( $key !== '' ) {
			return $key;
		}
		$key = wp_generate_password( 24, false, false );
		update_user_meta( $uid, self::META_KEY, $key );

		return $key;
	}

	/**
	 * Ο σύνδεσμος ενός πωλητή που έφυγε ή απενεργοποιήθηκε δεν δουλεύει.
	 *
	 * Χωρίς αυτό, ένας συνεργάτης που αποχώρησε θα συνέχιζε να τραβάει
	 * υποψηφίους στο όνομά του για πάντα -- ο σύνδεσμος είναι ήδη μοιρασμένος
	 * σε κινητά πελατών και δεν ανακαλείται με το να φύγει κάποιος.
	 */
	private static function partner_active( int $uid ): bool {
		if ( ! get_userdata( $uid ) ) {
			return false;
		}
		if ( get_user_meta( $uid, \EnergyCRM\Persistence\TeamRepository::DISABLED_META, true ) ) {
			return false;
		}

		return user_can( $uid, \EnergyCRM\Access\Capability::CREATE_CONTRACT );
	}

	// --- Ο σύνδεσμος, από την πλευρά του πωλητή -------------------------------

	public static function rest_link( WP_REST_Request $req ): WP_REST_Response {
		$uid = get_current_user_id();

		return new WP_REST_Response( [ 'ok' => true, 'url' => self::url( $uid ) ], 200 );
	}

	public static function rest_revoke( WP_REST_Request $req ): WP_REST_Response {
		$uid = get_current_user_id();
		self::revoke( $uid );

		// Νέο κλειδί αμέσως: ο πωλητής δεν μένει ποτέ χωρίς σύνδεσμο, απλώς ο
		// παλιός σταματά να ισχύει από τη στιγμή αυτή.
		return new WP_REST_Response( [ 'ok' => true, 'url' => self::url( $uid ) ], 200 );
	}

	// --- Η δημόσια υποβολή ----------------------------------------------------

	public static function rest_submit( WP_REST_Request $req ): WP_REST_Response {
		if ( class_exists( 'ECRM_RateLimit' ) && ! ECRM_RateLimit::allow( 'intake_submit', 4, 900 ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Πολλές προσπάθειες. Δοκιμάστε αργότερα.' ], 429 );
		}

		$uid = self::verify( (string) $req['token'] );
		if ( ! $uid || ! self::partner_active( $uid ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_token' ], 404 );
		}

		$p       = $req->get_json_params() ?: $req->get_params();
		$phone   = self::phone( (string) ( $p['phone'] ?? '' ) );
		$consent = ! empty( $p['consent'] );

		/*
		 * Το όνομα ΔΕΝ ζητιέται από τον πελάτη -- είναι τυπωμένο πάνω στην
		 * ταυτότητα που ανεβάζει, και το διαβάζει το AI. Κάθε πεδίο που
		 * γλιτώνει μια δημόσια φόρμα είναι πελάτης που δεν την παρατάει.
		 *
		 * Ως ετικέτα του υποψηφίου μπαίνει το κινητό: είναι το μόνο πραγματικό
		 * αναγνωριστικό που έχουμε μέχρι να διαβαστούν τα έγγραφα, και είναι
		 * ούτως ή άλλως αυτό που θα καλέσει ο πωλητής. Η στήλη είναι NOT NULL,
		 * οπότε κάτι πρέπει να μπει· ένα «Νέος υποψήφιος» δεν θα βοηθούσε
		 * κανέναν να ξεχωρίσει δύο γραμμές.
		 */
		$name = $phone;

		if ( $phone === '' ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Συμπληρώστε έγκυρο κινητό.' ], 400 );
		}
		if ( ! $consent ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Απαιτείται η συναίνεσή σας.' ], 400 );
		}

		global $wpdb;
		$lt = ECRM_DB::table( 'leads' );

		/*
		 * Ιδεμποτεντία -- ΟΧΙ λήξη του συνδέσμου.
		 *
		 * Ο σύνδεσμος είναι σκόπιμα μόνιμος ανά πωλητή: τον στέλνει σε δεκάδες
		 * πελάτες. Αν «καιγόταν» μετά την πρώτη υποβολή, ο δεύτερος πελάτης δεν
		 * θα μπορούσε να τον χρησιμοποιήσει και ο πωλητής θα παρήγαγε καινούριο
		 * κάθε φορά -- ακριβώς ό,τι απορρίφθηκε στον σχεδιασμό.
		 *
		 * Το πραγματικό κενό ήταν ότι κάθε refresh έφτιαχνε ΝΕΟ υποψήφιο (το
		 * βρήκε ο ιδιοκτήτης, πρώτη ζωντανή δοκιμή). Εδώ η ίδια υποβολή --
		 * ίδιος πωλητής, ίδιο κινητό, ακόμα «νέος», ακόμα αμετάτρεπτος --
		 * ενώνεται με τον υπάρχοντα αντί να τον διπλασιάσει.
		 *
		 * Παρενέργεια που είναι χαρακτηριστικό: ο πελάτης που έστειλε τον
		 * λογαριασμό και ξέχασε την ταυτότητα ξαναμπαίνει και τη στέλνει στον
		 * ΙΔΙΟ υποψήφιο.
		 *
		 * Η απάντηση είναι πανομοιότυπη είτε βρέθηκε είτε φτιάχτηκε, οπότε
		 * κανείς δεν μαθαίνει από έξω αν υπάρχει πελάτης με κάποιο τηλέφωνο.
		 *
		 * NOW() - INTERVAL και όχι ημερομηνία υπολογισμένη σε PHP: το
		 * created_at το γράφει η MySQL με το δικό της ρολόι, και σύγκριση δύο
		 * ρολογιών είναι ακριβώς η κατηγορία σφάλματος που έχει ήδη δαγκώσει
		 * αυτό το plugin (HANDOVER §8).
		 */
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$lt}
			  WHERE partner_user_id = %d AND phone = %s AND source = 'link'
			    AND stage = 'new' AND contract_id IS NULL
			    AND created_at > ( NOW() - INTERVAL 12 HOUR )
			  ORDER BY id DESC LIMIT 1",
			$uid,
			$phone
		) );

		if ( $existing > 0 ) {
			return new WP_REST_Response( [ 'ok' => true, 'ref' => self::lead_ref( $uid, $existing ) ], 200 );
		}

		$wpdb->insert( $lt, [
			'partner_user_id' => $uid,
			'name'            => $name,
			'phone'           => $phone,
			'source'          => 'link',
			'stage'           => 'new',
			'consent_at'      => current_time( 'mysql' ),
			'consent_ip'      => \EnergyCRM\Infrastructure\RequestIp::current(),
		] );
		$lead_id = (int) $wpdb->insert_id;

		if ( ! $lead_id ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Σφάλμα αποθήκευσης.' ], 500 );
		}

		return new WP_REST_Response( [ 'ok' => true, 'ref' => self::lead_ref( $uid, $lead_id ) ], 200 );
	}

	/**
	 * Ένα αρχείο τη φορά, κρεμασμένο στον υποψήφιο που μόλις δημιουργήθηκε.
	 *
	 * Οι έλεγχοι είναι αυτούσιοι από το ECRM_Tracking::rest_upload(): whitelist
	 * MIME, magic bytes, όριο μεγέθους, προστατευμένος φάκελος. Δεν επινοείται
	 * τίποτα -- η δημόσια διαδρομή έχει ήδη σκληρύνει μία φορά και δεν υπάρχει
	 * λόγος να ξαναγίνει η ίδια δουλειά με άλλα, πιο φρέσκα λάθη.
	 */
	public static function rest_file( WP_REST_Request $req ): WP_REST_Response {
		if ( class_exists( 'ECRM_RateLimit' ) && ! ECRM_RateLimit::allow( 'intake_file', 20, 600 ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Πολλές προσπάθειες. Δοκιμάστε αργότερα.' ], 429 );
		}

		$uid = self::verify( (string) $req['token'] );
		if ( ! $uid || ! self::partner_active( $uid ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_token' ], 404 );
		}

		$p       = $req->get_json_params() ?: $req->get_params();
		$lead_id = self::verify_ref( $uid, (string) ( $p['ref'] ?? '' ) );
		if ( ! $lead_id ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_ref' ], 404 );
		}

		global $wpdb;
		$lt   = ECRM_DB::table( 'leads' );
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT partner_user_id, contract_id FROM {$lt} WHERE id = %d", $lead_id ), ARRAY_A );

		// Ο υποψήφιος πρέπει να ανήκει ΣΕ ΑΥΤΟΝ τον πωλητή και να μην έχει
		// μετατραπεί ήδη: μετά τη μετατροπή τα έγγραφα ανήκουν στη σύμβαση και
		// περνούν από τη διαδρομή του /track, όχι από εδώ.
		if ( ! $lead || (int) $lead['partner_user_id'] !== $uid || ! empty( $lead['contract_id'] ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_ref' ], 404 );
		}

		// Πόσα αρχεία κρέμονται ήδη. Χωρίς όριο, ο σύνδεσμος γίνεται δωρεάν
		// αποθηκευτικός χώρος για όποιον τον έχει.
		$ft    = ECRM_DB::table( 'files' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ft} WHERE lead_id = %d", $lead_id ) );
		if ( $count >= 6 ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Έχετε ήδη ανεβάσει τον μέγιστο αριθμό αρχείων.' ], 400 );
		}

		$kind = sanitize_key( (string) ( $p['kind'] ?? 'other' ) );
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$kind = 'other';
		}

		$data = (string) ( $p['data'] ?? '' );
		if ( ! preg_match( '#^data:([a-zA-Z0-9./+\-]+);base64,#', $data, $m ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Μη έγκυρο αρχείο.' ], 400 );
		}
		$mime    = strtolower( $m[1] );
		$allowed = ECRM_Tracking::upload_types();
		if ( ! isset( $allowed[ $mime ] ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Επιτρέπονται μόνο JPG, PNG, WEBP, HEIC ή PDF.' ], 400 );
		}
		$bin = base64_decode( substr( $data, strlen( $m[0] ) ), true );
		if ( false === $bin || strlen( $bin ) < 64 ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Το αρχείο είναι κατεστραμμένο.' ], 400 );
		}
		if ( strlen( $bin ) > 12 * 1024 * 1024 ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Το αρχείο είναι πολύ μεγάλο (μέγιστο 12MB).' ], 400 );
		}

		// Magic bytes, ώστε ο δηλωμένος τύπος να μη μπορεί να πει ψέματα.
		$sig_ok = true;
		if ( 'application/pdf' === $mime ) {
			$sig_ok = ( '%PDF-' === substr( $bin, 0, 5 ) );
		} elseif ( 'image/png' === $mime ) {
			$sig_ok = ( "\x89PNG\r\n\x1a\n" === substr( $bin, 0, 8 ) );
		} elseif ( 'image/jpeg' === $mime ) {
			$sig_ok = ( "\xFF\xD8\xFF" === substr( $bin, 0, 3 ) );
		}
		if ( ! $sig_ok ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Ο τύπος αρχείου δεν ταιριάζει.' ], 400 );
		}

		$ext    = $allowed[ $mime ];
		$stored = ECRM_Files::put_bytes( $bin, $ext, $mime, $kind . '.' . $ext );
		if ( ! $stored ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Σφάλμα αποθήκευσης.' ], 500 );
		}

		$wpdb->insert( $ft, [
			'contract_id'   => null,
			'lead_id'       => $lead_id,
			'attachment_id' => null,
			'doc_kind'      => $kind,
			'filename'      => $stored['filename'],
			'mime'          => $stored['mime'],
			'path'          => $stored['path'],
			'protected'     => 1,
		] );

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	// --- Βοηθητικά ------------------------------------------------------------

	private static function lead_ref( int $uid, int $lead_id ): string {
		$key = self::stored_key( $uid );

		return $key === '' ? '' : self::sign( 'ecrm_intake_lead_', $lead_id, $key );
	}

	private static function verify_ref( int $uid, string $ref ): ?int {
		if ( ! preg_match( '/^(\d+)-([a-f0-9]{20})$/', $ref, $m ) ) {
			return null;
		}
		$lead_id = (int) $m[1];
		$made    = self::lead_ref( $uid, $lead_id );

		return $made !== '' && hash_equals( $made, $ref ) ? $lead_id : null;
	}

	/**
	 * Κινητό σε μορφή που μπορεί να καλέσει άνθρωπος, ή '' αν δεν στέκει.
	 *
	 * ΕΝΑΣ κανόνας κανονικοποίησης, και δεν είναι εδώ. Η πρώτη γραφή έκοβε
	 * μόνη της τα μη-ψηφία και έλεγχε μήκος -- και ο
	 * AfmIsNormalisedInOnePlaceTest το έκοψε αμέσως, σωστά. Η
	 * ECRM_Messaging::normalize_phone() κάνει ΗΔΗ το κόψιμο, τον κωδικό χώρας
	 * ΚΑΙ τον έλεγχο μήκους, και επιστρέφει '' για ό,τι δεν στέκει. Δεύτερος
	 * έλεγχος εδώ δεν θα πρόσθετε ασφάλεια· θα πρόσθετε δεύτερη αλήθεια, και
	 * αργότερα δύο τιμές που διαφέρουν χωρίς να το πει κανείς.
	 *
	 * Το '' όταν λείπει η κλάση αποτυγχάνει ΚΛΕΙΣΤΑ: καμία υποβολή δεν περνά
	 * με ανεπαλήθευτο τηλέφωνο.
	 */
	private static function phone( string $raw ): string {
		return class_exists( 'ECRM_Messaging' ) ? ECRM_Messaging::normalize_phone( $raw ) : '';
	}

	/**
	 * Το κείμενο συναίνεσης.
	 *
	 * ΠΡΟΣΧΕΔΙΟ. Χρειάζεται έλεγχο δικηγόρου πριν βγει σε πραγματικούς πελάτες
	 * -- εδώ συλλέγονται ταυτότητες ιδιωτών μέσα από δημόσια σελίδα. Βλ.
	 * `docs/GDPR-DPA.html` §8.
	 */
	// --- Η δημόσια σελίδα ------------------------------------------------------

	/**
	 * Αυτοτελής σελίδα, με δικό της CSS μέσα στο αρχείο.
	 *
	 * Ίδια επιλογή με το `ECRM_Tracking::maybe_render()` και για τον ίδιο λόγο:
	 * η σελίδα του πελάτη δεν φορτώνει τα κοινά φύλλα του CRM, ώστε καμία
	 * αλλαγή στο kit να μη μπορεί να τη σπάσει εν αγνοία μας. Το τίμημα είναι
	 * ότι τα χρώματα και τα μεγέθη εδώ ΔΕΝ ελέγχονται από τους φύλακες
	 * τυπογραφίας/χρώματος -- τυφλό σημείο ήδη τεκμηριωμένο στον
	 * TypographyIsDecidedInOnePlaceTest, που το ονομάζει ρητά για το tracking.
	 */
	public static function maybe_render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- δημόσια σελίδα με υπογεγραμμένο token, όχι φόρμα.
		$token = isset( $_GET['ecrm_intake'] ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', wp_unslash( $_GET['ecrm_intake'] ) ) : '';
		if ( ! $token ) {
			return;
		}

		$uid     = self::verify( (string) $token );
		$valid   = ( $uid && self::partner_active( (int) $uid ) );
		$accent  = class_exists( 'ECRM_Admin' )
			? (string) ECRM_Admin::get( 'accent_color', ECRM_Admin::DEFAULT_ACCENT )
			: '#c2f04a';
		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : '';
		$rest    = esc_url_raw( rest_url( \EnergyCRM\Http\Router::NAMESPACE . '/intake/' . $token ) );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Στείλε τα στοιχεία σου</title>
<?php
echo \EnergyCRM\Infrastructure\LocalFonts::styleTag( ECRM_URL ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- σταθερό CSS με URL που φτιάχνει η ίδια η κλάση.
?>
<style>
	:root { --accent: <?php echo esc_html( $accent ); ?>; --accent-text:#1a2208;
		--bg:#111110; --card:#ffffff; --ink:#2a2926; --muted:#6e6c66; --line:#dedcd7;
		--soft:#f1f1ee; --ok:#00531e; --ok-bg:#e3f0e4; --err:#c32b47; --err-bg:#fdeaea; }
	* { box-sizing:border-box; }
	body { margin:0; background:var(--bg); color:var(--ink); font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
		line-height:1.55; -webkit-font-smoothing:antialiased; padding:18px 14px 60px; }
	.card { max-width:460px; margin:0 auto; background:var(--card); border-radius:18px; padding:22px 20px 24px; }
	.brand { font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
	h1 { font-size:21px; font-weight:800; margin:4px 0 6px; letter-spacing:-.01em; }
	.lede { font-size:14px; color:var(--muted); margin:0 0 18px; }
	label { display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); margin:0 0 5px; }
	input[type=text],input[type=tel] { width:100%; border:1px solid var(--line); border-radius:10px; padding:11px 12px; font-size:16px;
		font-family:inherit; color:var(--ink); background:#fff; }
	input:focus { outline:2px solid var(--accent); outline-offset:1px; }
	.f { margin-bottom:13px; }
	.pick { display:block; width:100%; border:1.5px dashed var(--line); border-radius:12px; background:#fff; padding:15px 12px;
		text-align:center; cursor:pointer; margin-bottom:9px; font-family:inherit; }
	.pick b { display:block; font-size:14px; color:var(--ink); margin-top:3px; }
	.pick span { font-size:12px; color:var(--muted); }
	.pick .ic { font-size:21px; }
	.pick.on { border-style:solid; border-color:var(--ok); background:var(--ok-bg); }
	.pick.on b { color:var(--ok); }
	.consent { display:flex; gap:9px; align-items:flex-start; background:var(--soft); border-radius:10px; padding:11px; margin:14px 0 4px; }
	.consent input { margin:2px 0 0; width:17px; height:17px; accent-color:var(--accent); flex:0 0 17px; }
	.consent label { text-transform:none; letter-spacing:0; font-size:12px; font-weight:400; color:#42413d; margin:0; }
	button.send { width:100%; border:0; border-radius:12px; background:var(--accent); color:var(--accent-text); font-family:inherit;
		font-size:16px; font-weight:800; padding:13px; margin-top:14px; cursor:pointer; }
	button.send[disabled] { opacity:.55; cursor:default; }
	.msg { border-radius:10px; padding:11px 13px; font-size:13.5px; margin-top:13px; display:none; }
	.msg.err { background:var(--err-bg); color:var(--err); display:block; }
	.msg.ok { background:var(--ok-bg); color:var(--ok); display:block; }
	.done { text-align:center; padding:14px 0 6px; }
	.done .tick { font-size:40px; }
	.done h2 { font-size:19px; margin:6px 0 4px; }
	.done p { font-size:14px; color:var(--muted); margin:0; }
	.dead { text-align:center; color:var(--muted); font-size:14px; padding:16px 0; }
</style>
</head>
<body>
<div class="card">
<?php if ( ! $valid ) : ?>
	<div class="brand"><?php echo esc_html( $company ); ?></div>
	<h1>Ο σύνδεσμος δεν ισχύει</h1>
	<div class="dead">Ζητήστε καινούριο σύνδεσμο από τον συνεργάτη που σας τον έστειλε.</div>
<?php else : ?>
	<div id="form">
		<div class="brand"><?php echo esc_html( $company ); ?></div>
		<h1>Στείλε τα στοιχεία σου</h1>
		<p class="lede">Το κινητό σου και δύο φωτογραφίες. Τα υπόλοιπα τα διαβάζουμε από τα έγγραφα.</p>

		<div class="f"><label for="ph">Κινητό</label>
			<input type="tel" id="ph" autocomplete="tel" inputmode="tel" placeholder="69........"></div>

		<button type="button" class="pick" data-kind="provider_bill">
			<span class="ic">📄</span><b>Λογαριασμός ρεύματος ή αερίου</b><span>Πάτα για φωτογραφία ή αρχείο</span></button>
		<button type="button" class="pick" data-kind="id_card">
			<span class="ic">🪪</span><b>Ταυτότητα ή διαβατήριο</b><span>Μπρος και πίσω, αν χρειάζεται</span></button>

		<div class="consent">
			<input type="checkbox" id="cs">
			<label for="cs"><?php echo esc_html( self::consent_text() ); ?></label>
		</div>

		<button type="button" class="send" id="go">Αποστολή</button>
		<div class="msg" id="msg"></div>
	</div>

	<div class="done" id="done" style="display:none">
		<div class="tick">✅</div>
		<h2>Τα λάβαμε</h2>
		<p>Ο συνεργάτης σου θα επικοινωνήσει σύντομα.</p>
	</div>

	<input type="file" id="fi" accept="image/jpeg,image/png,image/webp,image/heic,application/pdf" multiple style="display:none">

	<script>
	(function () {
		var REST = <?php echo wp_json_encode( $rest ); ?>;

		/* ΚΑΜΙΑ μνήμη συνεδρίας εδώ, και είναι απόφαση.
		 *
		 * Μπήκε αρχικά sessionStorage ώστε το refresh να μη δείχνει ξανά άδεια
		 * φόρμα. Έλυνε πρόβλημα που ΔΕΝ υπάρχει: τη διπλοεγγραφή την αποτρέπει
		 * η ιδεμποτεντία στον server, που ενώνει τη δεύτερη υποβολή με τον ίδιο
		 * υποψήφιο. Σε αντάλλαγμα κλείδωνε τη σελίδα για όλη τη συνεδρία, και
		 * χρειάστηκε κουμπί εξόδου -- μπάλωμα πάνω σε μπάλωμα, με κείμενο που
		 * μιλούσε στον πωλητή μέσα σε οθόνη του πελάτη.
		 *
		 * Ο πελάτης στέλνει μία φορά και τελειώνει. Refresh δείχνει τη φόρμα,
		 * όπως κάθε φόρμα· δεύτερη υποβολή με το ίδιο κινητό καταλήγει στον
		 * ίδιο φάκελο. Η προστασία ζει εκεί που ανήκει: στον server. */
		function showDone() {
			document.getElementById('form').style.display = 'none';
			document.getElementById('done').style.display = 'block';
		}
		var files = { provider_bill: [], id_card: [] };
		var picking = null;
		var fi = document.getElementById('fi');
		var msg = document.getElementById('msg');

		function say(t, cls) { msg.className = 'msg ' + cls; msg.textContent = t; }

		document.querySelectorAll('.pick').forEach(function (b) {
			b.addEventListener('click', function () { picking = b.getAttribute('data-kind'); fi.value = ''; fi.click(); });
		});

		fi.addEventListener('change', function () {
			if (!picking || !fi.files.length) { return; }
			var btn = document.querySelector('.pick[data-kind="' + picking + '"]');
			var list = [];
			for (var i = 0; i < fi.files.length && list.length < 3; i++) { list.push(fi.files[i]); }
			files[picking] = list;
			btn.classList.add('on');
			btn.querySelector('span:last-child').textContent = list.length + (list.length === 1 ? ' αρχείο' : ' αρχεία');
		});

		function toDataUrl(file) {
			return new Promise(function (res, rej) {
				var r = new FileReader();
				r.onload = function () { res(r.result); };
				r.onerror = function () { rej(new Error('read')); };
				r.readAsDataURL(file);
			});
		}

		/* Σμίκρυνση ΠΡΙΝ το ανέβασμα, και δεν είναι καλλωπισμός.
		 *
		 * Φωτογραφία σύγχρονου κινητού είναι 4-8MB· σε base64 φουσκώνει κατά
		 * 33% και ξεπερνά το προεπιλεγμένο post_max_size (8M) της PHP. Το
		 * αίτημα τότε απορρίπτεται πριν καν φτάσει στο WordPress. Στα 1600px
		 * το κείμενο ενός λογαριασμού παραμένει άνετα αναγνώσιμο, και το
		 * αρχείο πέφτει 5-10 φορές -- που μετράει και στα δεδομένα του πελάτη.
		 *
		 * PDF και HEIC περνούν ως έχουν: το <img> δεν τα φορτώνει, οπότε η
		 * onerror τα στέλνει αυτούσια και ο server τα δέχεται κανονικά. */
		function shrink(file) {
			if (file.type.indexOf('image/') !== 0 || file.type === 'image/heic') { return toDataUrl(file); }
			return new Promise(function (res) {
				var img = new Image();
				var url = URL.createObjectURL(file);
				img.onload = function () {
					URL.revokeObjectURL(url);
					var max = 1600, w = img.naturalWidth, h = img.naturalHeight;
					if (w <= max && h <= max && file.size < 1200000) { toDataUrl(file).then(res); return; }
					var s = Math.min(1, max / Math.max(w, h));
					var cv = document.createElement('canvas');
					cv.width = Math.round(w * s); cv.height = Math.round(h * s);
					cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
					res(cv.toDataURL('image/jpeg', 0.82));
				};
				img.onerror = function () { URL.revokeObjectURL(url); toDataUrl(file).then(res); };
				img.src = url;
			});
		}

		document.getElementById('go').addEventListener('click', function () {
			var go = this;
			var phone = document.getElementById('ph').value.trim();
			if (phone.replace(/\D+/g, '').length < 10) { say('Συμπλήρωσε έγκυρο κινητό.', 'err'); return; }
			if (!document.getElementById('cs').checked) { say('Χρειάζεται η συναίνεσή σου για να προχωρήσουμε.', 'err'); return; }

			go.disabled = true;
			say('Αποστολή…', 'ok');

			/* Πρώτα τα στοιχεία επικοινωνίας: αν ο πελάτης τα παρατήσει στη
			   μέση του ανεβάσματος, ο συνεργάτης έχει ήδη ένα τηλέφωνο. */
			fetch(REST, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ phone: phone, consent: true })
			})
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.ok || !d.ref) { throw new Error((d && d.error) || 'Αποτυχία.'); }
					var all = files.provider_bill.map(function (f) { return { f: f, k: 'provider_bill' }; })
						.concat(files.id_card.map(function (f) { return { f: f, k: 'id_card' }; }));
					return all.reduce(function (chain, item, i) {
						return chain.then(function () {
							say('Ανέβασμα ' + (i + 1) + ' από ' + all.length + '…', 'ok');
							return shrink(item.f).then(function (data) {
								return fetch(REST + '/file', {
									method: 'POST',
									headers: { 'Content-Type': 'application/json' },
									body: JSON.stringify({ ref: d.ref, kind: item.k, data: data })
								});
							}).then(function (r) {
								/* Η πρώτη γραφή διάβαζε την απάντηση και ΔΕΝ την
								 * κοίταζε ποτέ. Κάθε αποτυχία ανεβάσματος περνούσε
								 * σιωπηλά και η σελίδα έλεγε «Τα λάβαμε» ενώ δεν
								 * είχε αποθηκευτεί τίποτα. Το έπιασε ο ιδιοκτήτης
								 * στην πρώτη ζωντανή δοκιμή, όχι κάποιο test:
								 * αυτή η σελίδα δεν έχει καμία αυτόματη κάλυψη. */
								if (r.status === 413) { throw new Error('Το αρχείο είναι πολύ μεγάλο για τον διακομιστή.'); }
								return r.json().then(function (res) {
									if (!res || !res.ok) { throw new Error((res && res.error) || 'Το αρχείο δεν ανέβηκε.'); }
								});
							});
						});
					}, Promise.resolve());
				})
				.then(showDone)
				.catch(function (e) {
					go.disabled = false;
					say((e && e.message) || 'Κάτι πήγε στραβά. Δοκίμασε ξανά.', 'err');
				});
		});
	})();
	</script>
<?php endif; ?>
</div>
</body>
</html>
		<?php
		exit;
	}

	public static function consent_text(): string {
		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : '';
		$who     = $company !== '' ? $company : 'την εταιρεία';

		return 'Συναινώ να διαβιβάσω τα στοιχεία και τα έγγραφά μου στην ' . $who .
			', αποκλειστικά για τον έλεγχο και την υποβολή αίτησης ενεργειακού συμβολαίου. ' .
			'Γνωρίζω ότι μπορώ να ζητήσω πρόσβαση, διόρθωση ή διαγραφή των δεδομένων μου οποτεδήποτε.';
	}
}
