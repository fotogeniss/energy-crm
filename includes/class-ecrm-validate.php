<?php
/**
 * Input validation helpers (Greek-specific).
 *
 * - afm(): Greek ΑΦΜ (VAT) check-digit validation.
 * - supply(): lenient sanity check for ΔΕΔΔΗΕ supply numbers / gas supply ids.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Validate {

	/** Strip everything but digits. */
	public static function digits( string $s ): string {
		return preg_replace( '/\D+/', '', $s );
	}

	/**
	 * Validate a Greek ΑΦΜ via its modulo-11 check digit.
	 * Returns true for a structurally valid 9-digit ΑΦΜ.
	 *
	 * Ο ΙΔΙΟΣ αλγόριθμος ξαναγράφεται σε JS -- validAfm() στο
	 * public/assets/ecrm-form.js -- για στιγμιαίο feedback στο πεδίο πριν
	 * φύγει το αίτημα. Δεν καλείται αυτή η μέθοδος μέσω κάποιου build step·
	 * PHP και JS δεν μοιράζονται κώδικα εδώ, οπότε τα δύο αντίγραφα πρέπει να
	 * αλλάζουν ΜΑΖΙ. Ο τελικός κριτής παραμένει εδώ (βλ. ContractSaveController) --
	 * το JS είναι μόνο πρόβλεψη για τον χρήστη, ποτέ η πύλη.
	 */
	public static function afm( string $afm ): bool {
		$d = self::digits( $afm );
		if ( strlen( $d ) !== 9 || $d === '000000000' ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 8; $i++ ) {
			$sum += (int) $d[ $i ] * ( 1 << ( 8 - $i ) ); // weights 256,128,…,2
		}
		$check = ( $sum % 11 ) % 10;
		return $check === (int) $d[8];
	}

	/**
	 * Lenient supply-number check. Power supply ids are typically 9–13 digits;
	 * gas ids vary. We only flag obvious garbage, never block on save.
	 *
	 * @return array{valid:bool, msg:string}
	 */
	public static function supply( string $supply, string $energy = 'power' ): array {
		$d = self::digits( $supply );
		if ( $supply === '' ) {
			return [ 'valid' => true, 'msg' => '' ];
		}
		$len = strlen( $d );
		if ( $energy === 'power' ) {
			if ( $len < 9 || $len > 13 ) {
				return [ 'valid' => false, 'msg' => 'Ο αριθμός παροχής ρεύματος είναι συνήθως 9–13 ψηφία.' ];
			}
		} else {
			if ( $len < 6 || $len > 16 ) {
				return [ 'valid' => false, 'msg' => 'Ελέγξτε τον αριθμό παροχής.' ];
			}
		}
		return [ 'valid' => true, 'msg' => '' ];
	}
}
