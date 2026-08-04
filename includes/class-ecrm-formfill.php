<?php
/**
 * ECRM_FormFill — fills the official provider application PDFs with the
 * contract/customer data by overlaying text at mapped coordinates.
 *
 * Why overlay (and not AcroForm fields): AcroForm + Greek renders as garbage
 * in most non-Acrobat viewers, because the field font lacks Greek glyphs.
 * Overlaying with the bundled DejaVu (Unicode) font renders Greek correctly
 * everywhere — exactly like ECRM_PDF already does.
 *
 * Templates live in assets/forms/{key}.pdf (qpdf-normalised so the free FPDI
 * parser can read them on any host — no external tools needed in production).
 * Their coordinate maps live in assets/forms/{key}.json (mm, origin top-left).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ECRM_FormFill {

	/** Baseline offset (mm) added to a label's top-y so text sits on its line. */
	const BASELINE = 3.0;

	/**
	 * Resolve a provider name + energy type to a bundled template key.
	 * Returns '' when we don't have a template for that combination yet.
	 */
	public static function template_key( string $provider_name, string $energy_type, string $customer_type = '', string $program = '', string $activation_type = '' ): string {
		$p = self::norm( $provider_name );
		$e = $energy_type;
		$biz = in_array( $customer_type, [ 'company', 'sole_prop' ], true );

		$has = static function ( $needle ) use ( $p ) { return strpos( $p, $needle ) !== false; };

		if ( $has( 'volton' ) && $e === 'power' )                         { return 'volton_he'; }
		if ( $has( 'volton' ) && $e === 'gas' )                           { return 'volton_fa'; }
		if ( ( $has( 'protergia' ) || $has( 'metlen' ) ) && $e === 'power' ) { return $biz ? 'protergia_he_biz' : 'protergia_he'; }
		if ( ( $has( 'protergia' ) || $has( 'metlen' ) ) && $e === 'gas' )   { return 'protergia_fa'; }
		if ( $has( 'nrg' ) && $e === 'power' )                            { return $biz ? 'nrg_he_biz' : 'nrg_he'; }
		if ( $has( 'nrg' ) && $e === 'gas' )                              { return 'nrg_fa'; }
		if ( $has( 'elpedison' ) && $e === 'power' )                      { return 'elpedison_he'; }
		if ( $has( 'enerwave' ) && $e === 'power' )                       { return 'enerwave_he'; }
		if ( $has( 'enerwave' ) && $e === 'gas' )                         { return 'enerwave_fa'; }
		if ( $has( 'zenith' ) || $has( 'ζενιθ' ) || $has( 'zeniθ' ) )      { return 'zenith_he'; }
		if ( $has( 'orizon' ) || $has( 'οριζον' ) ) {
			$prog = self::norm( $program );
			// "family" plan → its own form; new-connection (ενεργοποίηση) → activation
			// contract; otherwise the standard portability (φορητότητα) form.
			if ( strpos( $prog, 'family' ) !== false || strpos( $prog, 'φαμιλυ' ) !== false ) { return 'orizon_family'; }
			if ( $activation_type === 'new_connection' )                                       { return 'orizon_activation'; }
			return 'orizon_port';
		}

		return '';
	}

	/** Lowercase + strip Greek/Latin accents for robust provider matching. */
	private static function norm( string $s ): string {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$map = [ 'ά'=>'α','έ'=>'ε','ή'=>'η','ί'=>'ι','ό'=>'ο','ύ'=>'υ','ώ'=>'ω','ϊ'=>'ι','ϋ'=>'υ','ΐ'=>'ι','ΰ'=>'υ' ];
		return strtr( $s, $map );
	}

	/** True when a filled provider form is available for this contract row. */
	public static function available( array $c ): bool {
		return self::template_key( (string) ( $c['provider_name'] ?? $c['provider'] ?? '' ), (string) ( $c['energy_type'] ?? '' ), (string) ( $c['customer_type'] ?? '' ), (string) ( $c['program_name'] ?? '' ), (string) ( $c['activation_type'] ?? '' ) ) !== '';
	}

	/**
	 * Build the field-name => value dictionary from a joined contract row
	 * (same row shape that ECRM_PDF::build receives).
	 */
	public static function values( array $c ): array {
		// Expanded form fields (legal rep, communication contact, meter/billing) are
		// stored in the contract's extra_json bag — decode so they can be mapped too.
		$x = [];
		if ( ! empty( $c['extra_json'] ) ) {
			$d = json_decode( (string) $c['extra_json'], true );
			if ( is_array( $d ) ) { $x = $d; }
		}
		$xg = static function ( $k ) use ( $x ) { return isset( $x[ $k ] ) ? (string) $x[ $k ] : ''; };

		$contact_name = trim( $xg( 'contact_first_name' ) . ' ' . $xg( 'contact_last_name' ) );
		$rep_name     = trim( $xg( 'rep_first_name' ) . ' ' . $xg( 'rep_last_name' ) );

		$name = trim( (string) ( $c['first_name'] ?? '' ) . ' ' . (string) ( $c['last_name'] ?? '' ) );
		if ( ! empty( $c['company_name'] ) ) { $name = (string) $c['company_name']; }

		$street = trim( (string) ( $c['street'] ?? '' ) . ' ' . (string) ( $c['street_no'] ?? '' ) );

		// Full one-line address for forms that have a single "ΔΙΕΥΘΥΝΣΗ" field
		// (street + number, city, postal code) — used where there is no separate city/TK box.
		$addr_full = $street;
		if ( ! empty( $c['city'] ) )        { $addr_full = trim( $addr_full . ( $addr_full ? ', ' : '' ) . (string) $c['city'] ); }
		if ( ! empty( $c['postal_code'] ) ) { $addr_full = trim( $addr_full . ' ' . (string) $c['postal_code'] ); }

		$partner = '';
		if ( ! empty( $c['partner_user_id'] ) ) {
			$u = get_userdata( (int) $c['partner_user_id'] );
			$partner = $u ? $u->display_name : '';
		}
		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name', '' ) : '';

		$created = ! empty( $c['created_at'] ) ? strtotime( (string) $c['created_at'] ) : 0;
		$diarkeia = ! empty( $c['term_months'] ) ? ( (int) $c['term_months'] . ' μήνες' ) : '';

		// Combined "Τόπος, Ημερομηνία" value for forms that have a single line.
		$topos_imer = trim( (string) ( $c['city'] ?? '' ) );
		if ( $created ) { $topos_imer = trim( $topos_imer . ( $topos_imer ? ', ' : '' ) . gmdate( 'd/m/Y', $created ) ); }

		$tm = (int) ( $c['term_months'] ?? 0 );
		$ct = (string) ( $c['customer_type'] ?? '' );
		$at = (string) ( $c['activation_type'] ?? '' );

		return [
			'onomateponymo'  => $name,
			'eponymo'        => (string) ( $c['last_name'] ?? '' ),
			'onoma'          => (string) ( $c['first_name'] ?? '' ),
			'patronymo'      => (string) ( $c['father_name'] ?? '' ),
			'eponymia'       => (string) ( $c['company_name'] ?? '' ),
			'afm'            => (string) ( $c['afm'] ?? '' ),
			'afm_etaireias'  => (string) ( $c['afm'] ?? '' ),
			'doy'            => (string) ( $c['doy'] ?? '' ),
			'adt'            => (string) ( $c['adt'] ?? '' ),
			'birth_date'     => (string) ( $c['birth_date'] ?? '' ),
			'tilefono'       => (string) ( $c['phone'] ?? '' ),
			'kinito'         => (string) ( $c['mobile'] ?? '' ),
			'email'          => (string) ( $c['email'] ?? '' ),
			'odos'           => $street,
			'dieuthynsi'     => $addr_full,
			'arithmos'       => (string) ( $c['street_no'] ?? '' ),
			'poli'           => (string) ( $c['city'] ?? '' ),
			'tk'             => (string) ( $c['postal_code'] ?? '' ),
			'nomos'          => (string) ( $c['region'] ?? '' ),
			'ar_paroxis'     => (string) ( $c['supply_number'] ?? '' ),
			'hkasp'          => (string) ( $c['supply_number'] ?? '' ),
			'ar_metriti'     => (string) ( $c['meter_number'] ?? '' ),
			'odos_paroxis'   => $addr_full,
			'poli_paroxis'   => (string) ( $c['city'] ?? '' ),
			'tk_paroxis'     => (string) ( $c['postal_code'] ?? '' ),
			'timologio'      => (string) ( $c['invoice_code'] ?? '' ),
			'programma'      => (string) ( $c['program_name'] ?? '' ),
			'diarkeia'       => $diarkeia,
			'ar_aitisis'     => (string) ( $c['code'] ?? '' ),
			'imerominia'     => $created ? gmdate( 'd/m/Y', $created ) : '',
			'end_date'       => ! empty( $c['end_date'] ) ? gmdate( 'd/m/Y', strtotime( (string) $c['end_date'] ) ) : '',
			'topos'          => (string) ( $c['city'] ?? '' ),
			'topos_imerominia' => $topos_imer,
			'synergatis'     => $company,
			'politis'        => $partner,
			'kod_synergati'  => $xg( 'kod_synergati' ),

			// Legal representative (companies).
			'nomimos_ekprosopos' => $rep_name,

			// Communication contact (Υπεύθυνος Επικοινωνίας).
			'contact_onomateponymo' => $contact_name,
			'contact_adt'           => $xg( 'contact_adt' ),
			'contact_afm'           => $xg( 'contact_afm' ),
			'contact_tilefono'      => $xg( 'contact_phone' ),
			'contact_kinito'        => $xg( 'contact_mobile' ),
			'contact_email'         => $xg( 'contact_email' ),

			// Meter / billing extras.
			'ipistamenos_promitheftis' => $xg( 'previous_provider' ),
			'poso_eggiisis'            => $xg( 'guarantee' ),
			'teleftaia_endeixi_imeras' => $xg( 'day_indication' ),
			'isxis_paroxis'            => $xg( 'agreed_power' ),
			'epaggelma'                => $xg( 'activity' ),

			// Checkboxes (engine stamps 'X' when the value is non-empty).
			'cat_idiotis'    => ( $ct === 'individual' ? 'X' : '' ),
			'cat_atomiki'    => ( $ct === 'sole_prop' ? 'X' : '' ),
			'cat_etaireia'   => ( $ct === 'company' ? 'X' : '' ),
			// Supply category: home vs business (derived from customer type).
			'cat_oikiaki'        => ( $ct === 'individual' ? 'X' : '' ),
			'cat_epaggelmatiki'  => ( in_array( $ct, [ 'company', 'sole_prop' ], true ) ? 'X' : '' ),
			// Activation / connection type.
			'act_change'         => ( $at === 'change_provider' ? 'X' : '' ),
			'act_succession'     => ( $at === 'succession' ? 'X' : '' ),
			'act_reconnection'   => ( $at === 'reconnection' ? 'X' : '' ),
			'act_renewal'        => ( $at === 'renewal' ? 'X' : '' ),
			'act_new'            => ( $at === 'new_connection' ? 'X' : '' ),
			'act_program_change' => ( $at === 'program_change' ? 'X' : '' ),
			// Generic "activation required" (new connection or reconnection).
			'act_any'            => ( in_array( $at, [ 'new_connection', 'reconnection' ], true ) ? 'X' : '' ),
			'dur_aoristou'   => ( $tm === 0 ? 'X' : '' ),
			'dur_6'          => ( $tm === 6 ? 'X' : '' ),
			'dur_12'         => ( $tm === 12 ? 'X' : '' ),
			'dur_18'         => ( $tm === 18 ? 'X' : '' ),
			'dur_24'         => ( $tm === 24 ? 'X' : '' ),
			'dur_36'         => ( $tm === 36 ? 'X' : '' ),
		];
	}

	/**
	 * Fill the provider form for a contract row.
	 *
	 * @param array       $c        Joined contract+customer row.
	 * @param string|null $sig_path Optional absolute path to a signature PNG.
	 * @return array{ok:bool,error?:string,bytes?:string,filename?:string}
	 */
	public static function fill( array $c, ?string $sig_path = null ): array {
		$key = self::template_key( (string) ( $c['provider_name'] ?? $c['provider'] ?? '' ), (string) ( $c['energy_type'] ?? '' ), (string) ( $c['customer_type'] ?? '' ), (string) ( $c['program_name'] ?? '' ), (string) ( $c['activation_type'] ?? '' ) );
		if ( $key === '' ) {
			return [ 'ok' => false, 'error' => 'Δεν υπάρχει ακόμη πρότυπο εντύπου για αυτόν τον πάροχο/τύπο παροχής.' ];
		}

		$dir  = ECRM_DIR . 'assets/forms/';
		$mapf = $dir . $key . '.json';
		if ( ! file_exists( $dir . $key . '-1.jpg' ) || ! file_exists( $mapf ) ) {
			return [ 'ok' => false, 'error' => 'Λείπει το αρχείο προτύπου για ' . $key . '.' ];
		}
		$map = json_decode( (string) file_get_contents( $mapf ), true );
		if ( ! is_array( $map ) || empty( $map['fields'] ) ) {
			return [ 'ok' => false, 'error' => 'Άκυρος χάρτης πεδίων.' ];
		}

		$values = self::values( $c );

		// Each template page is bundled as a background image (assets/forms/{key}-{n}.jpg);
		// we overlay the Greek values with tFPDF (DejaVu Unicode). No PDF-import library is
		// used, so this works identically on any host regardless of PDF parser support.
		require_once ECRM_DIR . 'includes/lib/tfpdf/tfpdf.php';

		@ini_set( 'memory_limit', '256M' );
		@set_time_limit( 60 );
		$er = error_reporting();
		error_reporting( 0 );
		try {
			ob_start();

			$w = (float) ( $map['page_w'] ?? 210 );
			$h = (float) ( $map['page_h'] ?? 297 );
			$orient = ( $w > $h ) ? 'L' : 'P';

			$pdf = new tFPDF( $orient, 'mm', [ $w, $h ] );
			$pdf->fontpath = __DIR__ . '/lib/tfpdf/font/';
			$pdf->SetAutoPageBreak( false );
			$pdf->AddFont( 'DejaVu', '', 'DejaVuSans.ttf', true );

			$p = 1;
			while ( file_exists( $dir . $key . '-' . $p . '.jpg' ) ) {
				$pdf->AddPage( $orient, [ $w, $h ] );
				$pdf->Image( $dir . $key . '-' . $p . '.jpg', 0, 0, $w, $h );

				$pdf->SetTextColor( 0, 0, 150 );
				foreach ( $map['fields'] as $field => $pos ) {
					if ( (int) ( $pos['page'] ?? 1 ) !== $p ) { continue; }
					$val = $values[ $field ] ?? '';
					if ( $val === '' ) { continue; }
					if ( ! empty( $pos['check'] ) ) {
						$pdf->SetFont( 'DejaVu', '', 10 );
						$pdf->Text( (float) $pos['x'], (float) $pos['y'] + self::BASELINE, 'X' );
						continue;
					}
					$pdf->SetFont( 'DejaVu', '', (float) ( $pos['size'] ?? 8.5 ) );
					$pdf->Text( (float) $pos['x'], (float) $pos['y'] + self::BASELINE, (string) $val );
				}

				if ( $sig_path && file_exists( $sig_path ) ) {
					// Support multiple signature stamps per template (e.g. a customer
					// signs in two places). Falls back to the single legacy "sig" key.
					$sigs = ( ! empty( $map['sigs'] ) && is_array( $map['sigs'] ) )
						? $map['sigs']
						: ( ! empty( $map['sig'] ) ? [ $map['sig'] ] : [] );
					foreach ( $sigs as $s ) {
						if ( ! is_array( $s ) || (int) ( $s['page'] ?? 0 ) !== $p ) { continue; }
						$pdf->Image( $sig_path, (float) $s['x'], (float) $s['y'], (float) ( $s['w'] ?? 40 ), (float) ( $s['h'] ?? 0 ) );
					}
				}
				$p++;
			}

			$bytes = $pdf->Output( '', 'S' );
			ob_end_clean();
		} catch ( \Throwable $e ) {
			if ( ob_get_level() > 0 ) { ob_end_clean(); }
			error_reporting( $er );
			return [ 'ok' => false, 'error' => 'Σφάλμα δημιουργίας εντύπου: ' . $e->getMessage() ];
		}
		error_reporting( $er );

		$at = strpos( (string) $bytes, '%PDF-' );
		if ( $at === false ) {
			return [ 'ok' => false, 'error' => 'Το έντυπο δεν δημιουργήθηκε σωστά. Πρώτα bytes: ' . substr( (string) $bytes, 0, 300 ) ];
		}
		if ( $at > 0 ) { $bytes = substr( $bytes, $at ); }

		$fname = 'entypo-' . $key . '-' . ( $c['code'] ?? '' ) . '.pdf';
		return [ 'ok' => true, 'bytes' => $bytes, 'filename' => $fname ];
	}
}
