<?php
/**
 * Field-level audit trail.
 *
 * Compares old vs new values when a contract/customer is edited and records a
 * single 'field_change' event on the contract timeline describing what changed
 * and who changed it. Status changes keep their own dedicated events.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EnergyCRM\Domain\Audit\ValueMask;

class ECRM_Audit {

	/** Fields we never surface in the trail (internal/noisy). */
	private static function excluded(): array {
		return [ 'partner_user_id', 'customer_id', 'extracted_json', 'extra_json', 'code',
			'consent_at', 'consent_ip', 'status', 'payout_id', 'created_at', 'updated_at', 'id' ];
	}

	/** Human label for a field key. */
	public static function label( string $k ): string {
		$m = [
			'afm' => 'ΑΦΜ', 'doy' => 'ΔΟΥ', 'adt' => 'ΑΔΤ',
			'first_name' => 'Όνομα', 'last_name' => 'Επίθετο', 'father_name' => 'Πατρώνυμο',
			'company_name' => 'Επωνυμία', 'birth_date' => 'Ημ. γέννησης',
			'region' => 'Νομός', 'city' => 'Πόλη', 'street' => 'Οδός', 'street_no' => 'Αριθμός', 'postal_code' => 'ΤΚ',
			'phone' => 'Τηλέφωνο', 'mobile' => 'Κινητό', 'email' => 'Email',
			'customer_type' => 'Τύπος πελάτη', 'provider_id' => 'Πάροχος', 'program_id' => 'Πρόγραμμα',
			'energy_type' => 'Είδος', 'category' => 'Κατηγορία', 'price_type' => 'Τύπος τιμής',
			'activation_type' => 'Ενεργοποίηση', 'supply_number' => 'Αριθμός παροχής', 'meter_number' => 'Μετρητής',
			'invoice_code' => 'Τιμολόγιο', 'notes' => 'Σημειώσεις',
			'start_date' => 'Έναρξη', 'term_months' => 'Διάρκεια (μήνες)', 'end_date' => 'Λήξη',
		];
		return $m[ $k ] ?? $k;
	}

	/**
	 * Compute changed fields between an old DB row and a new (partial) array.
	 *
	 * @return array<string,array{0:string,1:string}> field => [old, new]
	 */
	public static function diff( array $old, array $new ): array {
		$skip = self::excluded();
		$out  = [];
		foreach ( $new as $k => $v ) {
			if ( in_array( $k, $skip, true ) ) { continue; }
			$ov = array_key_exists( $k, $old ) ? (string) $old[ $k ] : '';
			$nv = (string) ( $v ?? '' );
			if ( trim( $ov ) !== trim( $nv ) ) {
				$out[ $k ] = [ $ov, $nv ];
			}
		}
		return $out;
	}

	/** Short readable value for the message. */
	private static function v( string $s ): string {
		$s = trim( $s );
		if ( $s === '' ) { return '∅'; }
		return mb_strlen( $s ) > 40 ? ( mb_substr( $s, 0, 39 ) . '…' ) : $s;
	}

	/**
	 * Write a single field_change event for a set of diffs.
	 *
	 * Ό,τι περνά από `ValueMask` (τα κρυπτογραφημένα πεδία, βλ. εκεί το
	 * docblock) δεν γράφεται ποτέ σε καθαρό κείμενο εδώ — το ιστορικό
	 * ενεργειών δεν είναι δεύτερη, ανασφάλιστη αντιγραφή της βάσης. Πεδία
	 * χωρίς τιμή στο `ValueMask::isOpaque()` δεν παίρνουν καν βέλος «παλιά →
	 * νέα»: μια μερική μάσκα εκεί θα έλεγε είτε πολλά είτε τίποτα.
	 *
	 * Αφορά μόνο ΝΕΕΣ εγγραφές, ρητή απόφαση 28/08/2026 — βλ.
	 * docs/CHANGELOG.md (168). Ό,τι έχει ήδη γραφτεί καθαρό στο `events`
	 * μένει καθαρό.
	 */
	public static function log( int $contract_id, array $changes, ?int $user_id = null ): void {
		if ( ! $changes ) { return; }
		global $wpdb;
		$parts = [];
		foreach ( $changes as $field => $pair ) {
			if ( ValueMask::isOpaque( $field ) ) {
				$parts[] = sprintf( '%s: %s', self::label( $field ), ValueMask::CHANGED );
				continue;
			}
			$parts[] = sprintf(
				'%s: %s → %s',
				self::label( $field ),
				self::v( ValueMask::apply( $field, $pair[0] ) ),
				self::v( ValueMask::apply( $field, $pair[1] ) )
			);
		}
		$wpdb->insert( ECRM_DB::table( 'events' ), [
			'contract_id' => $contract_id,
			'user_id'     => $user_id ?: get_current_user_id(),
			'type'        => 'field_change',
			'message'     => 'Τροποποίηση — ' . implode( ' · ', $parts ),
		] );
	}
}
