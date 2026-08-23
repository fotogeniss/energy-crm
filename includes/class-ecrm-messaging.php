<?php
/**
 * Customer messaging — SMS / Viber on contract status changes.
 *
 * Pluggable gateway: pick the provider in Energy CRM → Ρυθμίσεις.
 *   - apifon  : native Apifon REST (HMAC-signed). Viber-with-SMS-fallback or SMS.
 *   - generic : works with ANY provider (Yuboto, Routee, Mitto, …) — you supply
 *               the endpoint URL, HTTP method, headers and a body template with
 *               {to} / {text} / {sender} placeholders.
 *
 * Per-status, editable Greek templates with placeholders:
 *   {name} {code} {company} {status} {provider}
 *
 * Each send is logged as an event (type 'sms') on the contract timeline, so the
 * full customer-comms history is auditable from the contract detail screen.
 * Credentials live server-side only and never reach the front-end.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Messaging {

	public static function init(): void {
		// Test-send from the settings screen (admin-only).
		add_action( 'wp_ajax_ecrm_sms_test', [ __CLASS__, 'ajax_test' ] );
	}

	/** Master on/off. */
	public static function enabled(): bool {
		return class_exists( 'ECRM_Admin' ) && ECRM_Admin::get( 'sms_enabled', '0' ) === '1';
	}

	/** Selected provider slug: apifon | generic. */
	public static function provider(): string {
		$p = (string) ECRM_Admin::get( 'sms_provider', 'apifon' );
		return in_array( $p, [ 'apifon', 'generic' ], true ) ? $p : 'apifon';
	}

	/** Default per-status message templates (Greek). */
	public static function default_templates(): array {
		return [
			'pending_signature' => 'Αγαπητέ/ή {name}, η αίτησή σας {code} περιμένει την υπογραφή σας. Υπογράψτε εδώ: {track} — {company}',
			'awaiting_signature' => 'Αγαπητέ/ή {name}, η αίτησή σας {code} αναμένει την υπογραφή σας. Υπογράψτε εδώ: {track} — {company}',
			'signed'            => '{name}, λάβαμε την υπογραφή σας για την αίτηση {code}. Ευχαριστούμε! — {company}',
			'routed'            => '{name}, η αίτησή σας {code} στάλθηκε στον πάροχο για ενεργοποίηση. Παρακολουθήστε την εδώ: {track} — {company}',
			'active'            => 'Καλώς ήρθατε! Η σύμβασή σας {code} ({provider}) ενεργοποιήθηκε. — {company}',
			'pending'           => '{name}, η αίτησή σας {code} εκκρεμεί. Θα επικοινωνήσουμε σύντομα μαζί σας. — {company}',
			'cancelled'         => '{name}, η αίτησή σας {code} ακυρώθηκε. Για διευκρινίσεις επικοινωνήστε μαζί μας. — {company}',
		];
	}

	/** @return array<string,string> status => template (defaults merged with saved). */
	public static function templates(): array {
		$saved = get_option( ECRM_PREFIX . 'sms_tpl', [] );
		$saved = is_array( $saved ) ? $saved : [];
		return array_merge( self::default_templates(), array_filter( $saved, 'strlen' ) );
	}

	/** @return array<string> statuses for which an SMS should fire. */
	public static function active_statuses(): array {
		$on = get_option( ECRM_PREFIX . 'sms_on', [ 'routed', 'active' ] );
		return is_array( $on ) ? array_values( $on ) : [];
	}

	/**
	 * Hook target — called from REST change_status after the event is logged.
	 *
	 * Κρατά ΜΟΝΟ την πύλη «είναι αυτή η κατάσταση στη λίστα;». Η αποστολή
	 * βγήκε στη send_for_status() ώστε να μπορεί να ζητηθεί και ρητά, από
	 * σημείο που ξέρει ότι τη θέλει — δες εκεί.
	 */
	public static function on_status_change( int $contract_id, string $to ): void {
		if ( ! self::enabled() ) {
			return;
		}
		if ( ! in_array( $to, self::active_statuses(), true ) ) {
			return;
		}

		self::send_for_status( $contract_id, $to );
	}

	/**
	 * Στέλνει ΤΩΡΑ το πρότυπο μιας κατάστασης, χωρίς να ρωτήσει τη λίστα.
	 *
	 * ## Γιατί χωρίς τη λίστα
	 *
	 * Η `sms_on` απαντά «ποιες αλλαγές κατάστασης στέλνουν μόνες τους μήνυμα».
	 * Άλλο ερώτημα από «ο συνεργάτης πάτησε Αποστολή, στείλ' το». Το δεύτερο
	 * είναι ρητή εντολή ανθρώπου· να την μπλοκάρει μια ρύθμιση αυτοματισμού θα
	 * ήταν σαν να μην τυπώνει ο εκτυπωτής επειδή είναι κλειστός ο
	 * προγραμματισμένος εκτυπωτής της νύχτας.
	 *
	 * Το `enabled()` ΟΜΩΣ τηρείται: χωρίς πάροχο δεν υπάρχει τι να σταλεί.
	 *
	 * Η συμπεριφορά της αυτόματης διαδρομής δεν άλλαξε ούτε στο ελάχιστο —
	 * ίδιοι έλεγχοι, ίδια σειρά, ίδια καταγραφή.
	 *
	 * @return array{ok:bool, error?:string, channel?:string, to?:string}
	 */
	public static function send_for_status( int $contract_id, string $status ): array {
		if ( ! self::enabled() ) {
			return [ 'ok' => false, 'error' => 'messaging_disabled' ];
		}

		$ctx = self::contract_context( $contract_id );
		if ( ! $ctx ) {
			return [ 'ok' => false, 'error' => 'contract_not_found' ];
		}
		if ( empty( $ctx['mobile'] ) ) {
			return [ 'ok' => false, 'error' => 'no_mobile' ];
		}

		$tpls = self::templates();
		$tpl  = $tpls[ $status ] ?? '';
		if ( $tpl === '' ) {
			return [ 'ok' => false, 'error' => 'no_template' ];
		}

		$text = self::render( $tpl, $ctx );
		$res  = self::send( $ctx['mobile'], $text );

		self::log( $contract_id, $ctx['mobile'], $text, $res );

		$res['to'] = $ctx['mobile'];

		return $res;
	}

	/** Pull the data a template needs for a contract. */
	private static function contract_context( int $contract_id ): ?array {
		global $wpdb;
		$ct = ECRM_DB::table( 'contracts' );
		$cu = ECRM_DB::table( 'customers' );
		$pr = ECRM_DB::table( 'providers' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.code, c.status, p.name AS provider_name,
			        cu.first_name, cu.last_name, cu.company_name, cu.mobile, cu.phone
			 FROM {$ct} c
			 LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 LEFT JOIN {$pr} p  ON p.id = c.provider_id
			 WHERE c.id = %d", $contract_id
		), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$name   = $row['company_name'] ?: trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
		$labels = ECRM_DB::statuses();
		return [
			'code'     => $row['code'] ?? '',
			'name'     => $name ?: 'πελάτη',
			'provider' => $row['provider_name'] ?? '',
			'status'   => $labels[ $row['status'] ] ?? $row['status'],
			'track'    => class_exists( 'ECRM_Tracking' ) ? ECRM_Tracking::url( $contract_id ) : '',
			'mobile'   => self::normalize_phone( $row['mobile'] ?: $row['phone'] ?: '' ),
		];
	}

	/** Replace {placeholders} in a template. */
	private static function render( string $tpl, array $ctx ): string {
		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name', get_bloginfo( 'name' ) ) : get_bloginfo( 'name' );
		$map = [
			'{name}'     => $ctx['name'] ?? '',
			'{code}'     => $ctx['code'] ?? '',
			'{company}'  => $company,
			'{status}'   => $ctx['status'] ?? '',
			'{provider}' => $ctx['provider'] ?? '',
			'{track}'    => $ctx['track'] ?? '',
		];
		return trim( strtr( $tpl, $map ) );
	}

	/**
	 * Normalize a Greek phone to MSISDN digits with country code (e.g. 306912345678).
	 * Returns '' if it doesn't look like a sendable mobile.
	 */
	public static function normalize_phone( string $raw ): string {
		$d = preg_replace( '/\D+/', '', $raw );
		if ( $d === '' ) {
			return '';
		}
		if ( strpos( $d, '00' ) === 0 ) {
			$d = substr( $d, 2 ); // 0030… → 30…
		}
		if ( strpos( $d, '30' ) === 0 && strlen( $d ) >= 11 ) {
			return $d; // already has GR country code
		}
		if ( strlen( $d ) === 10 && $d[0] === '6' ) {
			return '30' . $d; // bare GR mobile
		}
		if ( strlen( $d ) === 10 && $d[0] === '2' ) {
			return '30' . $d; // bare GR landline (Viber may not apply, but allow SMS)
		}
		// Already international-ish (>=11 digits) — trust it.
		return strlen( $d ) >= 11 ? $d : '';
	}

	/**
	 * Send a single message via the configured provider.
	 *
	 * @return array{ok:bool, error?:string, channel?:string}
	 */
	public static function send( string $to, string $text ): array {
		if ( $to === '' ) {
			return [ 'ok' => false, 'error' => 'no_number' ];
		}
		switch ( self::provider() ) {
			case 'generic':
				return self::send_generic( $to, $text );
			case 'apifon':
			default:
				return self::send_apifon( $to, $text );
		}
	}

	// --- Apifon (native HMAC) ----------------------------------------------

	private static function send_apifon( string $to, string $text ): array {
		$token  = (string) ECRM_Admin::get( 'sms_apifon_token', '' );
		$secret = (string) ECRM_Admin::get( 'sms_apifon_secret', '' );
		$sender = (string) ECRM_Admin::get( 'sms_sender', '' );
		$channel = (string) ECRM_Admin::get( 'sms_apifon_channel', 'sms' ); // sms | viber
		if ( $token === '' || $secret === '' ) {
			return [ 'ok' => false, 'error' => 'missing_apifon_credentials' ];
		}

		$host = 'https://ars.apifon.com';
		$path = ( $channel === 'viber' ) ? '/services/api/v1/im/send' : '/services/api/v1/sms/send';

		$message = [ 'text' => $text ];
		if ( $sender !== '' ) {
			$message['sender_id'] = $sender;
		}
		$payload = [
			'subscribers' => [ [ 'number' => $to ] ],
			'message'     => $message,
		];
		if ( $channel === 'viber' ) {
			// Fall back to SMS if the device has no Viber.
			$payload['message']['fallback'] = [ 'sender_id' => $sender ?: 'INFO', 'text' => $text ];
		}
		$body = wp_json_encode( $payload );

		// Apifon request signing: HMAC-SHA256 over METHOD\nPATH\nBODY\nDATE.
		$date = gmdate( 'D, d M Y H:i:s' ) . ' GMT';
		$to_sign = "POST\n" . $path . "\n" . $body . "\n" . $date;
		$sig = base64_encode( hash_hmac( 'sha256', $to_sign, $secret, true ) );

		$resp = wp_remote_post( $host . $path, [
			'timeout' => 15,
			'headers' => [
				'Content-Type'    => 'application/json',
				'X-ApifonWS-Date' => $date,
				'Authorization'   => 'ApifonWS ' . $token . ':' . $sig,
			],
			'body'    => $body,
		] );
		return self::interpret( $resp, $channel );
	}

	// --- Generic (any provider via template) --------------------------------

	private static function send_generic( string $to, string $text ): array {
		$url     = (string) ECRM_Admin::get( 'sms_generic_url', '' );
		$method  = strtoupper( (string) ECRM_Admin::get( 'sms_generic_method', 'POST' ) );
		$sender  = (string) ECRM_Admin::get( 'sms_sender', '' );
		$tplBody = (string) ECRM_Admin::get( 'sms_generic_body', '' );
		$rawHdrs = (string) ECRM_Admin::get( 'sms_generic_headers', '' );
		if ( $url === '' ) {
			return [ 'ok' => false, 'error' => 'missing_generic_url' ];
		}
		$repl = [
			'{to}'     => $to,
			'{text}'   => $text,
			'{sender}' => $sender,
		];
		$urlRepl = [
			'{to}'     => rawurlencode( $to ),
			'{text}'   => rawurlencode( $text ),
			'{sender}' => rawurlencode( $sender ),
		];
		$url = strtr( $url, $urlRepl );

		// Parse "Key: Value" header lines.
		$headers = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $rawHdrs ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || strpos( $line, ':' ) === false ) {
				continue;
			}
			list( $k, $v ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$headers[ $k ] = strtr( $v, $repl );
		}

		$args = [ 'timeout' => 15, 'headers' => $headers, 'method' => $method ];
		if ( $method !== 'GET' && $tplBody !== '' ) {
			$args['body'] = strtr( $tplBody, $repl );
		}
		$resp = ( $method === 'GET' )
			? wp_remote_get( $url, $args )
			: wp_remote_request( $url, $args );
		return self::interpret( $resp, 'generic' );
	}

	/** Turn a wp_remote_* response into our normalized result. */
	private static function interpret( $resp, string $channel ): array {
		if ( is_wp_error( $resp ) ) {
			return [ 'ok' => false, 'error' => $resp->get_error_message(), 'channel' => $channel ];
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code >= 200 && $code < 300 ) {
			return [ 'ok' => true, 'channel' => $channel ];
		}
		$snippet = mb_substr( (string) wp_remote_retrieve_body( $resp ), 0, 300 );
		return [ 'ok' => false, 'error' => 'HTTP ' . $code . ' ' . $snippet, 'channel' => $channel ];
	}

	/** Write an audit event on the contract timeline. */
	private static function log( int $contract_id, string $to, string $text, array $res ): void {
		global $wpdb;
		$ok  = ! empty( $res['ok'] );
		$msg = sprintf(
			'SMS%s → %s: %s%s',
			isset( $res['channel'] ) ? ' (' . $res['channel'] . ')' : '',
			$to,
			mb_substr( $text, 0, 140 ),
			$ok ? '' : ' [ΣΦΑΛΜΑ: ' . ( $res['error'] ?? '?' ) . ']'
		);
		$wpdb->insert( ECRM_DB::table( 'events' ), [
			'contract_id' => $contract_id,
			'user_id'     => get_current_user_id() ?: null,
			'type'        => $ok ? 'sms' : 'sms_failed',
			'message'     => $msg,
		] );
	}

	// --- Settings test-send -------------------------------------------------

	public static function ajax_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'error' => 'forbidden' ], 403 );
		}
		check_ajax_referer( 'ecrm_sms_test', 'nonce' );
		if ( class_exists( 'ECRM_RateLimit' ) && ! ECRM_RateLimit::allow( 'sms_test', 10, 300 ) ) {
			wp_send_json_error( [ 'error' => 'Πάρα πολλές δοκιμές. Προσπάθησε σε λίγο.' ], 429 );
		}
		$to   = self::normalize_phone( sanitize_text_field( $_POST['to'] ?? '' ) );
		$text = sanitize_textarea_field( $_POST['text'] ?? '' ) ?: 'Δοκιμαστικό μήνυμα από το Energy CRM.';
		if ( $to === '' ) {
			wp_send_json_error( [ 'error' => 'Μη έγκυρος αριθμός.' ] );
		}
		$res = self::send( $to, $text );
		if ( ! empty( $res['ok'] ) ) {
			wp_send_json_success( [ 'message' => 'Στάλθηκε (' . ( $res['channel'] ?? '' ) . ').' ] );
		}
		wp_send_json_error( [ 'error' => $res['error'] ?? 'Αποτυχία αποστολής.' ] );
	}
}
