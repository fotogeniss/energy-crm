<?php
/**
 * PDF generator — produces a filled "Αίτηση Σύμβασης" document for a contract.
 *
 * Uses the bundled tFPDF engine + DejaVuSans (Unicode) so Greek renders
 * correctly with no external dependency. The font metric cache is shipped
 * pre-generated, so the server does not need write access to the font dir.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_PDF {

	private static function boot(): void {
		if ( ! defined( 'FPDF_FONTPATH' ) ) {
			// The name is tFPDF's, not ours: the library reads this exact
			// constant. Prefixing it would simply mean the fonts are not found.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			define( 'FPDF_FONTPATH', ECRM_DIR . 'includes/lib/tfpdf/font/' );
		}
		require_once ECRM_DIR . 'includes/lib/tfpdf/tfpdf.php';
	}

	/**
	 * Build the PDF for a contract row (already ownership-checked by caller).
	 *
	 * @param array $c Joined contract+customer row (see ContractRepository::findDetailed).
	 * @return string Raw PDF bytes.
	 */
	public static function build( array $c, ?string $sig_path = null, array $sign_meta = [] ): string {
		self::boot();

		$navy  = [ 10, 31, 61 ];
		$amber = [ 245, 158, 11 ];
		$muted = [ 100, 116, 139 ];

		$pdf = new tFPDF( 'P', 'mm', 'A4' );
		$pdf->fontpath = __DIR__ . '/lib/tfpdf/font/';
		$pdf->SetAutoPageBreak( false );
		$pdf->AddPage();
		$pdf->AddFont( 'DejaVu', '', 'DejaVuSans.ttf', true );
		$pdf->AddFont( 'DejaVu', 'B', 'DejaVuSans-Bold.ttf', true );

		// --- header band ---
		$pdf->SetFillColor( $navy[0], $navy[1], $navy[2] );
		$pdf->Rect( 0, 0, 210, 28, 'F' );
		$pdf->SetFillColor( $amber[0], $amber[1], $amber[2] );
		$pdf->Rect( 0, 28, 210, 1.4, 'F' );

		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : '';
		$logo    = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_logo' ) : '';

		// Optional company logo (top-right), embedded from the media library.
		if ( $logo ) {
			$path = self::logo_path( $logo );
			if ( $path ) {
				try { $pdf->Image( $path, 170, 7, 26 ); } catch ( \Throwable $e ) {}
			}
		}

		$pdf->SetXY( 14, 8 );
		$pdf->SetTextColor( 255, 255, 255 );
		$pdf->SetFont( 'DejaVu', 'B', 16 );
		$pdf->Cell( 0, 8, 'Αίτηση Σύμβασης', 0, 1 );
		$pdf->SetX( 14 );
		$pdf->SetFont( 'DejaVu', '', 10 );
		$pdf->SetTextColor( 203, 213, 225 );
		$code = $c['code'] ?? '';
		$prov = $c['provider_name'] ?? '';
		$pdf->Cell( 0, 6, trim( $code . ( $prov ? '  ·  ' . $prov : '' ) . ( $company ? '  ·  ' . $company : '' ) ), 0, 1 );

		$pdf->SetTextColor( 15, 23, 42 );
		$pdf->Ln( 8 );

		$energy = ECRM_DB::energy_label( (string) ( $c['energy_type'] ?? '' ) );
		$acts   = ECRM_DB::activation_types();
		$st     = ECRM_DB::statuses();
		$addr   = trim( ( $c['street'] ?? '' ) . ' ' . ( $c['street_no'] ?? '' ) );

		// --- section: customer ---
		self::section( $pdf, $navy, 'Στοιχεία Πελάτη' );
		self::grid( $pdf, $muted, [
			[ 'ΑΦΜ', $c['afm'] ?? '' ],            [ 'ΔΟΥ', $c['doy'] ?? '' ],          [ 'ΑΔΤ', $c['adt'] ?? '' ],
			[ 'Όνομα', $c['first_name'] ?? '' ],    [ 'Επίθετο', $c['last_name'] ?? '' ], [ 'Πατρώνυμο', $c['father_name'] ?? '' ],
			[ 'Επωνυμία', $c['company_name'] ?? '' ],[ 'Ημ. Γέννησης', $c['birth_date'] ?? '' ], [ 'Email', $c['email'] ?? '' ],
			[ 'Κινητό', $c['mobile'] ?? '' ],        [ 'Τηλέφωνο', $c['phone'] ?? '' ],   [ 'ΤΚ', $c['postal_code'] ?? '' ],
			[ 'Διεύθυνση', $addr ],                  [ 'Πόλη', $c['city'] ?? '' ],         [ 'Νομός', $c['region'] ?? '' ],
		] );

		// --- section: application ---
		$pdf->Ln( 4 );
		self::section( $pdf, $navy, 'Στοιχεία Αίτησης' );
		self::grid( $pdf, $muted, [
			[ 'Πάροχος', $prov ],                          [ 'Πρόγραμμα', $c['program_name'] ?? '' ], [ 'Είδος', $energy ],
			[ 'Ενεργοποίηση', $acts[ $c['activation_type'] ?? '' ] ?? '' ], [ 'Κατάσταση', $st[ $c['status'] ?? '' ] ?? '' ], [ 'Τιμολόγιο', $c['invoice_code'] ?? '' ],
			[ 'Αριθμός Παροχής', $c['supply_number'] ?? '' ], [ 'Μετρητής', $c['meter_number'] ?? '' ], [ '', '' ],
		] );

		if ( ! empty( $c['notes'] ) ) {
			$pdf->Ln( 4 );
			self::section( $pdf, $navy, 'Σχόλια' );
			$pdf->SetFont( 'DejaVu', '', 10 );
			$pdf->MultiCell( 0, 6, (string) $c['notes'] );
		}

		// --- signature line ---
		$pdf->Ln( 14 );
		$pdf->SetFont( 'DejaVu', '', 10 );
		$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
		$y = $pdf->GetY();
		// Embed the e-signature image above the customer line, if signed.
		if ( $sig_path ) {
			try { $pdf->Image( $sig_path, 30, $y - 12, 50 ); } catch ( \Throwable $e ) {}
		}
		$pdf->Line( 20, $y + 12, 90, $y + 12 );
		$pdf->Line( 120, $y + 12, 190, $y + 12 );
		$pdf->SetXY( 20, $y + 13 );
		$pdf->Cell( 70, 6, 'Υπογραφή πελάτη', 0, 0, 'C' );
		$pdf->SetXY( 120, $y + 13 );
		if ( ! empty( $sign_meta['date'] ) ) {
			$pdf->Cell( 70, 6, (string) $sign_meta['date'], 0, 0, 'C' );
		} else {
			$pdf->Cell( 70, 6, 'Ημερομηνία', 0, 0, 'C' );
		}
		// Audit note for electronic signature.
		if ( $sig_path ) {
			$pdf->SetXY( 20, $y + 20 );
			$pdf->SetFont( 'DejaVu', '', 8 );
			$pdf->SetTextColor( 120, 130, 150 );
			$audit = 'Υπογράφηκε ηλεκτρονικά';
			if ( ! empty( $sign_meta['date'] ) ) { $audit .= ' στις ' . $sign_meta['date']; }
			if ( ! empty( $sign_meta['ip'] ) )   { $audit .= ' · IP ' . $sign_meta['ip']; }
			$audit .= ' · Συναίνεση επεξεργασίας προσωπικών δεδομένων (GDPR)';
			$pdf->MultiCell( 170, 4, $audit );
		}

		// --- footer note ---
		$pdf->SetY( -16 );
		$pdf->SetFont( 'DejaVu', '', 8 );
		$pdf->SetTextColor( 148, 163, 184 );
		$footer = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'pdf_footer' ) : '';
		$info   = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_info' ) : '';
		$line   = $footer ?: ( $info ?: 'Δημιουργήθηκε από Energy CRM' );
		$pdf->Cell( 0, 5, $line . ' · ' . gmdate( 'd/m/Y H:i' ), 0, 0, 'C' );

		return $pdf->Output( '', 'S' );
	}

	/**
	 * Build a commission settlement statement PDF.
	 *
	 * @param array $meta { partner, period, status, paid_at, payout_id }
	 * @param array $lines List of [ code, customer, provider, amount ].
	 * @return string Raw PDF bytes.
	 */
	public static function build_statement( array $meta, array $lines ): string {
		self::boot();

		$navy  = [ 10, 31, 61 ];
		$amber = [ 245, 158, 11 ];
		$muted = [ 100, 116, 139 ];

		$pdf = new tFPDF( 'P', 'mm', 'A4' );
		$pdf->fontpath = __DIR__ . '/lib/tfpdf/font/';
		$pdf->SetAutoPageBreak( true, 18 );
		$pdf->AddPage();
		$pdf->AddFont( 'DejaVu', '', 'DejaVuSans.ttf', true );
		$pdf->AddFont( 'DejaVu', 'B', 'DejaVuSans-Bold.ttf', true );

		// header band
		$pdf->SetFillColor( $navy[0], $navy[1], $navy[2] );
		$pdf->Rect( 0, 0, 210, 28, 'F' );
		$pdf->SetFillColor( $amber[0], $amber[1], $amber[2] );
		$pdf->Rect( 0, 28, 210, 1.4, 'F' );

		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : '';
		$logo    = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_logo' ) : '';
		if ( $logo ) {
			$path = self::logo_path( $logo );
			if ( $path ) { try { $pdf->Image( $path, 170, 7, 26 ); } catch ( \Throwable $e ) {} }
		}

		$pdf->SetXY( 14, 8 );
		$pdf->SetTextColor( 255, 255, 255 );
		$pdf->SetFont( 'DejaVu', 'B', 16 );
		$pdf->Cell( 0, 8, 'Κατάσταση Προμηθειών', 0, 1 );
		$pdf->SetX( 14 );
		$pdf->SetFont( 'DejaVu', '', 10 );
		$pdf->SetTextColor( 203, 213, 225 );
		$sub = 'Εκκαθάριση #' . ( $meta['payout_id'] ?? '' ) . ( $company ? '  ·  ' . $company : '' );
		$pdf->Cell( 0, 6, $sub, 0, 1 );

		$pdf->SetTextColor( 15, 23, 42 );
		$pdf->Ln( 8 );

		// meta block
		$st_label = ( ( $meta['status'] ?? '' ) === 'paid' ) ? 'Πληρωμένη' : 'Εκκρεμεί';
		self::section( $pdf, $navy, 'Στοιχεία Εκκαθάρισης' );
		self::grid( $pdf, $muted, [
			[ 'Συνεργάτης', $meta['partner'] ?? '' ],
			[ 'Περίοδος', $meta['period'] ?? '' ],
			[ 'Κατάσταση', $st_label ],
			[ 'Ημ. Πληρωμής', ! empty( $meta['paid_at'] ) ? mysql2date( 'd/m/Y', $meta['paid_at'] ) : '—' ],
			[ 'Πλήθος Συμβάσεων', (string) count( $lines ) ],
			[ 'Έκδοση', gmdate( 'd/m/Y H:i' ) ],
		] );

		$pdf->Ln( 4 );
		self::section( $pdf, $navy, 'Αναλυτικά' );

		// table header
		$pdf->SetFont( 'DejaVu', 'B', 9 );
		$pdf->SetFillColor( 241, 245, 249 );
		$pdf->SetTextColor( $navy[0], $navy[1], $navy[2] );
		$w = [ 30, 78, 52, 22 ]; // code, customer, provider, amount = 182
		$pdf->Cell( $w[0], 8, 'Κωδικός', 0, 0, 'L', true );
		$pdf->Cell( $w[1], 8, 'Πελάτης', 0, 0, 'L', true );
		$pdf->Cell( $w[2], 8, 'Πάροχος', 0, 0, 'L', true );
		$pdf->Cell( $w[3], 8, 'Ποσό €', 0, 1, 'R', true );

		$pdf->SetFont( 'DejaVu', '', 9 );
		$pdf->SetTextColor( 15, 23, 42 );
		$total = 0.0; $i = 0;
		foreach ( $lines as $ln ) {
			$amt = (float) ( $ln['amount'] ?? 0 );
			$total += $amt;
			if ( $i % 2 === 1 ) {
				$pdf->SetFillColor( 249, 250, 251 );
				$fill = true;
			} else {
				$fill = false;
			}
			$pdf->Cell( $w[0], 7, self::clip( (string) ( $ln['code'] ?? '' ), 16 ), 0, 0, 'L', $fill );
			$pdf->Cell( $w[1], 7, self::clip( (string) ( $ln['customer'] ?? '' ), 42 ), 0, 0, 'L', $fill );
			$pdf->Cell( $w[2], 7, self::clip( (string) ( $ln['provider'] ?? '' ), 28 ), 0, 0, 'L', $fill );
			$pdf->Cell( $w[3], 7, number_format( $amt, 2 ), 0, 1, 'R', $fill );
			$i++;
		}
		if ( ! $lines ) {
			$pdf->Cell( array_sum( $w ), 7, 'Καμία σύμβαση.', 0, 1, 'C' );
		}

		// total row
		$pdf->SetFont( 'DejaVu', 'B', 11 );
		$pdf->SetTextColor( $navy[0], $navy[1], $navy[2] );
		$pdf->Cell( $w[0] + $w[1] + $w[2], 10, 'ΣΥΝΟΛΟ', 0, 0, 'R' );
		$pdf->Cell( $w[3], 10, number_format( $total, 2 ) . ' €', 0, 1, 'R' );

		// footer
		$pdf->SetY( -16 );
		$pdf->SetFont( 'DejaVu', '', 8 );
		$pdf->SetTextColor( 148, 163, 184 );
		$footer = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'pdf_footer' ) : '';
		$info   = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_info' ) : '';
		$line   = $footer ?: ( $info ?: 'Δημιουργήθηκε από Energy CRM' );
		$pdf->Cell( 0, 5, $line, 0, 0, 'C' );

		return $pdf->Output( '', 'S' );
	}

	/**
	 * (376) Οικονομική Προσφορά: μία σελίδα Α4, λευκή, σαν επιστολή εταιρείας.
	 * docs/UI-QUOTE-PDF.html (εγκρίθηκε 13/11/2026).
	 *
	 * Πάνω λογότυπο και στοιχεία εταιρείας, «Προς» τον πελάτη και ο σύμβουλος,
	 * η πρόταση, η σύγκριση (ή μόνο το κόστος της πρότασης όταν δεν υπάρχει
	 * τιμή σήμερα), το αποτέλεσμα, όροι, υπογραφές, υποσέλιδο. Ελληνικοί
	 * αριθμοί και κεφαλαία χωρίς τόνους. Πάντα μία σελίδα: το υποσέλιδο
	 * γράφεται με κλειστό το auto page break (πριν έπεφτε σε δεύτερη σελίδα).
	 *
	 * @param array $m Computed figures (see EnergyCRM\Http\QuoteController).
	 * @return string Raw PDF bytes.
	 */
	public static function build_quote( array $m ): string {
		self::boot();
		$navy  = [ 10, 31, 61 ];
		$ink   = [ 15, 23, 42 ];
		$muted = [ 100, 116, 139 ];
		$soft  = [ 148, 163, 184 ];
		$band  = [ 245, 247, 250 ];
		$rule  = [ 226, 232, 240 ];
		$green = [ 22, 163, 74 ];

		$caps = static fn ( string $s ): string => \EnergyCRM\Domain\Document\GreekCaps::upper( $s );
		$num  = static fn ( float $v, int $d ): string => \EnergyCRM\Domain\Quote\QuoteText::number( $v, $d );
		$eur  = static fn ( float $v ): string => \EnergyCRM\Domain\Quote\QuoteText::euro( $v );
		$sgn  = static fn ( float $v ): string => \EnergyCRM\Domain\Quote\QuoteText::signedEuro( $v );

		$pdf = new tFPDF( 'P', 'mm', 'A4' );
		$pdf->fontpath = __DIR__ . '/lib/tfpdf/font/';
		$pdf->SetMargins( 14, 14, 14 );
		$pdf->SetAutoPageBreak( false );
		$pdf->AddPage();
		$pdf->AddFont( 'DejaVu', '', 'DejaVuSans.ttf', true );
		$pdf->AddFont( 'DejaVu', 'B', 'DejaVuSans-Bold.ttf', true );

		$color = static function ( array $c ) use ( $pdf ): void {
			$pdf->SetTextColor( $c[0], $c[1], $c[2] );
		};

		$company = class_exists( 'ECRM_Admin' ) ? trim( (string) ECRM_Admin::get( 'company_name' ) ) : '';
		$info    = class_exists( 'ECRM_Admin' ) ? trim( (string) ECRM_Admin::get( 'company_info' ) ) : '';
		$footer  = class_exists( 'ECRM_Admin' ) ? trim( (string) ECRM_Admin::get( 'pdf_footer' ) ) : '';
		$logo    = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_logo' ) : '';
		$company = $company !== '' ? $company : 'Energy CRM';
		$info    = trim( (string) preg_replace( '/\s*\R\s*/u', ' · ', $info ) );
		$is_gas  = ( $m['energy_key'] ?? 'power' ) === 'gas';

		// --- Κεφαλίδα: λογότυπο και εταιρεία αριστερά, τίτλος δεξιά ---
		$text_x = 14;
		$path   = $logo ? self::logo_path( $logo ) : null;
		if ( $path ) {
			$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$w    = ( $size && $size[1] > 0 ) ? min( 40.0, 14 * $size[0] / $size[1] ) : 14.0;
			try {
				$pdf->Image( $path, 14, 14, $w, 14 );
				$text_x = 14 + $w + 4;
			} catch ( \Throwable $e ) {
				$path = null;
			}
		}
		if ( ! $path ) {
			$pdf->SetFillColor( $navy[0], $navy[1], $navy[2] );
			$pdf->Rect( 14, 14, 14, 14, 'F' );
			$pdf->SetXY( 14, 14 );
			$pdf->SetFont( 'DejaVu', 'B', 13 );
			$pdf->SetTextColor( 255, 255, 255 );
			$pdf->Cell( 14, 14, mb_substr( $caps( $company ), 0, 1 ), 0, 0, 'C' );
			$text_x = 32;
		}

		$pdf->SetXY( $text_x, 15 );
		$pdf->SetFont( 'DejaVu', 'B', 12 );
		$color( $navy );
		$pdf->Cell( 108 - $text_x, 6, self::clip( $company, 40 ), 0, 2 );
		if ( $info !== '' ) {
			$pdf->SetFont( 'DejaVu', '', 7.5 );
			$color( $muted );
			$pdf->MultiCell( 108 - $text_x, 3.6, self::clip( $info, 180 ), 0, 'L' );
		}
		$left_bottom = max( 28.0, $pdf->GetY() );

		$pdf->SetXY( 110, 14 );
		$pdf->SetFont( 'DejaVu', 'B', 15 );
		$color( $navy );
		$pdf->Cell( 86, 8, 'Οικονομική Προσφορά', 0, 2, 'R' );
		$pdf->SetFont( 'DejaVu', '', 8.5 );
		$color( $muted );
		$pdf->Cell( 86, 4.5, $is_gas ? 'Προμήθεια φυσικού αερίου' : 'Προμήθεια ηλεκτρικής ενέργειας', 0, 2, 'R' );
		$pdf->Cell( 86, 4.5, 'Ημερομηνία ' . ( $m['date'] ?? gmdate( 'd/m/Y' ) ), 0, 2, 'R' );

		$y = max( $left_bottom, $pdf->GetY() ) + 4;
		$pdf->SetDrawColor( $navy[0], $navy[1], $navy[2] );
		$pdf->SetLineWidth( 0.6 );
		$pdf->Line( 14, $y, 196, $y );
		$pdf->SetLineWidth( 0.2 );

		$label = static function ( float $x, float $y, string $text ) use ( $pdf, $muted, $caps ): void {
			$pdf->SetXY( $x, $y );
			$pdf->SetFont( 'DejaVu', 'B', 7 );
			$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
			$pdf->Cell( 80, 4, $caps( $text ), 0, 0 );
		};

		// --- Προς / Σύμβουλος ---
		$y += 7;
		$label( 14, $y, 'Προς' );
		$pdf->SetXY( 14, $y + 5 );
		$pdf->SetFont( 'DejaVu', 'B', 11 );
		$color( $ink );
		$pdf->Cell( 96, 6, self::clip( (string) ( $m['customer'] ?: '—' ), 44 ), 0, 2 );
		$pdf->SetFont( 'DejaVu', '', 8.5 );
		$color( $muted );
		$supply = ( $m['category'] ?? '' ) === 'business' ? 'Επαγγελματική παροχή' : ( ( $m['category'] ?? '' ) === 'home' ? 'Οικιακή παροχή' : 'Παροχή' );
		$pdf->Cell( 96, 5, $supply . ' · ' . ( $m['energy'] ?? '' ), 0, 2 );

		$label( 118, $y, 'Σύμβουλος' );
		$agent = array_filter( [
			[ 'Όνομα', (string) ( $m['agent_name'] ?? '' ) ],
			[ 'Email', (string) ( $m['agent_email'] ?? '' ) ],
		], static fn ( array $p ): bool => $p[1] !== '' );
		$ay = $y + 5;
		foreach ( $agent as $p ) {
			$pdf->SetXY( 118, $ay );
			$pdf->SetFont( 'DejaVu', '', 8.5 );
			$color( $muted );
			$pdf->Cell( 20, 5, $p[0], 0, 0 );
			$pdf->SetFont( 'DejaVu', 'B', 8.5 );
			$color( $ink );
			$pdf->Cell( 58, 5, self::clip( $p[1], 34 ), 0, 0, 'R' );
			$ay += 5;
		}

		$y = max( $pdf->GetY() + 5, $ay ) + 6;

		// --- Πίνακες ---
		$head = static function ( array $cols, float $y ) use ( $pdf, $band, $navy, $caps ): void {
			$pdf->SetXY( 14, $y );
			$pdf->SetFillColor( $band[0], $band[1], $band[2] );
			$pdf->SetFont( 'DejaVu', 'B', 7 );
			$pdf->SetTextColor( $navy[0], $navy[1], $navy[2] );
			foreach ( $cols as $i => $c ) {
				$pdf->Cell( $c[1], 7, $caps( $c[0] ), 0, 0, $i === 0 ? 'L' : 'R', true );
			}
		};
		$row = static function ( array $cols, array $vals, float $y, bool $total = false ) use ( $pdf, $ink, $navy, $rule ): float {
			$h = $total ? 9 : 7.5;
			$pdf->SetXY( 14, $y );
			$pdf->SetFont( 'DejaVu', $total ? 'B' : '', $total ? 10 : 8.5 );
			$c = $total ? $navy : $ink;
			$pdf->SetTextColor( $c[0], $c[1], $c[2] );
			foreach ( $cols as $i => $col ) {
				$pdf->Cell( $col[1], $h, (string) ( $vals[ $i ] ?? '' ), 0, 0, $i === 0 ? 'L' : 'R' );
			}
			if ( $total ) {
				$pdf->SetDrawColor( $navy[0], $navy[1], $navy[2] );
				$pdf->SetLineWidth( 0.4 );
				$pdf->Line( 14, $y, 196, $y );
				$pdf->SetLineWidth( 0.2 );
			} else {
				$pdf->SetDrawColor( $rule[0], $rule[1], $rule[2] );
				$pdf->Line( 14, $y + $h, 196, $y + $h );
			}
			return $y + $h;
		};

		$cons     = (float) $m['consumption'];
		$tariff   = (string) ( $m['tariff'] ?? '' );
		$label( 14, $y, 'Η πρόταση' );
		$y += 5;
		$pcols = [ [ 'Πάροχος', 46 ], [ 'Πρόγραμμα', 72 ], [ 'Τιμολόγιο', 28 ], [ 'Κατανάλωση / έτος', 36 ] ];
		$head( $pcols, $y );
		$y = $row( $pcols, [
			self::clip( (string) ( $m['provider'] ?: '—' ), 24 ),
			self::clip( (string) ( $m['program'] ?: '—' ), 40 ),
			$tariff !== '' ? $tariff : '—',
			$num( $cons, 0 ) . ' kWh',
		], $y + 7 );

		$cur_price = (float) $m['current_price'];
		$cur_fixed = (float) $m['current_fixed'];
		$off_price = (float) $m['offered_price'];
		$off_fixed = (float) $m['offered_fixed'];
		$has_cur   = $cur_price > 0 || $cur_fixed > 0;

		$y += 7;
		$label( 14, $y, $has_cur ? 'Σύγκριση ετήσιου κόστους' : 'Εκτιμώμενο ετήσιο κόστος' );
		$y += 5;
		$kwh = $num( $cons, 0 ) . ' kWh';
		if ( $has_cur ) {
			$ccols = [ [ '', 64 ], [ 'Σήμερα', 40 ], [ 'Με την πρόταση', 42 ], [ 'Διαφορά', 36 ] ];
			$head( $ccols, $y );
			$y += 7;
			$dp = $off_price - $cur_price;
			$y  = $row( $ccols, [ 'Τιμή ενέργειας', $num( $cur_price, 5 ) . ' €/kWh', $num( $off_price, 5 ) . ' €/kWh', ( $dp < 0 ? '−' : ( $dp > 0 ? '+' : '' ) ) . $num( abs( $dp ), 5 ) ], $y );
			$y  = $row( $ccols, [ 'Πάγιο', $num( $cur_fixed, 2 ) . ' €/μήνα', $num( $off_fixed, 2 ) . ' €/μήνα', $sgn( $off_fixed - $cur_fixed ) ], $y );
			$y  = $row( $ccols, [ 'Ενέργεια (' . $kwh . ')', $eur( $cons * $cur_price ), $eur( $cons * $off_price ), $sgn( $cons * ( $off_price - $cur_price ) ) ], $y );
			$y  = $row( $ccols, [ 'Πάγιο (12 μήνες)', $eur( 12 * $cur_fixed ), $eur( 12 * $off_fixed ), $sgn( 12 * ( $off_fixed - $cur_fixed ) ) ], $y );
			$y  = $row( $ccols, [ 'Εκτιμώμενο ετήσιο κόστος', $eur( (float) $m['current_annual'] ), $eur( (float) $m['offered_annual'] ), $sgn( (float) $m['offered_annual'] - (float) $m['current_annual'] ) ], $y, true );
		} else {
			$ccols = [ [ '', 112 ], [ 'Με την πρόταση', 70 ] ];
			$head( $ccols, $y );
			$y += 7;
			$y  = $row( $ccols, [ 'Τιμή ενέργειας', $num( $off_price, 5 ) . ' €/kWh' ], $y );
			$y  = $row( $ccols, [ 'Πάγιο', $num( $off_fixed, 2 ) . ' €/μήνα' ], $y );
			$y  = $row( $ccols, [ 'Ενέργεια (' . $kwh . ')', $eur( $cons * $off_price ) ], $y );
			$y  = $row( $ccols, [ 'Πάγιο (12 μήνες)', $eur( 12 * $off_fixed ) ], $y );
			$y  = $row( $ccols, [ 'Εκτιμώμενο ετήσιο κόστος', $eur( (float) $m['offered_annual'] ) ], $y, true );
		}

		// --- Το αποτέλεσμα ---
		$y    += 7;
		$save  = (float) $m['savings'];
		$gain  = $has_cur && $save > 0.005;
		if ( $gain ) {
			$title = 'Εκτιμώμενη ετήσια εξοικονόμηση';
			$big   = $eur( $save );
			$side  = [ $num( (float) $m['pct'], 1 ) . '% λιγότερο', '≈ ' . $eur( $save / 12 ) . ' τον μήνα' ];
		} elseif ( $has_cur ) {
			$title = 'Εκτιμώμενη ετήσια διαφορά';
			$big   = $sgn( -$save );
			$side  = [ $save < -0.005 ? 'πιο ακριβό από σήμερα' : 'ίδιο κόστος με σήμερα', '' ];
		} else {
			$title = 'Εκτιμώμενο ετήσιο κόστος';
			$big   = $eur( (float) $m['offered_annual'] );
			$side  = [ '≈ ' . $eur( (float) $m['offered_annual'] / 12 ) . ' τον μήνα', '' ];
		}
		$fill   = $gain ? $navy : $band;
		$stripe = $gain ? $green : $navy;
		$pdf->SetFillColor( $fill[0], $fill[1], $fill[2] );
		$pdf->Rect( 14, $y, 182, 20, 'F' );
		$pdf->SetFillColor( $stripe[0], $stripe[1], $stripe[2] );
		$pdf->Rect( 14, $y, 1.6, 20, 'F' );
		$pdf->SetXY( 20, $y + 3.5 );
		$pdf->SetFont( 'DejaVu', '', 7.5 );
		if ( $gain ) {
			$pdf->SetTextColor( 203, 213, 225 );
		} else {
			$color( $muted );
		}
		$pdf->Cell( 110, 4, $caps( $title ), 0, 2 );
		$pdf->SetFont( 'DejaVu', 'B', 18 );
		if ( $gain ) {
			$pdf->SetTextColor( 255, 255, 255 );
		} else {
			$color( $navy );
		}
		$pdf->Cell( 110, 9, $big, 0, 0 );
		$pdf->SetFont( 'DejaVu', '', 9 );
		if ( $gain ) {
			$pdf->SetTextColor( 203, 213, 225 );
		} else {
			$color( $muted );
		}
		$pdf->SetXY( 120, $y + ( $side[1] !== '' ? 5.5 : 8 ) );
		$pdf->Cell( 72, 4.5, $side[0], 0, 2, 'R' );
		if ( $side[1] !== '' ) {
			$pdf->Cell( 72, 4.5, $side[1], 0, 2, 'R' );
		}
		$y += 20;

		// --- Όροι ---
		$y += 7;
		$pdf->SetXY( 14, $y );
		$pdf->SetFont( 'DejaVu', 'B', 8.5 );
		$color( $ink );
		$pdf->Cell( 0, 5, 'Όροι και σημειώσεις', 0, 2 );
		$notes = [];
		if ( ! $has_cur ) {
			$notes[] = 'Χωρίς στοιχεία του σημερινού λογαριασμού δεν γίνεται σύγκριση· φαίνεται μόνο το κόστος της πρότασης.';
		}
		$notes[] = 'Η εκτίμηση αφορά τη χρέωση προμήθειας (ενέργεια και πάγιο) για τη δηλωμένη κατανάλωση. Ρυθμιζόμενες χρεώσεις, ΥΚΩ, φόροι και δημοτικά τέλη δεν περιλαμβάνονται, γιατί είναι ίδια σε όλους τους παρόχους.';
		$notes[] = 'Τιμές του παρόχου κατά την ημερομηνία έκδοσης. Οι τελικοί όροι είναι αυτοί της σύμβασης κατά την ενεργοποίηση.';
		$notes[] = 'Η προσφορά είναι ενημερωτική και δεν αποτελεί σύμβαση.';
		$pdf->SetFont( 'DejaVu', '', 7.5 );
		$color( $muted );
		foreach ( $notes as $i => $note ) {
			$pdf->SetX( 14 );
			$pdf->Cell( 5, 4, ( $i + 1 ) . '.', 0, 0 );
			$pdf->MultiCell( 177, 4, $note, 0, 'L' );
		}

		// --- Υπογραφές ---
		$y = max( $pdf->GetY() + 16, 240.0 );
		$y = min( $y, 262.0 );
		$pdf->SetDrawColor( $soft[0], $soft[1], $soft[2] );
		$pdf->Line( 14, $y, 94, $y );
		$pdf->Line( 116, $y, 196, $y );
		$sign = static function ( float $x, string $who, string $sub ) use ( $pdf, $ink, $muted, $y ): void {
			$pdf->SetXY( $x, $y + 1.5 );
			$pdf->SetFont( 'DejaVu', 'B', 8.5 );
			$pdf->SetTextColor( $ink[0], $ink[1], $ink[2] );
			$pdf->Cell( 80, 4.5, $who, 0, 2 );
			$pdf->SetFont( 'DejaVu', '', 7.5 );
			$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
			$pdf->Cell( 80, 4, $sub, 0, 2 );
		};
		$agent_name = (string) ( $m['agent_name'] ?? '' );
		$sign( 14, 'Για την εταιρεία', $agent_name !== '' ? self::clip( $agent_name, 40 ) . ', σύμβουλος ενέργειας' : $company );
		$sign( 116, 'Ο πελάτης', 'Έλαβα γνώση' );

		// --- Υποσέλιδο: πάντα στην ίδια σελίδα ---
		$pdf->SetDrawColor( $rule[0], $rule[1], $rule[2] );
		$pdf->Line( 14, 283, 196, 283 );
		$pdf->SetXY( 14, 284.5 );
		$pdf->SetFont( 'DejaVu', '', 7 );
		$color( $soft );
		$foot = $footer !== '' ? $footer : trim( $company . ( $info !== '' ? ' · ' . $info : '' ) );
		$pdf->Cell( 150, 4, self::clip( $foot, 120 ), 0, 0 );
		$pdf->Cell( 32, 4, 'Σελίδα 1 από 1', 0, 0, 'R' );

		return $pdf->Output( '', 'S' );
	}

	/** Truncate a string to n chars with an ellipsis (multibyte-safe). */
	/**
	 * (354b) Αποδεικτικό ηλεκτρονικής υπογραφής — docs/UI-SIGN-AFM.html, οθόνη 4.
	 *
	 * Ένα A4, λευκό, χωρίς τη σκούρα ταινία των άλλων εντύπων: είναι πρακτικό
	 * έγγραφο αρχείου, όχι έντυπο προς τον πελάτη. Τα δεδομένα έρχονται έτοιμα
	 * (κρυμμένο ΑΦΜ/IP, ώρες ως κείμενο) από το ECRM_Tracking — εδώ μόνο
	 * στοιχειοθετούνται.
	 *
	 * @param array{
	 *   code:string, provider:string, signer:string, afm:string, partner:string,
	 *   timeline:list<array{0:string,1:string}>, ip:string, device:string,
	 *   consent:string, sig_path:?string, hash_doc:string, hash_sig:string, note:string
	 * } $m
	 * @return string Raw PDF bytes.
	 */
	public static function build_sign_proof( array $m ): string {
		self::boot();

		$ink   = [ 27, 29, 26 ];
		$muted = [ 95, 99, 93 ];
		$line  = [ 227, 229, 225 ];
		$green = [ 10, 107, 29 ];

		$pdf = new tFPDF( 'P', 'mm', 'A4' );
		$pdf->fontpath = __DIR__ . '/lib/tfpdf/font/';
		$pdf->SetMargins( 18, 18, 18 );
		$pdf->SetAutoPageBreak( true, 18 );
		$pdf->AddPage();
		$pdf->AddFont( 'DejaVu', '', 'DejaVuSans.ttf', true );
		$pdf->AddFont( 'DejaVu', 'B', 'DejaVuSans-Bold.ttf', true );

		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : '';

		$pdf->SetTextColor( $ink[0], $ink[1], $ink[2] );
		$pdf->SetFont( 'DejaVu', 'B', 17 );
		$pdf->Cell( 0, 9, 'Αποδεικτικό ηλεκτρονικής υπογραφής', 0, 1 );
		$pdf->SetFont( 'DejaVu', '', 9 );
		$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
		$pdf->Cell( 0, 5, ( $company ? $company . '  ·  ' : '' ) . 'παράχθηκε αυτόματα με την υπογραφή', 0, 1 );

		$rule = static function ( string $title ) use ( $pdf, $muted, $line ): void {
			$pdf->Ln( 4 );
			$pdf->SetDrawColor( $line[0], $line[1], $line[2] );
			$y = $pdf->GetY();
			$pdf->Line( 18, $y, 192, $y );
			$pdf->Ln( 3 );
			$pdf->SetFont( 'DejaVu', 'B', 8.5 );
			$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
			$pdf->Cell( 0, 5, $title, 0, 1 );
		};
		$kv = static function ( string $k, string $v, ?array $color = null ) use ( $pdf, $ink, $muted ): void {
			$pdf->SetFont( 'DejaVu', '', 9.5 );
			$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
			$pdf->Cell( 46, 6, $k, 0, 0 );
			$pdf->SetFont( 'DejaVu', 'B', 9.5 );
			$c = $color ?? $ink;
			$pdf->SetTextColor( $c[0], $c[1], $c[2] );
			$pdf->MultiCell( 0, 6, $v !== '' ? $v : '—', 0, 'L' );
		};

		$rule( 'Αίτηση' );
		$kv( 'Κωδικός', (string) $m['code'] );
		$kv( 'Πάροχος / πρόγραμμα', (string) $m['provider'] );
		$kv( 'Υπογράφων', (string) $m['signer'] );
		$kv( 'ΑΦΜ', (string) $m['afm'] . '  ✓ ταιριάζει με την αίτηση', $green );
		$kv( 'Συνεργάτης', (string) $m['partner'] );

		$rule( 'Χρονολόγιο' );
		foreach ( (array) $m['timeline'] as $row ) {
			$pdf->SetFont( 'DejaVu', '', 9.5 );
			$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
			$pdf->Cell( 46, 6, (string) $row[0], 0, 0 );
			$pdf->SetTextColor( $ink[0], $ink[1], $ink[2] );
			$pdf->MultiCell( 0, 6, (string) $row[1], 0, 'L' );
		}

		$rule( 'Συσκευή' );
		$kv( 'IP', (string) $m['ip'] );
		$kv( 'Συσκευή', (string) $m['device'] );

		$rule( 'Κείμενο συναίνεσης που αποδέχτηκε ο πελάτης' );
		$pdf->SetFillColor( 244, 245, 243 );
		$pdf->SetFont( 'DejaVu', '', 9 );
		$pdf->SetTextColor( $ink[0], $ink[1], $ink[2] );
		$pdf->MultiCell( 0, 5, (string) $m['consent'], 0, 'L', true );

		$rule( 'Υπογραφή' );
		$y = $pdf->GetY();
		if ( $y > 240 ) {
			$pdf->AddPage();
			$y = $pdf->GetY();
		}
		$pdf->SetDrawColor( $line[0], $line[1], $line[2] );
		$pdf->Rect( 18, $y, 70, 24 );
		if ( ! empty( $m['sig_path'] ) && is_readable( (string) $m['sig_path'] ) ) {
			try { $pdf->Image( (string) $m['sig_path'], 20, $y + 1, 66, 22 ); } catch ( \Throwable $e ) {}
		}
		$pdf->SetY( $y + 26 );

		$rule( 'Αποτύπωμα (SHA-256)' );
		$hash = static function ( string $k, string $v ) use ( $pdf, $muted ): void {
			$pdf->SetFont( 'DejaVu', '', 9 );
			$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
			$pdf->Cell( 46, 5, $k, 0, 0 );
			$pdf->SetFont( 'DejaVu', '', 7.5 );
			$pdf->MultiCell( 0, 5, $v !== '' ? $v : '—', 0, 'L' );
		};
		$hash( 'Υπογεγραμμένο έντυπο', (string) $m['hash_doc'] );
		$hash( 'Εικόνα υπογραφής', (string) $m['hash_sig'] );

		$pdf->Ln( 4 );
		$pdf->SetFont( 'DejaVu', '', 8 );
		$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
		$foot = 'Αν το υπογεγραμμένο έντυπο αλλάξει έστω και ένα byte, το αποτύπωμα παύει να ταιριάζει.';
		if ( ! empty( $m['note'] ) ) {
			$foot = (string) $m['note'] . ' ' . $foot;
		}
		$pdf->MultiCell( 0, 4.5, $foot, 0, 'L' );

		return $pdf->Output( '', 'S' );
	}

	private static function clip( string $s, int $n ): string {
		return ( mb_strlen( $s ) > $n ) ? ( mb_substr( $s, 0, $n - 1 ) . '…' ) : $s;
	}

	/** Resolve a media-library URL to a local file path for embedding. */
	private static function logo_path( string $url ): ?string {		$id = function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $url ) : 0;
		if ( $id ) {
			$file = get_attached_file( $id );
			if ( $file && file_exists( $file ) ) {
				$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
				if ( in_array( $ext, [ 'png', 'jpg', 'jpeg', 'gif' ], true ) ) {
					return $file;
				}
			}
		}
		return null;
	}

	private static function section( $pdf, array $navy, string $title ): void {
		$pdf->SetFont( 'DejaVu', 'B', 11 );
		$pdf->SetTextColor( $navy[0], $navy[1], $navy[2] );
		$pdf->Cell( 0, 7, $title, 0, 1 );
		$pdf->SetDrawColor( 226, 232, 240 );
		$y = $pdf->GetY();
		$pdf->Line( 14, $y, 196, $y );
		$pdf->Ln( 2 );
	}

	/** Three-column label/value grid at fixed coordinates (stays on one row band). */
	private static function grid( $pdf, array $muted, array $pairs ): void {
		$col_w = 60.66; // (196-14)/3
		$row_h = 12.5;
		$x0    = 14;
		$start = $pdf->GetY();

		$i = 0;
		foreach ( $pairs as $pair ) {
			[ $label, $value ] = $pair;
			$col = $i % 3;
			$row = intdiv( $i, 3 );
			$x   = $x0 + $col * $col_w;
			$y   = $start + $row * $row_h;

			$pdf->SetXY( $x, $y );
			$pdf->SetFont( 'DejaVu', '', 7.5 );
			$pdf->SetTextColor( $muted[0], $muted[1], $muted[2] );
			// (376) Κεφαλαία χωρίς τόνους.
			$pdf->Cell( $col_w, 4, \EnergyCRM\Domain\Document\GreekCaps::upper( (string) $label ), 0, 0 );

			$pdf->SetXY( $x, $y + 4.5 );
			$pdf->SetFont( 'DejaVu', '', 10 );
			$pdf->SetTextColor( 15, 23, 42 );
			$pdf->Cell( $col_w, 5, ( $value !== '' && $value !== null ) ? (string) $value : '—', 0, 0 );
			$i++;
		}

		$rows = (int) ceil( count( $pairs ) / 3 );
		$pdf->SetY( $start + $rows * $row_h );
	}
}
