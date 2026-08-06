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
 * and FileRepository::protectBatch().
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Files {

	const TTL = 3600; // signed-URL lifetime (seconds)

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
			rest_url( ECRM_REST::NS . '/file/' . $file_id )
		);
	}

	// --- Upload (protected) -------------------------------------------------

	/**
	 * Store an uploaded file in the protected dir.
	 *
	 * @param array $file A single $_FILES entry (tmp_name, name, type).
	 * @return array{path:string, filename:string, mime:string}|null
	 */
	public static function store( array $file, array $allowed_mimes ): ?array {
		if ( ( $file['error'] ?? 1 ) !== UPLOAD_ERR_OK || empty( $file['tmp_name'] ) ) {
			return null;
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return null;
		}
		$ft   = wp_check_filetype( $file['name'] ?? '' );
		$mime = in_array( ( $file['type'] ?? '' ), $allowed_mimes, true ) ? $file['type'] : ( $ft['type'] ?? '' );
		if ( ! in_array( $mime, $allowed_mimes, true ) ) {
			return null;
		}
		$ext  = $ft['ext'] ?: 'bin';
		$dir  = self::dir();
		$name = 'doc_' . wp_generate_password( 24, false ) . '.' . $ext; // unguessable name
		$dest = trailingslashit( $dir ) . $name;
		if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) { // phpcs:ignore
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
	public static function put_bytes( string $bytes, string $ext, string $mime, string $filename = '' ): ?array {
		if ( $bytes === '' ) { return null; }
		$dir  = self::dir();
		$name = 'doc_' . wp_generate_password( 24, false ) . '.' . preg_replace( '/[^a-z0-9]/i', '', $ext );
		$dest = trailingslashit( $dir ) . $name;
		if ( false === @file_put_contents( $dest, $bytes ) ) { // phpcs:ignore
			return null;
		}
		@chmod( $dest, 0640 ); // phpcs:ignore
		return [ 'path' => $dest, 'filename' => sanitize_file_name( $filename ?: $name ), 'mime' => $mime ];
	}

	// --- Serving ------------------------------------------------------------

	public static function serve( WP_REST_Request $req ): void {
		$fid   = (int) $req['id'];
		$token = (string) $req->get_param( 't' );
		$v     = self::verify( $token );
		if ( ! $v || $v[1] !== $fid ) {
			status_header( 403 );
			exit;
		}
		$bound_uid = $v[0];

		global $wpdb;
		$fl  = ECRM_DB::table( 'files' );
		$ct  = ECRM_DB::table( 'contracts' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT f.*, c.partner_user_id FROM {$fl} f LEFT JOIN {$ct} c ON c.id = f.contract_id WHERE f.id = %d", $fid
		), ARRAY_A );
		if ( ! $row ) { status_header( 404 ); exit; }

		// The user the token was issued to must still be allowed to see the contract.
		$allowed = in_array( (int) $row['partner_user_id'], ECRM_DB::visible_user_ids( $bound_uid ), true );
		if ( ! $allowed ) { status_header( 403 ); exit; }

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
