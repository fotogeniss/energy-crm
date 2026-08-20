<?php
/**
 * Public application-tracking page (courier-style).
 *
 * The customer opens ?ecrm_track=TOKEN (no login) and sees a friendly,
 * stage-based progress view of their application — code, provider, energy
 * type, current stage, last update. No internal notes/events are exposed.
 *
 * Tokens are stateless: {id}-{hmac}. No DB column, cannot be enumerated or
 * forged without the site auth salt. ECRM_Tracking::url() builds the link,
 * and ECRM_Messaging exposes it to templates as {track}.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Tracking {

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( \EnergyCRM\Http\Router::NAMESPACE, '/track/(?P<token>[A-Za-z0-9\-]+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'rest_get' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( \EnergyCRM\Http\Router::NAMESPACE, '/track/(?P<token>[A-Za-z0-9\-]+)/sign', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_sign' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( \EnergyCRM\Http\Router::NAMESPACE, '/track/(?P<token>[A-Za-z0-9\-]+)/upload', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_upload' ],
			'permission_callback' => '__return_true',
		] );
	}

	// --- Token ανά σύμβαση, ανακλητό ----------------------------------------

	/**
	 * Ο σύνδεσμος μιας σύμβασης. Παράγει κλειδί αν δεν υπάρχει ήδη.
	 *
	 * Ως τις 19/08/2026 το token ήταν `{id}-{hmac(id, salt)}`: καθαρό, χωρίς
	 * βάση, μη απαριθμήσιμο — και **αιώνιο**. Ο ίδιος σύνδεσμος για την ίδια
	 * σύμβαση, για πάντα, και ο μόνος τρόπος να ακυρωθεί ήταν να αλλάξει το
	 * `wp_salt('auth')`, που ακυρώνει όλους και πετάει έξω κάθε συνδεδεμένο
	 * χρήστη του WordPress.
	 *
	 * Τώρα στο υλικό του HMAC μπαίνει και ένα κλειδί που ανήκει στη σύμβαση.
	 * Ανάκληση ενός συνδέσμου = νέο κλειδί σε μία γραμμή· όλοι οι άλλοι ζουν.
	 *
	 * Χάθηκε η ιδιότητα «δεν αγγίζει τη βάση» — μία ανάγνωση με πρωτεύον
	 * κλειδί. Είναι το τίμημα της ανάκλησης, και είναι το ζητούμενο.
	 */
	public static function token( int $id ): string {
		$key = self::key_for( $id );
		return $key === '' ? '' : self::sign( $id, $key );
	}

	/**
	 * Το id πίσω από ένα token, ή null.
	 *
	 * **Ποτέ δεν παράγει κλειδί.** Η διαδρομή είναι ανώνυμη και δημόσια· αν
	 * παρήγαγε, μια αίτηση σε τυχαίο id θα προκαλούσε εγγραφή στη βάση, δηλαδή
	 * θα έδινε σε οποιονδήποτε τρόπο να γεμίσει τον πίνακα. Σύμβαση χωρίς
	 * κλειδί δεν έχει έγκυρο σύνδεσμο, τελεία.
	 */
	public static function verify( string $token ): ?int {
		if ( ! preg_match( '/^(\d+)-([a-f0-9]{20})$/', $token, $m ) ) {
			return null;
		}
		$id  = (int) $m[1];
		$key = self::stored_key( $id );

		return $key !== '' && hash_equals( self::sign( $id, $key ), $token ) ? $id : null;
	}

	/**
	 * Ακυρώνει τον σύνδεσμο μιας σύμβασης.
	 *
	 * Ο επόμενος που θα ζητήσει σύνδεσμο παίρνει καινούργιο· ο παλιός δεν
	 * ξαναδουλεύει ποτέ.
	 */
	public static function revoke( int $id ): void {
		global $wpdb;
		$ct = ECRM_DB::table( 'contracts' );
		// Ρητό ερώτημα και όχι $wpdb->update(): εκείνη δεν γράφει NULL — τα
		// null στα δεδομένα τα προσπερνά, οπότε η ανάκληση θα «πετύχαινε»
		// αφήνοντας το κλειδί στη θέση του. Σιωπηλή αποτυχία σε λειτουργία
		// ασφαλείας είναι το χειρότερο είδος.
		$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET track_key = NULL WHERE id = %d", $id ) );
	}

	private static function sign( int $id, string $key ): string {
		$sig = substr( hash_hmac( 'sha256', 'ecrm_track_' . $id . '|' . $key, wp_salt( 'auth' ) ), 0, 20 );
		return $id . '-' . $sig;
	}

	/** Το αποθηκευμένο κλειδί, χωρίς να παραχθεί τίποτα. */
	private static function stored_key( int $id ): string {
		global $wpdb;
		if ( $id <= 0 ) {
			return '';
		}
		$ct = ECRM_DB::table( 'contracts' );
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT track_key FROM {$ct} WHERE id = %d", $id ) );
	}

	/**
	 * Το κλειδί της σύμβασης, φτιαγμένο αν λείπει.
	 *
	 * Η διεκδίκηση είναι μέσα στο `UPDATE`, με το `track_key IS NULL` ως
	 * συνθήκη, και μετά ξαναδιαβάζεται ό,τι όντως γράφτηκε. Ο λόγος δεν είναι
	 * θεωρητικός: το `ContractsReadController` χτίζει `track_url` σε **κάθε**
	 * άνοιγμα καρτέλας, οπότε δύο ταυτόχρονα αιτήματα θα έγραφαν δύο κλειδιά
	 * και ο σύνδεσμος του πρώτου θα έπαυε να ισχύει σιωπηλά — ακριβώς το σχήμα
	 * που έκλεισε στη δημιουργία εκκαθάρισης.
	 */
	private static function key_for( int $id ): string {
		global $wpdb;
		if ( $id <= 0 ) {
			return '';
		}

		$existing = self::stored_key( $id );
		if ( $existing !== '' ) {
			return $existing;
		}

		$ct = ECRM_DB::table( 'contracts' );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$ct} SET track_key = %s WHERE id = %d AND ( track_key IS NULL OR track_key = '' )",
			wp_generate_password( 24, false ),
			$id
		) );

		return self::stored_key( $id );
	}

	public static function url( int $id ): string {
		$token = self::token( $id );
		return $token === '' ? '' : add_query_arg( 'ecrm_track', $token, home_url( '/' ) );
	}

	// --- Customer-friendly stages -------------------------------------------

	/** Ordered journey shown to the customer. */
	public static function stages(): array {
		return [ 'Καταχώρηση', 'Προς υπογραφή', 'Σε επεξεργασία', 'Δρομολόγηση', 'Ενεργοποίηση' ];
	}

	/** Statuses where the customer is allowed to e-sign from the tracking link. */
	public static function signable_statuses(): array {
		return [ 'pending_signature', 'awaiting_signature' ];
	}

	/** Map an internal status to a stage index (0-based) or -1 if cancelled. */
	public static function stage_index( string $status ): int {
		$map = [
			'draft'              => 0,
			'new'                => 0,
			'pending_signature'  => 1,
			'awaiting_signature' => 1, // System-A "sign-link" status — same stage.
			'signed'             => 2, // Customer signed → moves on to processing.
			'processing'         => 2,
			'pending'           => 2,
			'resolved'          => 2,
			'routed'            => 3,
			'active'            => 4,
		];
		if ( in_array( $status, [ 'cancelled', 'terminated' ], true ) ) {
			return -1;
		}
		return $map[ $status ] ?? 0;
	}

	/** Allowed upload MIME types → file extension. */
	public static function upload_types(): array {
		return [
			'image/jpeg'      => 'jpg',
			'image/png'       => 'png',
			'image/webp'      => 'webp',
			'image/heic'      => 'heic',
			'application/pdf' => 'pdf',
		];
	}

	/**
	 * Build the customer-facing document payload for a contract: the required
	 * checklist (kind, label, done) plus whether uploads are currently accepted.
	 *
	 * @return array{items:array,complete:bool,can_upload:bool}
	 */
	public static function docs_payload( int $id, string $status, ?string $activation_type ): array {
		$can = ! in_array( $status, [ 'cancelled', 'terminated', 'active' ], true );
		if ( ! class_exists( 'ECRM_Docs' ) ) {
			return [ 'items' => [], 'complete' => true, 'can_upload' => $can ];
		}
		$cl = ECRM_Docs::checklist( $id, (string) $activation_type );
		$items = [];
		foreach ( $cl['items'] as $it ) {
			$items[] = [ 'kind' => $it['kind'], 'label' => $it['label'], 'done' => ! empty( $it['ok'] ) ];
		}
		return [ 'items' => $items, 'complete' => ! empty( $cl['complete'] ), 'can_upload' => $can ];
	}

	// --- REST: tracking payload ---------------------------------------------

	public static function rest_get( WP_REST_Request $req ): WP_REST_Response {
		if ( class_exists( 'ECRM_RateLimit' ) && ! ECRM_RateLimit::allow( 'track', 60, 300 ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'too_many' ], 429 );
		}
		$id = self::verify( (string) $req['token'] );
		if ( ! $id ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_token' ], 404 );
		}
		global $wpdb;
		$ct = ECRM_DB::table( 'contracts' );
		$cu = ECRM_DB::table( 'customers' );
		$pr = ECRM_DB::table( 'providers' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.code, c.status, c.energy_type, c.activation_type, c.updated_at, c.created_at, c.signed_at,
			        p.name AS provider_name,
			        cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c
			 LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 LEFT JOIN {$pr} p  ON p.id  = c.provider_id
			 WHERE c.id = %d", $id
		), ARRAY_A );
		if ( ! $row ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'not_found' ], 404 );
		}

		$status = (string) $row['status'];
		$idx    = self::stage_index( $status );
		$labels = ECRM_DB::statuses();
		$name   = $row['company_name'] ?: trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );

		return new WP_REST_Response( [
			'ok'           => true,
			'code'         => $row['code'] ?: '—',
			'provider'     => $row['provider_name'] ?: '—',
			'energy'       => ECRM_DB::energy_label( (string) $row['energy_type'] ),
			'customer'     => $name ?: '—',
			'status_label' => $labels[ $status ] ?? $status,
			'cancelled'    => ( $idx < 0 ),
			'stage'        => max( 0, $idx ),
			'stages'       => self::stages(),
			'updated_at'   => $row['updated_at'],
			'created_at'   => $row['created_at'],
			'signed'       => ! empty( $row['signed_at'] ),
			'can_sign'     => ( in_array( $status, self::signable_statuses(), true ) && empty( $row['signed_at'] ) ),
			'docs'         => self::docs_payload( $id, $status, $row['activation_type'] ?? '' ),
			'company'      => class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : '',
		], 200 );
	}

	// --- REST: customer e-signature ----------------------------------------

	public static function rest_sign( WP_REST_Request $req ): WP_REST_Response {
		if ( class_exists( 'ECRM_RateLimit' ) && ! ECRM_RateLimit::allow( 'track_sign', 10, 600 ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Πολλές προσπάθειες. Δοκιμάστε αργότερα.' ], 429 );
		}
		$id = self::verify( (string) $req['token'] );
		if ( ! $id ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Μη έγκυρος σύνδεσμος.' ], 404 );
		}
		$p       = $req->get_json_params() ?: $req->get_params();
		$consent = ! empty( $p['consent'] );
		$dataurl = (string) ( $p['signature'] ?? '' );
		if ( ! $consent ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Απαιτείται η συναίνεσή σας.' ], 400 );
		}
		if ( ! preg_match( '#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataurl, $m ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Μη έγκυρη υπογραφή.' ], 400 );
		}
		$png = base64_decode( $m[1], true );
		if ( $png === false || strlen( $png ) < 200 || strlen( $png ) > 800000 || substr( $png, 0, 8 ) !== "\x89PNG\r\n\x1a\n" ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Μη έγκυρη υπογραφή.' ], 400 );
		}

		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ct} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Δεν βρέθηκε η αίτηση.' ], 404 );
		}
		if ( ! in_array( $row['status'], self::signable_statuses(), true ) || ! empty( $row['signed_at'] ) ) {
			return new WP_REST_Response( [ 'ok' => true, 'already' => true, 'message' => 'Η αίτηση δεν εκκρεμεί για υπογραφή ή έχει ήδη υπογραφεί.' ], 200 );
		}

		$now = current_time( 'mysql' );
		$ip  = \EnergyCRM\Infrastructure\RequestIp::current();

		// Persist the signature image (protected).
		$sig_path = '';
		if ( class_exists( 'ECRM_Files' ) ) {
			$stored = ECRM_Files::put_bytes( $png, 'png', 'image/png', 'signature.png' );
			if ( $stored ) {
				$sig_path = $stored['path'];
				// Μέσω FileRepository και όχι με σκέτο insert. Μια σύμβαση έχει ΜΙΑ
				// υπογραφή, και η replaceKind() σβήνει πρώτα τα bytes της παλιάς·
				// ένα insert εδώ θα άφηνε το προηγούμενο σχέδιο στον δίσκο χωρίς
				// τίποτα να το δείχνει — ακριβώς η διαρροή για την οποία γράφτηκε
				// ο FileRepository, και που ως τώρα την απέτρεπε μόνο ο έλεγχος
				// signed_at δύο οθόνες πιο πάνω.
				\EnergyCRM\Services::files()->replaceKind(
					$id, 'signature', 'signature.png', 'image/png', $stored['path']
				);
			}
		}

		// Build a signed contract PDF (full customer + application data + signature).
		//
		// The joined row comes from the repository rather than from a fourth copy
		// of the same query written here. The copy that used to sit in this spot
		// selected the encrypted customer columns and handed them straight to the
		// renderer, so with ECRM_ENCRYPT_PII on this document — the signed one —
		// printed ciphertext where the ΑΦΜ belongs.
		if ( class_exists( 'ECRM_PDF' ) ) {
			$full = \EnergyCRM\Services::contracts()->detailedForDocument( $id );
			try {
				$pdf = ECRM_PDF::build( $full ?: $row, $sig_path ?: null, [ 'date' => gmdate( 'd/m/Y H:i' ), 'ip' => $ip ] );
				if ( $pdf && class_exists( 'ECRM_Files' ) ) {
					$sp = ECRM_Files::put_bytes( $pdf, 'pdf', 'application/pdf', 'symvasi-ypografi.pdf' );
					if ( $sp ) {
						\EnergyCRM\Services::files()->replaceKind(
							$id,
							'signed_pdf',
							( $row['code'] ?: ( 'symvasi-' . $id ) ) . '-ypografi.pdf',
							'application/pdf',
							$sp['path']
						);
					}
				}
			} catch ( \Throwable $e ) { /* PDF optional — signing still succeeds */ }
		}

		// Advance status + record the signature audit fields, via the centralized
		// lifecycle (logs the event and fires the configured customer messaging).
		//
		// The hand-rolled fallback that used to sit here, for the case where
		// ECRM_REST was missing, is gone: it wrote a different event type for the
		// same act, so the one path nobody could reach was also the only one that
		// logged it wrong.
		\EnergyCRM\Services::lifecycle()->moveTo( $id, 'signed', [
			'from'    => (string) $row['status'],
			'message' => 'Ο πελάτης υπέγραψε ηλεκτρονικά από τον σύνδεσμο παρακολούθησης' . ( $ip ? ' (IP ' . $ip . ')' : '' ),
			'extra'   => [ 'signed_at' => $now, 'signed_ip' => $ip ],
			'inapp'   => false, // The contractNotices() call below handles the in-app notification.
		] );

		// Rebuild the attached provider form, so the stored copy reflects the
		// contract as it stands at the moment of signing.
		\EnergyCRM\Services::contractDocuments()->store( $id );

		\EnergyCRM\Services::contractNotices()->signed( $id, (string) ( $row['first_name'] ?? '' ) );

		// Notify the seller.
		if ( ! empty( $row['partner_user_id'] ) ) {
			$u = get_userdata( (int) $row['partner_user_id'] );
			if ( $u && is_email( $u->user_email ) ) {
				wp_mail(
					$u->user_email,
					sprintf( '✍️ Υπεγράφη: %s', $row['code'] ),
					sprintf( "Ο πελάτης υπέγραψε ηλεκτρονικά τη σύμβαση %s.\nΗ κατάσταση προχώρησε σε «Υπεγράφη».", $row['code'] )
				);
			}
		}

		return new WP_REST_Response( [ 'ok' => true, 'message' => 'Ευχαριστούμε! Η υπογραφή καταχωρήθηκε.' ], 200 );
	}

	// --- REST: customer document upload ------------------------------------

	public static function rest_upload( WP_REST_Request $req ): WP_REST_Response {
		if ( class_exists( 'ECRM_RateLimit' ) && ! ECRM_RateLimit::allow( 'track_upload', 30, 600 ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Πολλές προσπάθειες. Δοκιμάστε αργότερα.' ], 429 );
		}
		$id = self::verify( (string) $req['token'] );
		if ( ! $id ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_token' ], 404 );
		}

		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, activation_type FROM {$ct} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'not_found' ], 404 );
		}
		if ( in_array( $row['status'], [ 'cancelled', 'terminated', 'active' ], true ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Η αίτηση δεν δέχεται πλέον έγγραφα.' ], 400 );
		}

		$p    = $req->get_json_params() ?: $req->get_params();
		$kind = sanitize_key( (string) ( $p['kind'] ?? 'other' ) );
		$catalog = class_exists( 'ECRM_Docs' ) ? ECRM_Docs::kinds() : [ 'other' => 'Άλλο έγγραφο' ];
		if ( ! array_key_exists( $kind, $catalog ) ) { $kind = 'other'; }

		$data  = (string) ( $p['data'] ?? '' );
		$fname = sanitize_file_name( (string) ( $p['filename'] ?? '' ) );

		if ( ! preg_match( '#^data:([a-zA-Z0-9./+\-]+);base64,#', $data, $m ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Μη έγκυρο αρχείο.' ], 400 );
		}
		$mime    = strtolower( $m[1] );
		$allowed = self::upload_types();
		if ( ! isset( $allowed[ $mime ] ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Επιτρέπονται μόνο JPG, PNG, WEBP, HEIC ή PDF.' ], 400 );
		}
		$bin = base64_decode( substr( $data, strlen( $m[0] ) ), true );
		if ( $bin === false || strlen( $bin ) < 64 ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Το αρχείο είναι κατεστραμμένο.' ], 400 );
		}
		if ( strlen( $bin ) > 12 * 1024 * 1024 ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Το αρχείο είναι πολύ μεγάλο (μέγιστο 12MB).' ], 400 );
		}
		// Magic-byte sanity so the declared MIME can't lie about the payload.
		$sig_ok = true;
		if ( $mime === 'application/pdf' ) {
			$sig_ok = ( substr( $bin, 0, 5 ) === '%PDF-' );
		} elseif ( $mime === 'image/png' ) {
			$sig_ok = ( substr( $bin, 0, 8 ) === "\x89PNG\r\n\x1a\n" );
		} elseif ( $mime === 'image/jpeg' ) {
			$sig_ok = ( substr( $bin, 0, 3 ) === "\xFF\xD8\xFF" );
		}
		// webp/heic: trust the whitelist + size check.
		if ( ! $sig_ok ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Ο τύπος αρχείου δεν ταιριάζει.' ], 400 );
		}

		if ( ! class_exists( 'ECRM_Files' ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Σφάλμα αποθήκευσης.' ], 500 );
		}
		$ext    = $allowed[ $mime ];
		$stored = ECRM_Files::put_bytes( $bin, $ext, $mime, ( $fname ?: ( $kind . '.' . $ext ) ) );
		if ( ! $stored ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Σφάλμα αποθήκευσης.' ], 500 );
		}

		$wpdb->insert( ECRM_DB::table( 'files' ), [
			'contract_id'   => $id,
			'attachment_id' => null,
			'doc_kind'      => $kind,
			'filename'      => $stored['filename'],
			'mime'          => $stored['mime'],
			'path'          => $stored['path'],
			'protected'     => 1,
		] );

		$label = $catalog[ $kind ] ?? 'έγγραφο';
		$wpdb->insert( ECRM_DB::table( 'events' ), [
			'contract_id' => $id, 'user_id' => 0, 'type' => 'note',
			'message'     => 'Ο πελάτης ανέβασε δικαιολογητικό: ' . $label,
		] );
		\EnergyCRM\Services::contractNotices()->documentUploaded( $id, $label );

		return new WP_REST_Response( [
			'ok'      => true,
			'message' => 'Το έγγραφο ανέβηκε. Ευχαριστούμε!',
			'docs'    => self::docs_payload( $id, (string) $row['status'], $row['activation_type'] ?? '' ),
		], 200 );
	}

	// --- Public page --------------------------------------------------------

	/**
	 * What the customer ticks before signing.
	 *
	 * The previous wording covered "processing for concluding the contract",
	 * which is true and incomplete: the photograph of their ID card and their
	 * bill is sent to an external AI service that reads the ΑΦΜ, ΑΔΤ, date of
	 * birth and address off it, so the application can be filled in without
	 * retyping. A recipient the customer is never told about is exactly what
	 * Articles 13–14 exist to prevent, and consent given without knowing it
	 * is not informed consent.
	 *
	 * Filterable because the final wording is the company lawyer's call, not
	 * the developer's — and because a wording change should never require
	 * editing a template.
	 */
	public static function consent_text(): string {
		$default = 'Συναινώ στην επεξεργασία των προσωπικών μου δεδομένων για τη σύναψη/διαχείριση '
			. 'της σύμβασης και αποδέχομαι την ηλεκτρονική υπογραφή ως δεσμευτική. Ενημερώθηκα ότι τα '
			. 'έγγραφα που υποβάλλονται (ταυτότητα, λογαριασμός) αναγνωρίζονται αυτόματα από εξωτερική '
			. 'υπηρεσία τεχνητής νοημοσύνης για τη συμπλήρωση της αίτησης.';

		return (string) apply_filters( 'ecrm_consent_text', $default );
	}

	public static function maybe_render(): void {
		$token = isset( $_GET['ecrm_track'] ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', wp_unslash( $_GET['ecrm_track'] ) ) : '';
		if ( ! $token ) {
			return;
		}
		$rest    = esc_url_raw( rest_url( \EnergyCRM\Http\Router::NAMESPACE . '/track/' . $token ) );
		$accent  = class_exists( 'ECRM_Admin' )
			? (string) ECRM_Admin::get( 'accent_color', ECRM_Admin::DEFAULT_ACCENT )
			: '#0e8610';
		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : '';

		// Verify the HMAC token here (server-side) so we can distinguish a genuinely
		// invalid/expired link (bad signature — e.g. site salts changed) from a
		// reachability problem (REST API blocked for anonymous visitors).
		$token_ok = ( self::verify( $token ) !== null );

		$consent_text = self::consent_text();

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Παρακολούθηση Αίτησης</title>
<?php // Η γραμματοσειρά σερβίρεται ΑΠΟ ΤΟ PLUGIN. Εδώ έμπαινε Inter από το CDN
// της Google — σε σελίδα που βλέπει ο ΠΕΛΑΤΗΣ, μέσα σε ροή προσωπικών
// δεδομένων. Δες EnergyCRM\Infrastructure\LocalFonts και CHANGELOG (15).
echo \EnergyCRM\Infrastructure\LocalFonts::styleTag( ECRM_URL ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- σταθερό CSS με URL που φτιάχνει η ίδια η κλάση.
?>
<style>
	/* ---- Ταυτότητα 2026-08-18 -----------------------------------------
	 * Ήταν navy #0a1f3d με amber gradient: η παλέτα ΠΡΙΝ από το restyle των
	 * πέντε βημάτων, που δεν άγγιξε ποτέ τις σελίδες του πελάτη. Ο πελάτης που
	 * υπέγραφε έβλεπε άλλο προϊόν από τον συνεργάτη που του πούλησε.
	 *
	 * Το --accent έρχεται από τις ρυθμίσεις, με προεπιλογή ECRM_Admin::DEFAULT_ACCENT. */
	:root {
		--accent: <?php echo esc_html( $accent ?: '#0e8610' ); ?>;
		--page:#141412; --chrome:#1a1a18; --surface:#fff;
		--ink:#2a2926; --ink2:#5c5a55; --ink3:#a3a099;
		--line:#e9e8e4; --line2:#dedcd7; --fill:#f8f8f6;
		--ok:#0f5f29; --ok-bg:#e1f0e6; --err:#c42a47; --err-bg:#fceaee;
	}
	* { box-sizing: border-box; }
	body { margin:0; font-family:<?php echo \EnergyCRM\Infrastructure\LocalFonts::STACK; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- σταθερά κλάσης, όχι είσοδος. Το esc_html() θα μετέτρεπε τα εισαγωγικά του «"Manrope"» σε &quot; και θα ΕΣΠΑΓΕ το CSS. ?>;
		background:var(--page); font-size:13px; min-height:100vh; min-height:100dvh;
		display:flex; align-items:center; justify-content:center; padding:20px; color:var(--ink); }
	.card { background:var(--surface); border-radius:12px; box-shadow:0 24px 60px -24px rgba(0,0,0,.55);
		width:100%; max-width:520px; overflow:hidden; }
	.head { background:var(--chrome); color:#fff; padding:14px 18px; }
	.head h1 { margin:0; font-size:15px; font-weight:700; letter-spacing:-.02em; }
	.head p { margin:4px 0 0; color:var(--ink3); font-size:12px; }
	.body { padding:16px 18px; }
	.row { display:flex; justify-content:space-between; gap:14px; padding:7px 0; border-bottom:1px solid var(--line); font-size:13px; }
	.row:last-of-type { border-bottom:0; }
	.row span { color:var(--ink2); } .row b { color:var(--ink); font-weight:600; text-align:right; }
	.foot { text-align:center; font-size:11.5px; color:var(--ink3); padding:11px; border-top:1px solid var(--line); }
	.steps { list-style:none; margin:16px 0 4px; padding:0; }
	.steps li { display:flex; align-items:flex-start; gap:12px; padding-bottom:13px; position:relative; }
	.steps li:not(:last-child)::before { content:""; position:absolute; left:11px; top:24px; bottom:-2px; width:2px; background:var(--line); }
	.steps li.done:not(:last-child)::before { background:var(--accent); }
	.dot { flex:0 0 24px; width:24px; height:24px; border-radius:50%; background:var(--line); color:var(--ink3);
		display:grid; place-items:center; font-weight:700; font-size:12.5px; z-index:1; }
	.steps li.done .dot { background:var(--accent); color:#fff; }
	.steps li.current .dot { background:var(--chrome); color:#fff; box-shadow:0 0 0 4px rgba(26,26,24,.12); }
	.steps .lbl { padding-top:3px; font-size:13px; font-weight:600; color:var(--ink3); }
	.steps li.done .lbl, .steps li.current .lbl { color:var(--ink); }
	/* ΟΥΔΕΤΕΡΟ, όχι πράσινο: το badge δείχνει όποια κατάσταση κι αν είναι, και
	   «Εκκρεμότητα» σε πράσινο φόντο λέει «όλα καλά». Την πρόοδο τη δείχνει η
	   λίστα βημάτων από κάτω· εδώ χρειάζεται μόνο ονομασία. Η ακύρωση κρατά το
	   δικό της κόκκινο, γιατί εκεί το χρώμα ΕΙΝΑΙ η πληροφορία. */
	.badge { display:inline-block; margin-top:4px; padding:3px 10px; border-radius:999px; font-size:11.5px;
		font-weight:700; background:var(--fill); color:var(--ink); border:1px solid var(--line2); }
	.badge.cancel { background:var(--err-bg); color:var(--err); }
	.cancel-box { text-align:center; padding:22px 10px; }
	.cancel-box .x { width:46px; height:46px; border-radius:50%; background:var(--err-bg); color:var(--err);
		display:grid; place-items:center; font-size:22px; margin:0 auto 12px; }
	.sign { margin-top:16px; border-top:1px solid var(--line); padding-top:14px; }
	.sign h2 { font-size:13px; margin:0 0 4px; color:var(--ink); font-weight:700; }
	.sign p.lead { margin:0 0 12px; font-size:12.5px; color:var(--ink2); }
	.pad { border:2px dashed var(--line2); border-radius:8px; background:var(--fill); touch-action:none;
		width:100%; height:150px; display:block; }
	.padbar { display:flex; justify-content:space-between; align-items:center; margin-top:8px; }
	.padbar small { color:var(--ink3); font-size:12px; }
	.btn { border:0; border-radius:8px; padding:11px 18px; font-size:14px; font-weight:600; cursor:pointer; font-family:inherit; }
	.btn--clear { background:var(--fill); color:var(--ink2); padding:6px 11px; font-size:11.5px; border:1px solid var(--line2); }
	.btn--clear:hover { border-color:var(--ink3); }
	.btn--sign { background:var(--accent); color:#fff; width:100%; margin-top:14px; height:46px; font-size:14px; font-weight:700; transition:filter .15s; }
	.btn--sign:hover:not(:disabled) { filter:brightness(1.08); }
	.btn--sign:disabled { opacity:.5; cursor:not-allowed; }
	.consent { display:flex; gap:10px; align-items:flex-start; margin-top:14px; font-size:12px; color:var(--ink2); line-height:1.5; }
	.consent input { margin-top:1px; flex:0 0 18px; width:18px; height:18px; accent-color:var(--accent); }
	.signed-ok { text-align:center; padding:14px 10px; }
	.signed-ok .v { width:44px; height:44px; border-radius:50%; background:var(--ok-bg); color:var(--ok);
		display:grid; place-items:center; font-size:20px; margin:0 auto 12px; }
	.signed-ok p { color:var(--ok); font-weight:700; margin:0; font-size:14px; }
	.docs { margin-top:16px; border-top:1px solid var(--line); padding-top:14px; }
	.docs h2 { font-size:13px; margin:0 0 4px; color:var(--ink); font-weight:700; }
	.docs p.lead { margin:0 0 12px; font-size:12.5px; color:var(--ink2); }
	.doc-item { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 0; border-bottom:1px solid var(--line); }
	.doc-item:last-child { border-bottom:0; }
	.doc-lbl { font-size:13px; font-weight:600; color:var(--ink); }
	.doc-ok { display:inline-flex; align-items:center; gap:6px; color:var(--ok); font-weight:700; font-size:13px; }
	.up-btn { border:1px solid var(--line2); background:var(--surface); color:var(--ink); border-radius:8px;
		padding:6px 11px; font-size:12px; font-weight:600; cursor:pointer; font-family:inherit; }
	.up-btn:hover { border-color:var(--ink3); }
	.up-btn.is-busy { opacity:.6; pointer-events:none; }
	.doc-msg { font-size:12px; margin-top:8px; min-height:16px; color:var(--ink3); }
	.doc-msg.is-ok { color:var(--ok); }
	.doc-msg.is-err { color:var(--err); }
	/* Η παράγραφος της ακύρωσης: είχε το slate #64748b της παλιάς ταυτότητας
	   γραμμένο μέσα στη render(), όπου κανένας φύλακας δεν το βλέπει — ο
	   ColourIsDecidedInOnePlaceTest σαρώνει public/assets/*.css, όχι public/*.php. */
	.cancel-box .quiet { margin:12px 0 0; font-size:13.5px; color:var(--ink2); }
	/* Ο άκυρος σύνδεσμος: ΟΥΔΕΤΕΡΟΣ, όχι κόκκινος. Ο πελάτης που πάτησε παλιό link
	   δεν έκανε λάθος και δεν έχει πρόβλημα ασφαλείας — έχει σύνδεσμο που δεν ισχύει.
	   Το κόκκινο μένει εκεί που το χρώμα ΕΙΝΑΙ η πληροφορία: στην ακυρωμένη αίτηση.
	   Το ΚΕΙΜΕΝΟ δεν άλλαξε. Τα δύο διαφορετικά μηνύματα (άκυρο token / δεν βρέθηκε)
	   δεν διαρρέουν τίποτα: και τα δύο γυρίζουν 404 και το δεύτερο απαιτεί έγκυρο
	   HMAC για να εμφανιστεί. Ολόκληρο το σκεπτικό στο docs/UI-SIGN.html §1. */
	.fail { text-align:center; padding:22px 10px; }
	.fail__i { width:46px; height:46px; border-radius:50%; background:var(--fill); color:var(--ink3);
		border:1px solid var(--line2); display:grid; place-items:center; font-size:22px; margin:0 auto 12px; }
	.fail p { margin:0; font-size:13.5px; color:var(--ink2); line-height:1.5; }
	.fail code { display:block; margin-top:10px; font-size:11px; color:var(--ink3);
		font-family:ui-monospace,Menlo,Consolas,monospace; }
	/* Πυκνότητα για ποντίκι, στόχος για αντίχειρα — ο κανόνας §1.9. Ίδιο μοτίβο με
	   τα 36->44px input της (65): ο σχεδιασμός ξεκινά από desktop, η λειτουργία δεν
	   επιτρέπεται να σπάσει πουθενά. */
	@media (max-width: 560px) {
		.pad { height:180px; }
		.up-btn, .btn--clear { min-height:40px; }
	}
</style>
</head>
<body>
<div class="card">
	<div class="head">
		<h1>Παρακολούθηση Αίτησης</h1>
		<p><?php echo esc_html( $company ?: 'Energy CRM' ); ?></p>
	</div>
	<div id="content" class="body"><p>Φόρτωση…</p></div>
	<div class="foot">Ασφαλής σύνδεσμος παρακολούθησης</div>
</div>

<script>
(function(){
	var REST = <?php echo wp_json_encode( $rest ); ?>;
	var TOKEN_OK = <?php echo $token_ok ? 'true' : 'false'; ?>;
	var CONSENT_TEXT = <?php echo wp_json_encode( $consent_text ); ?>;
	var SIGN = REST + '/sign';
	var content = document.getElementById('content');
	// Το μελάνι της υπογραφής, από το ίδιο --ink του :root. ΔΕΝ ξαναγράφεται εδώ:
	// ο καμβάς γίνεται PNG που μπαίνει στο υπογεγραμμένο PDF, άρα αυτή η τιμή
	// ΑΠΟΘΗΚΕΥΕΤΑΙ μέσα στο έγγραφο. Μέχρι τις 20/08 ήταν σκληροκωδικοποιημένο το
	// navy #0a1f3d — η ταυτότητα που ξηλώθηκε από όλο το plugin στις 17-19/8.
	var INK = (getComputedStyle(document.documentElement).getPropertyValue('--ink') || '').trim() || '#2a2926';

	function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
	function fmt(s){ if(!s) return '—'; var d=new Date(s.replace(' ','T')+'Z'); if(isNaN(d)) return s; return d.toLocaleDateString('el-GR')+' '+d.toLocaleTimeString('el-GR',{hour:'2-digit',minute:'2-digit'}); }

	function fail(msg, code){
		content.innerHTML = '<div class="fail"><div class="fail__i">!</div><p>' + esc(msg) + '</p>' +
			(code ? '<code>κωδ. ' + esc(code) + '</code>' : '') + '</div>';
	}

	function load(){
		// If the link itself didn't pass the server-side signature check, it's
		// genuinely invalid/expired — no point hitting the API.
		if (!TOKEN_OK) {
			fail('Ο σύνδεσμος δεν είναι έγκυρος ή έληξε. Ζητήστε νέο σύνδεσμο από τον συνεργάτη σας.', 'BAD_LINK');
			return;
		}
		fetch(REST, { headers: { 'Accept': 'application/json' } })
			.then(function(r){
				return r.json().catch(function(){ return null; }).then(function(d){ return { http: r.status, d: d }; });
			})
			.then(function(res){
				var d = res.d, http = res.http;
				if (d && d.ok) { render(d); return; }

				// The token is valid (checked above), so a non-OK here is almost
				// always the server blocking the public endpoint, not a bad link.
				if (http === 401 || http === 403 || (d && /^rest_/.test(d.code || ''))) {
					fail('Δεν ήταν δυνατή η σύνδεση με τον διακομιστή. Δοκιμάστε ξανά ή επικοινωνήστε μαζί μας.', 'REST_' + http);
				} else if (http === 429 || (d && d.error === 'too_many')) {
					fail('Πολλές προσπάθειες σε σύντομο διάστημα. Δοκιμάστε ξανά σε λίγα λεπτά.', 'RATE');
				} else if (d && d.error === 'not_found') {
					fail('Η αίτηση δεν βρέθηκε. Ενδέχεται να έχει διαγραφεί.', 'NOT_FOUND');
				} else if (d && d.error === 'invalid_token') {
					fail('Ο σύνδεσμος δεν είναι έγκυρος ή έληξε.', 'INVALID');
				} else {
					fail('Παρουσιάστηκε πρόβλημα κατά τη φόρτωση. Δοκιμάστε ξανά.', 'HTTP_' + (http || '0'));
				}
			})
			.catch(function(){ fail('Σφάλμα δικτύου. Ελέγξτε τη σύνδεσή σας και δοκιμάστε ξανά.', 'NET'); });
	}
	load();

	function render(d){
		var info =
			'<div class="row"><span>Κωδικός</span><b>'+esc(d.code)+'</b></div>'+
			'<div class="row"><span>Πάροχος</span><b>'+esc(d.provider)+'</b></div>'+
			'<div class="row"><span>Υπηρεσία</span><b>'+esc(d.energy)+'</b></div>'+
			'<div class="row"><span>Πελάτης</span><b>'+esc(d.customer)+'</b></div>'+
			'<div class="row"><span>Τελευταία ενημέρωση</span><b>'+esc(fmt(d.updated_at))+'</b></div>';

		if(d.cancelled){
			content.innerHTML = info +
				'<div class="cancel-box"><div class="x">✕</div>'+
				'<div class="badge cancel">'+esc(d.status_label)+'</div>'+
				'<p class="quiet">Η αίτηση δεν είναι σε εξέλιξη. Για διευκρινίσεις επικοινωνήστε μαζί μας.</p></div>';
			return;
		}

		var stages = d.stages || [], cur = d.stage|0;
		var steps = '<ul class="steps">';
		for(var i=0;i<stages.length;i++){
			var cls = i<cur ? 'done' : (i===cur ? 'current' : '');
			var mark = i<cur ? '✓' : (i+1);
			steps += '<li class="'+cls+'"><span class="dot">'+mark+'</span><span class="lbl">'+esc(stages[i])+'</span></li>';
		}
		steps += '</ul>';

		var tail = '<div style="text-align:center"><span class="badge">'+esc(d.status_label)+'</span></div>';

		if (d.can_sign) {
			tail +=
				'<div class="sign">'+
					'<h2>Υπογραφή σύμβασης</h2>'+
					'<p class="lead">Υπογράψτε στο πλαίσιο με το δάχτυλο ή το ποντίκι για να ολοκληρωθεί η αίτησή σας.</p>'+
					'<canvas class="pad is-empty" id="pad"></canvas>'+
					'<div class="padbar"><small>Σχεδιάστε την υπογραφή σας παραπάνω</small><button type="button" class="btn btn--clear" id="clear">Καθαρισμός</button></div>'+
					'<label class="consent"><input type="checkbox" id="consent"> '+esc(CONSENT_TEXT)+'</label>'+
					'<button type="button" class="btn btn--sign" id="dosign" disabled>Υπογραφή & Αποστολή</button>'+
				'</div>';
		} else if (d.signed) {
			tail += '<div class="signed-ok"><div class="v">✓</div><p>Η σύμβαση υπεγράφη ηλεκτρονικά</p></div>';
		}

		tail += docsHtml(d.docs);

		content.innerHTML = info + steps + tail;
		if (d.can_sign) initPad();
		initDocs();
	}

	function docsHtml(docs){
		if (!docs || !docs.can_upload) return '';
		var items = docs.items || [];
		var html = '<div class="docs"><h2>Δικαιολογητικά</h2>'+
			'<p class="lead">Ανεβάστε τα απαραίτητα έγγραφα (φωτογραφία ή PDF). Συνδέονται αυτόματα με την αίτησή σας.</p>';
		for (var i=0;i<items.length;i++){
			var it = items[i];
			html += '<div class="doc-item"><span class="doc-lbl">'+esc(it.label)+'</span>'+
				(it.done
					? '<span class="doc-ok">✓ Ανέβηκε</span>'
					: '<button type="button" class="up-btn" data-kind="'+esc(it.kind)+'">Ανέβασμα</button>')+
				'</div>';
		}
		// Always allow a free-form extra document.
		html += '<div class="doc-item"><span class="doc-lbl">Άλλο έγγραφο</span>'+
			'<button type="button" class="up-btn" data-kind="other">Ανέβασμα</button></div>';
		html += '<div class="doc-msg" id="docmsg"></div>'+
			'<input type="file" id="docfile" accept="image/jpeg,image/png,image/webp,image/heic,application/pdf,.pdf,.jpg,.jpeg,.png,.webp,.heic" style="display:none">'+
			'</div>';
		return html;
	}

	function initDocs(){
		var input = document.getElementById('docfile');
		if (!input) return;
		var msg = document.getElementById('docmsg');
		var curKind = 'other', curBtn = null;
		function say(t, ok){ if(msg){ msg.textContent=t; msg.className = 'doc-msg' + (ok===false ? ' is-err' : (ok ? ' is-ok' : '')); } }

		content.querySelectorAll('.up-btn').forEach(function(b){
			b.addEventListener('click', function(){ curKind = b.getAttribute('data-kind') || 'other'; curBtn = b; input.value=''; input.click(); });
		});

		input.addEventListener('change', function(){
			var f = input.files && input.files[0];
			if (!f) return;
			if (f.size > 12*1024*1024) { say('Το αρχείο είναι πολύ μεγάλο (μέγιστο 12MB).', false); return; }
			if (curBtn) curBtn.classList.add('is-busy');
			say('Ανέβασμα…');
			var reader = new FileReader();
			reader.onload = function(){
				fetch(REST + '/upload', {
					method:'POST', headers:{'Content-Type':'application/json'},
					body: JSON.stringify({ kind: curKind, filename: f.name, data: reader.result })
				}).then(function(r){ return r.json().catch(function(){return null;}); })
				.then(function(res){
					if (curBtn) curBtn.classList.remove('is-busy');
					if (res && res.ok) { say(res.message || 'Το έγγραφο ανέβηκε.', true); load(); }
					else { say((res && res.error) || 'Αποτυχία ανεβάσματος.', false); }
				}).catch(function(){ if(curBtn) curBtn.classList.remove('is-busy'); say('Σφάλμα δικτύου.', false); });
			};
			reader.onerror = function(){ if(curBtn) curBtn.classList.remove('is-busy'); say('Δεν ήταν δυνατή η ανάγνωση του αρχείου.', false); };
			reader.readAsDataURL(f);
		});
	}

	function initPad(){
		var canvas = document.getElementById('pad');
		var clearBtn = document.getElementById('clear');
		var consent = document.getElementById('consent');
		var doBtn = document.getElementById('dosign');
		if(!canvas) return;

		// Hi-DPI sizing.
		var ratio = window.devicePixelRatio || 1;
		function resize(){
			var w = canvas.clientWidth, h = canvas.clientHeight;
			canvas.width = w * ratio; canvas.height = h * ratio;
			var c = canvas.getContext('2d'); c.scale(ratio, ratio);
			c.lineWidth = 2.2; c.lineCap = 'round'; c.lineJoin = 'round'; c.strokeStyle = INK;
		}
		resize();
		var ctx = canvas.getContext('2d');
		var drawing = false, empty = true, last = null;

		function pos(e){
			var r = canvas.getBoundingClientRect();
			var t = e.touches ? e.touches[0] : e;
			return { x: t.clientX - r.left, y: t.clientY - r.top };
		}
		function start(e){ e.preventDefault(); drawing = true; last = pos(e); }
		function move(e){
			if(!drawing) return; e.preventDefault();
			var p = pos(e);
			ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
			last = p; empty = false; canvas.classList.remove('is-empty');
			doBtn.disabled = !(consent.checked && !empty);
		}
		function end(){ drawing = false; }

		canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move);
		window.addEventListener('mouseup', end);
		canvas.addEventListener('touchstart', start, {passive:false});
		canvas.addEventListener('touchmove', move, {passive:false});
		canvas.addEventListener('touchend', end);

		clearBtn.addEventListener('click', function(){
			ctx.clearRect(0,0,canvas.width,canvas.height); empty = true;
			canvas.classList.add('is-empty'); doBtn.disabled = true;
		});
		consent.addEventListener('change', function(){ doBtn.disabled = !(consent.checked && !empty); });

		doBtn.addEventListener('click', function(){
			if(empty || !consent.checked) return;
			doBtn.disabled = true; doBtn.textContent = 'Αποστολή…';
			var data = canvas.toDataURL('image/png');
			fetch(SIGN, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ signature:data, consent:true }) })
				.then(function(r){ return r.json(); })
				.then(function(res){
					if(res && res.ok){ load(); }
					else { doBtn.disabled=false; doBtn.textContent='Υπογραφή & Αποστολή'; alert((res && res.error) || 'Αποτυχία.'); }
				})
				.catch(function(){ doBtn.disabled=false; doBtn.textContent='Υπογραφή & Αποστολή'; alert('Σφάλμα δικτύου.'); });
		});

		window.addEventListener('resize', function(){ /* keep simple: no redraw persistence */ });
	}
})();
</script>
</body>
</html>
		<?php
		exit;
	}
}
