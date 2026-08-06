<?php
/**
 * Simple rate limiter (transient-backed sliding window).
 *
 * Protects expensive/abusable endpoints (external VIES lookups, SMS sends,
 * token pages) from hammering. Keyed per user when authenticated, else per IP
 * — and the IP has to be one the caller cannot choose, or the whole thing is
 * decoration. See ip() below.
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

	/**
	 * The address this request came from.
	 *
	 * Forwarded headers are believed only when the connection itself came from
	 * a proxy listed here, because otherwise the caller writes them. See
	 * EnergyCRM\Infrastructure\ClientIp for the reasoning.
	 *
	 * Behind Cloudflare or a load balancer, list its ranges:
	 *
	 *   add_filter( 'ecrm_trusted_proxies', fn() => [ '173.245.48.0/20', ... ] );
	 *
	 * Left empty, every visitor behind that proxy shares one bucket — safe,
	 * but stricter than intended, which is why the misconfiguration is logged.
	 */
	public static function ip(): string {
		$trusted = (array) apply_filters( 'ecrm_trusted_proxies', get_option( 'ecrm_trusted_proxies', [] ) );
		$trusted = array_values( array_filter( array_map( 'strval', $trusted ) ) );

		if ( ! $trusted ) {
			self::warn_if_proxied();
		}

		$server = array_map( 'wp_unslash', $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- ClientIp validates every value it uses.

		return substr( ( new \EnergyCRM\Infrastructure\ClientIp( $trusted ) )->resolve( $server ), 0, 45 );
	}

	/**
	 * Say so, once a day, when the site is behind a proxy nobody declared.
	 *
	 * Silence here would look like working rate limiting while every visitor
	 * counts as the same client.
	 */
	private static function warn_if_proxied(): void {
		if ( empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return;
		}
		if ( get_transient( 'ecrm_rl_proxy_warned' ) ) {
			return;
		}
		set_transient( 'ecrm_rl_proxy_warned', 1, DAY_IN_SECONDS );
		error_log( '[Energy CRM] Οι αιτήσεις έρχονται μέσω proxy αλλά δεν έχει οριστεί το φίλτρο ecrm_trusted_proxies· το rate limiting μετράει όλους τους επισκέπτες ως έναν.' ); // phpcs:ignore
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
