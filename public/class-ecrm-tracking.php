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
		register_rest_route( ECRM_REST::NS, '/track/(?P<token>[A-Za-z0-9\-]+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'rest_get' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( ECRM_REST::NS, '/track/(?P<token>[A-Za-z0-9\-]+)/sign', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_sign' ],
			'permission_callback' => '__return_true',
		] );
		register_rest_route( ECRM_REST::NS, '/track/(?P<token>[A-Za-z0-9\-]+)/upload', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_upload' ],
			'permission_callback' => '__return_true',
		] );
	}

	// --- Stateless token ----------------------------------------------------

	public static function token( int $id ): string {
		$sig = substr( hash_hmac( 'sha256', 'ecrm_track_' . $id, wp_salt( 'auth' ) ), 0, 20 );
		return $id . '-' . $sig;
	}

	public static function verify( string $token ): ?int {
		if ( ! preg_match( '/^(\d+)-([a-f0-9]{20})$/', $token, $m ) ) {
			return null;
		}
		$id = (int) $m[1];
		return hash_equals( self::token( $id ), $token ) ? $id : null;
	}

	public static function url( int $id ): string {
		return add_query_arg( 'ecrm_track', self::token( $id ), home_url( '/' ) );
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
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		// Persist the signature image (protected).
		$sig_path = '';
		if ( class_exists( 'ECRM_Files' ) ) {
			$stored = ECRM_Files::put_bytes( $png, 'png', 'image/png', 'signature.png' );
			if ( $stored ) {
				$sig_path = $stored['path'];
				$wpdb->insert( ECRM_DB::table( 'files' ), [
					'contract_id' => $id, 'attachment_id' => null, 'doc_kind' => 'signature',
					'filename' => 'signature.png', 'mime' => 'image/png', 'path' => $stored['path'], 'protected' => 1,
				] );
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
						$wpdb->insert( ECRM_DB::table( 'files' ), [
							'contract_id' => $id, 'attachment_id' => null, 'doc_kind' => 'signed_pdf',
							'filename' => ( $row['code'] ?: ( 'symvasi-' . $id ) ) . '-ypografi.pdf',
							'mime' => 'application/pdf', 'path' => $sp['path'], 'protected' => 1,
						] );
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
			'inapp'   => false, // notify_signed() below handles the in-app notification.
		] );

		// Rebuild the attached provider form, so the stored copy reflects the
		// contract as it stands at the moment of signing.
		\EnergyCRM\Services::contractDocuments()->store( $id );

		if ( class_exists( 'ECRM_REST' ) ) {
			ECRM_REST::notify_signed( $id, (string) ( $row['first_name'] ?? '' ) );
		}

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
		if ( class_exists( 'ECRM_REST' ) && method_exists( 'ECRM_REST', 'notify_document' ) ) {
			ECRM_REST::notify_document( $id, $label );
		}

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
		$rest    = esc_url_raw( rest_url( ECRM_REST::NS . '/track/' . $token ) );
		$accent  = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'accent_color', '#f59e0b' ) : '#f59e0b';
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
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
<style>
	:root { --accent: <?php echo esc_html( $accent ?: '#f59e0b' ); ?>; --navy:#0a1f3d; }
	* { box-sizing: border-box; }
	body { margin:0; font-family:"Inter",system-ui,sans-serif; background:
		radial-gradient(ellipse at 20% 10%, rgba(245,158,11,.12), transparent 50%),
		linear-gradient(160deg,#061429,var(--navy) 50%,#14304f); min-height:100vh;
		display:flex; align-items:center; justify-content:center; padding:20px; color:#0f172a; }
	.card { background:#fff; border-radius:18px; box-shadow:0 30px 70px -20px rgba(0,0,0,.5); width:100%; max-width:520px; overflow:hidden; }
	.head { background:var(--navy); color:#fff; padding:22px 24px; }
	.head h1 { margin:0; font-size:19px; }
	.head p { margin:6px 0 0; color:#cbd5e1; font-size:13px; }
	.body { padding:24px; }
	.row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f1f5f9; font-size:14px; }
	.row span { color:#64748b; } .row b { color:#0f172a; }
	.steps { list-style:none; margin:22px 0 6px; padding:0; }
	.steps li { display:flex; align-items:flex-start; gap:12px; padding-bottom:18px; position:relative; }
	.steps li:not(:last-child)::before { content:""; position:absolute; left:13px; top:28px; bottom:-2px; width:2px; background:#e2e8f0; }
	.steps li.done:not(:last-child)::before { background:var(--accent); }
	.dot { flex:0 0 28px; width:28px; height:28px; border-radius:50%; background:#e2e8f0; color:#94a3b8; display:grid; place-items:center; font-weight:800; font-size:14px; z-index:1; }
	.steps li.done .dot { background:var(--accent); color:var(--navy); }
	.steps li.current .dot { background:var(--navy); color:#fff; box-shadow:0 0 0 4px rgba(10,31,61,.15); }
	.steps .lbl { padding-top:4px; font-weight:600; color:#94a3b8; }
	.steps li.done .lbl, .steps li.current .lbl { color:#0f172a; }
	.badge { display:inline-block; margin-top:4px; padding:6px 12px; border-radius:999px; font-size:13px; font-weight:700; background:#eef2ff; color:#3730a3; }
	.badge.cancel { background:#fee2e2; color:#b91c1c; }
	.cancel-box { text-align:center; padding:30px 10px; }
	.cancel-box .x { width:60px; height:60px; border-radius:50%; background:#fee2e2; color:#b91c1c; display:grid; place-items:center; font-size:30px; margin:0 auto 14px; }
	.foot { text-align:center; font-size:12px; color:#94a3b8; padding:14px; }
	.sign { margin-top:22px; border-top:1px solid #f1f5f9; padding-top:18px; }
	.sign h2 { font-size:15px; margin:0 0 4px; color:#0f172a; }
	.sign p.lead { margin:0 0 12px; font-size:13px; color:#64748b; }
	.pad { border:2px dashed #cbd5e1; border-radius:12px; background:#fbfdff; touch-action:none; width:100%; height:180px; display:block; }
	.pad.is-empty { } 
	.padbar { display:flex; justify-content:space-between; align-items:center; margin-top:8px; }
	.padbar small { color:#94a3b8; font-size:12px; }
	.btn { border:0; border-radius:10px; padding:11px 18px; font-size:14px; font-weight:700; cursor:pointer; }
	.btn--clear { background:#f1f5f9; color:#475569; padding:7px 12px; font-size:12px; }
	.btn--sign { background:var(--accent); color:var(--navy); width:100%; margin-top:14px; }
	.btn--sign:disabled { opacity:.5; cursor:not-allowed; }
	.consent { display:flex; gap:10px; align-items:flex-start; margin-top:14px; font-size:12.5px; color:#475569; line-height:1.5; }
	.consent input { margin-top:2px; flex:0 0 auto; width:16px; height:16px; }
	.signed-ok { text-align:center; padding:18px 10px; }
	.signed-ok .v { width:54px; height:54px; border-radius:50%; background:#dcfce7; color:#15803d; display:grid; place-items:center; font-size:28px; margin:0 auto 12px; }
	.signed-ok p { color:#15803d; font-weight:700; margin:0; }
	.docs { margin-top:22px; border-top:1px solid #f1f5f9; padding-top:18px; }
	.docs h2 { font-size:15px; margin:0 0 4px; color:#0f172a; }
	.docs p.lead { margin:0 0 12px; font-size:13px; color:#64748b; }
	.doc-item { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 0; border-bottom:1px solid #f5f7fa; }
	.doc-item:last-child { border-bottom:0; }
	.doc-lbl { font-size:14px; font-weight:600; color:#334155; }
	.doc-ok { display:inline-flex; align-items:center; gap:6px; color:#15803d; font-weight:700; font-size:13px; }
	.up-btn { border:1px solid var(--navy); background:#fff; color:var(--navy); border-radius:9px; padding:7px 14px; font-size:13px; font-weight:700; cursor:pointer; }
	.up-btn:hover { background:#f8fafc; }
	.up-btn.is-busy { opacity:.6; pointer-events:none; }
	.doc-msg { font-size:12px; margin-top:8px; min-height:16px; }
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

	function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
	function fmt(s){ if(!s) return '—'; var d=new Date(s.replace(' ','T')+'Z'); if(isNaN(d)) return s; return d.toLocaleDateString('el-GR')+' '+d.toLocaleTimeString('el-GR',{hour:'2-digit',minute:'2-digit'}); }

	function fail(msg, code){
		content.innerHTML = '<p style="color:#b91c1c;line-height:1.5">' + esc(msg) +
			(code ? '<br><small style="color:#94a3b8">(κωδ. ' + esc(code) + ')</small>' : '') + '</p>';
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
				'<p style="color:#64748b;margin:12px 0 0">Η αίτηση δεν είναι σε εξέλιξη. Για διευκρινίσεις επικοινωνήστε μαζί μας.</p></div>';
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
		function say(t, ok){ if(msg){ msg.textContent=t; msg.style.color = ok===false ? '#b91c1c' : (ok? '#15803d':'#64748b'); } }

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
			c.lineWidth = 2.2; c.lineCap = 'round'; c.lineJoin = 'round'; c.strokeStyle = '#0a1f3d';
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
