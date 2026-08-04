<?php
/**
 * Baseline security response headers (front-end).
 *
 * Conservative set that won't break a typical theme: clickjacking protection,
 * MIME-sniffing protection, referrer policy, a tight permissions policy, and
 * HSTS when served over HTTPS. CSP is intentionally left out (high breakage
 * risk on themed sites) — add it at server level once tested.
 *
 * Disable via: add_filter( 'ecrm_security_headers_enabled', '__return_false' );
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Security {

	public static function init(): void {
		add_action( 'send_headers', [ __CLASS__, 'headers' ] );
	}

	public static function headers(): void {
		if ( is_admin() || headers_sent() ) {
			return;
		}
		if ( ! apply_filters( 'ecrm_security_headers_enabled', true ) ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), browsing-topics=()' );
		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
		}
	}
}
