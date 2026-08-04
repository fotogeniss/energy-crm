<?php
/**
 * Document extractor — the AI core.
 *
 * Takes one or more uploaded documents (ταυτότητα + λογαριασμός παρόχου),
 * sends them to the Claude Messages API as image/document blocks, and asks
 * for a strict JSON object of customer + supply fields to pre-fill the form.
 *
 * The model is told to return ONLY JSON (no prose, no markdown fences), and
 * we parse defensively. Anything it can't read is returned as null so the
 * agent fills it in manually.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Extractor {

	const API_URL = 'https://api.anthropic.com/v1/messages';
	const API_VER = '2023-06-01';

	/**
	 * Default model — overridable via settings.
	 *
	 * Sonnet over Opus deliberately: reading an ID card and a utility bill into
	 * a fixed set of fields is well within its vision capability, at a fraction
	 * of the cost per application. On this workload the cost difference lands
	 * straight on the margin per contract.
	 */
	const DEFAULT_MODEL = 'claude-sonnet-5';

	/** Fields we expect back. Keep in sync with the customers table + form. */
	public static function fields(): array {
		return [
			'customer_type', 'afm', 'doy', 'first_name', 'last_name', 'father_name',
			'company_name', 'adt', 'birth_date', 'region', 'city', 'street',
			'street_no', 'postal_code', 'phone', 'mobile', 'email',
			'supply_number', 'meter_number', 'invoice_code',
		];
	}

	/**
	 * Run extraction over a set of files.
	 *
	 * @param array $files Each: ['path'=>abs path, 'mime'=>mime, 'kind'=>id_card|provider_bill|other]
	 * @return array{ok:bool, data?:array, raw?:string, error?:string}
	 */
	public static function extract( array $files ): array {
		$api_key = self::api_key();
		if ( empty( $api_key ) ) {
			return [ 'ok' => false, 'error' => 'Δεν έχει οριστεί Claude API key. Ρυθμίσεις → Energy CRM.' ];
		}
		if ( empty( $files ) ) {
			return [ 'ok' => false, 'error' => 'Δεν δόθηκαν αρχεία.' ];
		}

		$content = [];

		// Lead with the instruction text, then attach each document.
		$content[] = [ 'type' => 'text', 'text' => self::prompt() ];

		foreach ( $files as $f ) {
			$block = self::file_to_block( $f );
			if ( $block ) {
				// Label each doc so the model knows which is which.
				$label = $f['kind'] === 'id_card' ? 'ΕΓΓΡΑΦΟ: Ταυτότητα/Διαβατήριο'
					: ( $f['kind'] === 'provider_bill' ? 'ΕΓΓΡΑΦΟ: Λογαριασμός παρόχου' : 'ΕΓΓΡΑΦΟ' );
				$content[] = [ 'type' => 'text', 'text' => $label ];
				$content[] = $block;
			}
		}

		if ( count( $content ) <= 1 ) {
			return [ 'ok' => false, 'error' => 'Τα αρχεία δεν διαβάστηκαν (μη υποστηριζόμενος τύπος).' ];
		}

		$body = [
			'model'      => self::model(),
			'max_tokens' => 1500,
			'messages'   => [
				[ 'role' => 'user', 'content' => $content ],
			],
		];

		$resp = wp_remote_post( self::API_URL, [
			'timeout' => 60,
			'headers' => [
				'content-type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => self::API_VER,
			],
			'body'    => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $resp ) ) {
			return [ 'ok' => false, 'error' => 'Σφάλμα σύνδεσης: ' . $resp->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code !== 200 ) {
			$msg = $json['error']['message'] ?? ( 'HTTP ' . $code );
			return [ 'ok' => false, 'error' => 'Claude API: ' . $msg ];
		}

		// Concatenate any text blocks from the assistant response.
		$text = '';
		foreach ( ( $json['content'] ?? [] ) as $blk ) {
			if ( ( $blk['type'] ?? '' ) === 'text' ) {
				$text .= $blk['text'];
			}
		}

		$data = self::parse_json( $text );
		if ( null === $data ) {
			return [ 'ok' => false, 'error' => 'Δεν επιστράφηκε έγκυρο JSON.', 'raw' => $text ];
		}

		return [ 'ok' => true, 'data' => self::normalize( $data ), 'raw' => $text ];
	}

	// ---------------------------------------------------------------------
	// Prompt
	// ---------------------------------------------------------------------
	private static function prompt(): string {
		$keys = implode( ', ', self::fields() );
		return <<<PROMPT
Είσαι βοηθός καταχώρισης για ελληνικό CRM ενεργειακών συμβολαίων. Θα σου δοθούν φωτογραφίες/PDF εγγράφων (ελληνική ταυτότητα ή διαβατήριο, και λογαριασμός παρόχου ρεύματος/αερίου). Διάβασέ τα και εξήγαγε τα στοιχεία του πελάτη.

Επέστρεψε ΜΟΝΟ ένα JSON object — χωρίς markdown, χωρίς σχόλια, χωρίς κείμενο πριν ή μετά. Τα κλειδιά πρέπει να είναι ακριβώς αυτά: {$keys}.

Κανόνες:
- customer_type: ένα από "individual" (ιδιώτης), "sole_prop" (ατομική επιχείρηση), "company" (εταιρεία). Αν αμφιβάλλεις, "individual".
- birth_date: μορφή YYYY-MM-DD. Αν δεν υπάρχει, null.
- afm: 9 ψηφία ΑΦΜ. supply_number: ο αριθμός παροχής/ΗΚΑΣΠ από τον λογαριασμό.
- Διεύθυνση: σπάσε σε street (οδός), street_no (αριθμός), city (πόλη), region (νομός), postal_code (ΤΚ).
- Για ό,τι ΔΕΝ διαβάζεται καθαρά, βάλε null. ΜΗΝ μαντεύεις και ΜΗΝ εφευρίσκεις τιμές.
- Κράτα ελληνικούς χαρακτήρες ως έχουν (μην τους κάνεις transliteration).

Παράδειγμα σχήματος εξόδου:
{"customer_type":"individual","afm":"123456789","doy":"Δ ΑΘΗΝΩΝ","first_name":"ΓΕΩΡΓΙΟΣ","last_name":"ΠΑΠΑΔΟΠΟΥΛΟΣ","father_name":"ΙΩΑΝΝΗΣ","company_name":null,"adt":"ΑΒ123456","birth_date":"1985-04-12","region":"ΑΤΤΙΚΗΣ","city":"ΑΘΗΝΑ","street":"ΕΡΜΟΥ","street_no":"15","postal_code":"10563","phone":null,"mobile":"6900000000","email":null,"supply_number":"0199999990001","meter_number":null,"invoice_code":null}
PROMPT;
	}

	// ---------------------------------------------------------------------
	// File -> Claude content block
	// ---------------------------------------------------------------------
	private static function file_to_block( array $f ): ?array {
		$path = $f['path'] ?? '';
		$mime = $f['mime'] ?? '';
		if ( ! $path || ! file_exists( $path ) ) {
			return null;
		}

		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return null;
		}
		$b64 = base64_encode( $raw );

		// PDFs go as a "document" block; images as "image".
		if ( $mime === 'application/pdf' || str_ends_with( strtolower( $path ), '.pdf' ) ) {
			return [
				'type'   => 'document',
				'source' => [ 'type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64 ],
			];
		}

		$image_mimes = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
		if ( in_array( $mime, $image_mimes, true ) ) {
			return [
				'type'   => 'image',
				'source' => [ 'type' => 'base64', 'media_type' => $mime, 'data' => $b64 ],
			];
		}

		return null;
	}

	// ---------------------------------------------------------------------
	// Parsing helpers
	// ---------------------------------------------------------------------
	private static function parse_json( string $text ): ?array {
		$text = trim( $text );
		// Strip accidental ```json fences if the model adds them.
		$text = preg_replace( '/^```(?:json)?|```$/m', '', $text );
		$text = trim( $text );

		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}

		// Fallback: grab the first {...} block.
		if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
			$data = json_decode( $m[0], true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return null;
	}

	/** Keep only known fields, coerce empties to null, sanitize. */
	private static function normalize( array $data ): array {
		$out = [];
		foreach ( self::fields() as $key ) {
			$val = $data[ $key ] ?? null;
			if ( is_string( $val ) ) {
				$val = trim( $val );
				if ( $val === '' || strtolower( $val ) === 'null' ) {
					$val = null;
				}
			}
			$out[ $key ] = is_scalar( $val ) ? sanitize_text_field( (string) $val ) : null;
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Settings accessors
	// ---------------------------------------------------------------------
	public static function api_key(): string {
		return \EnergyCRM\Services::secrets()->get( 'claude_api_key' );
	}

	public static function model(): string {
		$m = (string) get_option( ECRM_PREFIX . 'claude_model', '' );
		return $m !== '' ? $m : self::DEFAULT_MODEL;
	}
}
