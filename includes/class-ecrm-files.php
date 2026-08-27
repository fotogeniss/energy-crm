<?php
/**
 * Secure document storage.
 *
 * Customer documents (IDs, bills) must NOT be publicly reachable. This class:
 *   - keeps a protected upload dir (uploads/ecrm-secure) hardened with .htaccess
 *     + web.config so the web server refuses direct access;
 *   - serves files only through an authenticated REST endpoint that verifies a
 *     short-lived signed token AND that the requesting user may see the file's
 *     contract.
 *
 * Moving the legacy publicly-stored files into that dir used to live here too.
 * It is now automatic and batched — see EnergyCRM\Infrastructure\DocumentProtection
 * and UnprotectedDocuments::protectBatch().
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Files {

	const TTL = 3600; // signed-URL lifetime (seconds)

	/**
	 * Το ΕΝΑ σημείο που γεννά διαδρομές εγγράφων.
	 *
	 * Μέχρι τις 2026-08-23 αυτή η κλάση έχτιζε μόνη της το όνομα, σε δύο
	 * σημεία, με τη λογική του `DocumentStorage::newPath()` αντιγραμμένη —
	 * ακριβώς αυτό που το docblock εκείνης της κλάσης προειδοποιούσε ότι θα
	 * αποκλίνει. Και απέκλινε: ο κάδος `Y/m` μπήκε εκεί, και τα ανεβασμένα
	 * έγγραφα μαζί με τα παραγόμενα PDF θα έμεναν επίπεδα χωρίς να το πει
	 * κανείς. Τώρα υπάρχει μία εκδοχή του τι σημαίνει «νέα διαδρομή».
	 *
	 * Καινούριο αντικείμενο σε κάθε κλήση: η κλάση δεν κρατά κατάσταση και
	 * δεν αγγίζει βάση, οπότε δεν υπάρχει τίποτα να μοιραστεί.
	 */
	private static function storage(): \EnergyCRM\Persistence\DocumentStorage {
		return new \EnergyCRM\Persistence\DocumentStorage( self::dir() );
	}

	/** Absolute path to the protected storage dir (created + hardened on demand). */
	public static function dir(): string {
		$up   = wp_upload_dir();
		$dir  = trailingslashit( $up['basedir'] ) . 'ecrm-secure';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Harden (idempotent).
		$ht = $dir . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			file_put_contents( $ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
		$idx = $dir . '/index.php';
		if ( ! file_exists( $idx ) ) {
			file_put_contents( $idx, "<?php // Silence is golden.\n" ); // phpcs:ignore
		}
		$wc = $dir . '/web.config';
		if ( ! file_exists( $wc ) ) {
			file_put_contents( $wc, "<configuration><system.webServer><authorization><deny users=\"*\"/></authorization></system.webServer></configuration>\n" ); // phpcs:ignore
		}
		return $dir;
	}

	// --- Signed short-lived tokens ------------------------------------------

	public static function sign( int $file_id, int $uid, int $ttl = self::TTL ): string {
		$exp  = time() + $ttl;
		$data = $uid . ':' . $file_id . ':' . $exp;
		$sig  = substr( hash_hmac( 'sha256', 'ecrmfile:' . $data, wp_salt( 'auth' ) ), 0, 24 );
		return rtrim( strtr( base64_encode( $data . ':' . $sig ), '+/', '-_' ), '=' );
	}

	/** @return array{0:int,1:int}|null [uid, file_id] */
	public static function verify( string $token ): ?array {
		$raw = base64_decode( strtr( $token, '-_', '+/' ) );
		if ( ! $raw || substr_count( $raw, ':' ) !== 3 ) {
			return null;
		}
		list( $uid, $fid, $exp, $sig ) = explode( ':', $raw );
		$data  = $uid . ':' . $fid . ':' . $exp;
		$check = substr( hash_hmac( 'sha256', 'ecrmfile:' . $data, wp_salt( 'auth' ) ), 0, 24 );
		if ( ! hash_equals( $check, $sig ) ) {
			return null;
		}
		if ( (int) $exp < time() ) {
			return null;
		}
		return [ (int) $uid, (int) $fid ];
	}

	/** Build an authenticated, signed URL for a file id (bound to current user). */
	public static function url( int $file_id ): string {
		$uid = get_current_user_id();
		return add_query_arg(
			[ 't' => self::sign( $file_id, $uid ) ],
			rest_url( \EnergyCRM\Http\Router::NAMESPACE . '/file/' . $file_id )
		);
	}

	// --- Upload (protected) -------------------------------------------------

	/**
	 * Αποθηκεύει ένα ανεβασμένο αρχείο στον προστατευμένο φάκελο.
	 *
	 * Ο τύπος βγαίνει από τα bytes του αρχείου, όχι από όσα δηλώνει ο browser:
	 * το `$file['type']` το γράφει ο πελάτης και αλλάζει με ένα curl. Μέχρι τις
	 * 2026-08-18 το εμπιστευόμασταν, και μαζί του και την κατάληξη από το όνομα.
	 *
	 * @param array       $file          Μία εγγραφή του $_FILES (tmp_name, name, type).
	 * @param array       $allowed_mimes Τι δεχόμαστε.
	 * @param string|null $reason        Γεμίζει με τον λόγο απόρριψης, για να τον πει η οθόνη.
	 *
	 * @return array{path:string, filename:string, mime:string}|null
	 */
	public static function store( array $file, array $allowed_mimes, ?string &$reason = null ): ?array {
		$reason = '';

		if ( ( $file['error'] ?? 1 ) !== UPLOAD_ERR_OK || empty( $file['tmp_name'] ) ) {
			// UPLOAD_ERR_INI_SIZE/FORM_SIZE σημαίνουν «πολύ μεγάλο» και το ξέρει ο χρήστης.
			$err    = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
			$reason = in_array( $err, [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true ) ? 'too_large' : 'upload_failed';
			return null;
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			$reason = 'upload_failed';
			return null;
		}

		$size    = (int) @filesize( $file['tmp_name'] ); // phpcs:ignore
		$problem = \EnergyCRM\Infrastructure\UploadCheck::sizeProblem( $size );
		if ( '' !== $problem ) {
			$reason = $problem;
			return null;
		}

		$head = (string) @file_get_contents( $file['tmp_name'], false, null, 0, \EnergyCRM\Infrastructure\UploadCheck::HEAD_BYTES ); // phpcs:ignore
		$mime = \EnergyCRM\Infrastructure\UploadCheck::sniff( $head );
		if ( '' === $mime || ! in_array( $mime, $allowed_mimes, true ) ) {
			$reason = 'bad_type';
			return null;
		}

		// Η κατάληξη από τον τύπο που επιβεβαιώθηκε — ποτέ από το όνομα.
		$ext  = \EnergyCRM\Infrastructure\UploadCheck::extensionFor( $mime );
		$dest = self::storage()->newPath( $ext ); // όνομα που δεν μαντεύεται, μέσα σε κάδο Y/m
		$name = basename( $dest );
		if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) { // phpcs:ignore
			$reason = 'store_failed';
			return null;
		}
		@chmod( $dest, 0640 ); // phpcs:ignore
		return [ 'path' => $dest, 'filename' => sanitize_file_name( $file['name'] ?? $name ), 'mime' => $mime ];
	}

	/**
	 * Store raw bytes (e.g. generated PDF, signature PNG) in the protected dir.
	 *
	 * @return array{path:string, filename:string, mime:string}|null
	 */
	/**
	 * Τα έγγραφα ενός υποψηφίου ακολουθούν τη σύμβαση που προέκυψε από αυτόν.
	 *
	 * Ο πελάτης τα ανέβασε από τον δημόσιο σύνδεσμο πριν υπάρξει σύμβαση. Στη
	 * μετατροπή δεν αντιγράφονται και δεν ξαναζητούνται -- αλλάζει μόνο σε τι
	 * κρέμονται.
	 *
	 * Το lead_id ΔΕΝ καθαρίζεται: η προέλευση είναι πληροφορία, όχι σκουπίδι.
	 * Χωρίς αυτήν, κανείς δεν ξεχωρίζει μετά ποια έγγραφα έφερε ο ίδιος ο
	 * πελάτης από αυτά που ανέβασε ο πωλητής.
	 *
	 * Το `contract_id IS NULL` είναι φύλακας ιδεμποτεντίας: δεύτερη κλήση δεν
	 * ξανακουνάει αρχείο που έχει ήδη βρει σπίτι.
	 */
	public static function attach_lead_to_contract( int $lead_id, int $contract_id ): int {
		global $wpdb;

		if ( $lead_id <= 0 || $contract_id <= 0 ) {
			return 0;
		}

		$t = ECRM_DB::table( 'files' );

		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET contract_id = %d WHERE lead_id = %d AND contract_id IS NULL",
			$contract_id,
			$lead_id
		) );
	}

	public static function put_bytes( string $bytes, string $ext, string $mime, string $filename = '' ): ?array {
		if ( $bytes === '' ) { return null; }
		$dest = self::storage()->newPath( $ext );
		$name = basename( $dest );
		if ( false === @file_put_contents( $dest, $bytes ) ) { // phpcs:ignore
			return null;
		}
		@chmod( $dest, 0640 ); // phpcs:ignore
		return [ 'path' => $dest, 'filename' => sanitize_file_name( $filename ?: $name ), 'mime' => $mime ];
	}

	// --- Serving ------------------------------------------------------------

	/**
	 * Η απόφαση εξουσιοδότησης, χωρισμένη από το `serve()` που την εφαρμόζει.
	 *
	 * Εύρημα ελέγχου ασφαλείας (26/08/2026): μέχρι εδώ ο έλεγχος ήταν ΜΟΝΟ το
	 * signed token — όποιος το είχε, μέσα στη 1ωρη ισχύ του, έπαιρνε το
	 * αρχείο, ασύνδετος ή όχι, ίδιος χρήστης με αυτόν που πήρε το link ή όχι.
	 * Το permission_callback του route είναι σκόπιμα `__return_true` (βλ.
	 * `DocumentsController::routes()`) — αυτό δεν αλλάζει, γιατί ο έλεγχος
	 * ζει εδώ, όχι εκεί. Ο ιδιοκτήτης επιβεβαίωσε (AskUserQuestion, 26/08)
	 * ότι δεν υπάρχει σήμερα κανένα σενάριο εμαιλ/SMS σε μη-συνδεδεμένο
	 * παραλήπτη — η `url()` καλείται μόνο μέσα από ήδη-συνδεδεμένες οθόνες
	 * του CRM. Οπότε: ο τρέχων αιτών πρέπει ΤΩΡΑ να είναι συνδεδεμένος ΚΑΙ
	 * μέσα στο ορατό scope της σύμβασης — το token μόνο του δεν αρκεί πια.
	 *
	 * Ξεχωριστή static μέθοδος (όχι inline μέσα στο `serve()`) γιατί το
	 * `serve()` τελειώνει σε `exit`/`readfile()` και η σουίτα δεν το φτάνει
	 * — ίδιος λόγος, ίδιο μοτίβο με το `PayoutRepository::deletePending()`
	 * (§6γ 1): η κρίσιμη λογική έπρεπε να ζει κάπου ελέγξιμο.
	 */
	public static function requesterMayView( int $bound_uid, int $requesting_uid, int $partner_user_id ): bool {
		if ( $requesting_uid <= 0 ) {
			return false;
		}
		if ( ! in_array( $partner_user_id, ECRM_DB::visible_user_ids( $bound_uid ), true ) ) {
			return false;
		}
		return in_array( $partner_user_id, ECRM_DB::visible_user_ids( $requesting_uid ), true );
	}

	public static function serve( WP_REST_Request $req ): void {
		$fid   = (int) $req['id'];
		$token = (string) $req->get_param( 't' );
		$v     = self::verify( $token );
		if ( ! $v || $v[1] !== $fid ) {
			status_header( 403 );
			exit;
		}
		$bound_uid      = $v[0];
		$requesting_uid = get_current_user_id();

		global $wpdb;
		$fl  = ECRM_DB::table( 'files' );
		$ct  = ECRM_DB::table( 'contracts' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT f.*, c.partner_user_id FROM {$fl} f LEFT JOIN {$ct} c ON c.id = f.contract_id WHERE f.id = %d", $fid
		), ARRAY_A );
		if ( ! $row ) { status_header( 404 ); exit; }

		if ( ! self::requesterMayView( $bound_uid, $requesting_uid, (int) $row['partner_user_id'] ) ) {
			status_header( 403 );
			exit;
		}

		// Resolve the physical file (protected path, or legacy attachment).
		$path = '';
		if ( ! empty( $row['protected'] ) && ! empty( $row['path'] ) && file_exists( $row['path'] ) ) {
			$path = $row['path'];
		} elseif ( ! empty( $row['attachment_id'] ) ) {
			$p = get_attached_file( (int) $row['attachment_id'] );
			if ( $p && file_exists( $p ) ) { $path = $p; }
		}
		if ( ! $path ) { status_header( 404 ); exit; }

		nocache_headers();
		header( 'Content-Type: ' . ( $row['mime'] ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $row['filename'] ?: basename( $path ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore
		exit;
	}

}
