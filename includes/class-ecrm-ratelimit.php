<?php
/**
 * Simple rate limiter (transient-backed sliding window).
 *
 * Protects expensive/abusable endpoints (external VIES lookups, SMS sends,
 * token pages) from hammering. Keyed per user when authenticated, else per IP.
 *
 * Usage:
 *   if ( ! ECRM_RateLimit::allow( 'afm', 30, 300 ) ) { return 429; }
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_RateLimit {

	/** Best-effort client IP (proxy-aware but conservative). */
	public static function ip(): string {
		$ip = '';
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$ip = explode( ',', (string) wp_unslash( $_SERVER[ $k ] ) )[0];
				break;
			}
		}
		return substr( trim( $ip ), 0, 45 );
	}

	/**
	 * Returns true if the action is within the allowed budget, false if over.
	 *
	 * @param string $action  Logical action name (namespace for the counter).
	 * @param int    $max     Max requests allowed within the window.
	 * @param int    $window  Window length in seconds.
	 * @param string $bucket  Optional explicit bucket; defaults to user id or IP.
	 */
	public static function allow( string $action, int $max, int $window, string $bucket = '' ): bool {
		if ( $bucket === '' ) {
			$uid    = get_current_user_id();
			$bucket = $uid ? ( 'u' . $uid ) : ( 'ip' . md5( self::ip() ) );
		}
		$key = 'ecrm_rl_' . $action . '_' . $bucket;
		$now = time();
		$rec = get_transient( $key );

		if ( ! is_array( $rec ) || empty( $rec['r'] ) || $now > (int) $rec['r'] ) {
			set_transient( $key, [ 'c' => 1, 'r' => $now + $window ], $window );
			return true;
		}
		if ( (int) $rec['c'] >= $max ) {
			return false;
		}
		$rec['c'] = (int) $rec['c'] + 1;
		set_transient( $key, $rec, max( 1, (int) $rec['r'] - $now ) );
		return true;
	}

	/** Build a standard 429 response. */
	public static function too_many( string $msg = 'Πάρα πολλές αιτήσεις. Προσπάθησε ξανά σε λίγο.' ): WP_REST_Response {
		return new WP_REST_Response( [ 'ok' => false, 'error' => $msg ], 429 );
	}
}
