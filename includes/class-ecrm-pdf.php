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
	 * @param array $c Joined contract+customer row (see ECRM_REST::get_contract).
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
	 * Build a savings/quote PDF for a sales offer.
	 *
	 * @param array $m Computed figures (see ECRM_REST::quote_pdf).
	 * @return string Raw PDF bytes.
	 */
	public static function build_quote( array $m ): string {
		self::boot();
		$navy  = [ 10, 31, 61 ];
		$amber = [ 245, 158, 11 ];
		$muted = [ 100, 116, 139 ];
		$green = [ 21, 128, 61 ];

		$pdf = new tFPDF( 'P', 'mm', 'A4' );
		$pdf->fontpath = __DIR__ . '/lib/tfpdf/font/';
		$pdf->SetAutoPageBreak( true, 18 );
		$pdf->AddPage();
		$pdf->AddFont( 'DejaVu', '', 'DejaVuSans.ttf', true );
		$pdf->AddFont( 'DejaVu', 'B', 'DejaVuSans-Bold.ttf', true );

		$pdf->SetFillColor( $navy[0], $navy[1], $navy[2] );
		$pdf->Rect( 0, 0, 210, 28, 'F' );
		$pdf->SetFillColor( $amber[0], $amber[1], $amber[2] );
		$pdf->Rect( 0, 28, 210, 1.4, 'F' );

		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : '';
		$logo    = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_logo' ) : '';
		if ( $logo ) { $path = self::logo_path( $logo ); if ( $path ) { try { $pdf->Image( $path, 170, 7, 26 ); } catch ( \Throwable $e ) {} } }

		$pdf->SetXY( 14, 8 );
		$pdf->SetTextColor( 255, 255, 255 );
		$pdf->SetFont( 'DejaVu', 'B', 16 );
		$pdf->Cell( 0, 8, 'Πρόταση Εξοικονόμησης', 0, 1 );
		$pdf->SetX( 14 );
		$pdf->SetFont( 'DejaVu', '', 10 );
		$pdf->SetTextColor( 203, 213, 225 );
		$pdf->Cell( 0, 6, ( $company ?: 'Energy CRM' ) . '  ·  ' . gmdate( 'd/m/Y' ), 0, 1 );

		$pdf->SetTextColor( 15, 23, 42 );
		$pdf->Ln( 8 );

		self::section( $pdf, $navy, 'Στοιχεία πρότασης' );
		self::grid( $pdf, $muted, [
			[ 'Πελάτης', $m['customer'] ?: '—' ],
			[ 'Πάροχος', $m['provider'] ?: '—' ],
			[ 'Πρόγραμμα', $m['program'] ?: '—' ],
			[ 'Υπηρεσία', $m['energy'] ?? '' ],
			[ 'Ετήσια κατανάλωση', number_format( (float) $m['consumption'], 0 ) . ' kWh' ],
			[ 'Τιμή πρότασης', number_format( (float) $m['offered_price'], 5 ) . ' €/kWh' ],
		] );

		$pdf->Ln( 4 );
		self::section( $pdf, $navy, 'Σύγκριση ετήσιου κόστους (ενέργεια)' );

		// comparison table
		$pdf->SetFont( 'DejaVu', 'B', 9 );
		$pdf->SetFillColor( 241, 245, 249 );
		$pdf->SetTextColor( $navy[0], $navy[1], $navy[2] );
		$w = [ 92, 45, 45 ];
		$pdf->Cell( $w[0], 8, '', 0, 0, 'L', true );
		$pdf->Cell( $w[1], 8, 'Τρέχον', 0, 0, 'R', true );
		$pdf->Cell( $w[2], 8, 'Πρόταση', 0, 1, 'R', true );

		$pdf->SetFont( 'DejaVu', '', 9 );
		$pdf->SetTextColor( 15, 23, 42 );
		$rowf = function ( $label, $a, $b, $suffix ) use ( $pdf, $w ) {
			$pdf->Cell( $w[0], 7, $label, 0, 0, 'L' );
			$pdf->Cell( $w[1], 7, $a . $suffix, 0, 0, 'R' );
			$pdf->Cell( $w[2], 7, $b . $suffix, 0, 1, 'R' );
		};
		$rowf( 'Τιμή ενέργειας', number_format( (float) $m['current_price'], 5 ), number_format( (float) $m['offered_price'], 5 ), ' €/kWh' );
		$rowf( 'Πάγιο', number_format( (float) $m['current_fixed'], 2 ), number_format( (float) $m['offered_fixed'], 2 ), ' €/μήνα' );

		$pdf->SetFont( 'DejaVu', 'B', 11 );
		$pdf->SetTextColor( $navy[0], $navy[1], $navy[2] );
		$pdf->Cell( $w[0], 10, 'Εκτιμώμενο ετήσιο κόστος', 0, 0, 'L' );
		$pdf->Cell( $w[1], 10, number_format( (float) $m['current_annual'], 2 ) . ' €', 0, 0, 'R' );
		$pdf->Cell( $w[2], 10, number_format( (float) $m['offered_annual'], 2 ) . ' €', 0, 1, 'R' );

		// savings box
		$pdf->Ln( 6 );
		$save = (float) $m['savings'];
		$pos  = $save >= 0;
		$boxc = $pos ? [ 240, 253, 244 ] : [ 254, 242, 242 ];
		$txtc = $pos ? $green : [ 185, 28, 28 ];
		$y = $pdf->GetY();
		$pdf->SetFillColor( $boxc[0], $boxc[1], $boxc[2] );
		$pdf->Rect( 14, $y, 182, 26, 'F' );
		$pdf->SetXY( 18, $y + 4 );
		$pdf->SetFont( 'DejaVu', '', 10 );
		$pdf->SetTextColor( $txtc[0], $txtc[1], $txtc[2] );
		$pdf->Cell( 0, 6, $pos ? 'Εκτιμώμενη ετήσια εξοικονόμηση' : 'Εκτιμώμενη ετήσια διαφορά', 0, 1 );
		$pdf->SetX( 18 );
		$pdf->SetFont( 'DejaVu', 'B', 20 );
		$pdf->Cell( 0, 12, number_format( abs( $save ), 2 ) . ' €  (' . number_format( (float) $m['pct'], 1 ) . '%)', 0, 1 );

		// disclaimer
		$pdf->SetY( -22 );
		$pdf->SetFont( 'DejaVu', '', 7.5 );
		$pdf->SetTextColor( 148, 163, 184 );
		$pdf->MultiCell( 0, 4, 'Η εκτίμηση αφορά τη χρέωση ενέργειας και το πάγιο βάσει της δηλωθείσας κατανάλωσης. Ρυθμιζόμενες χρεώσεις, χρεώσεις δικτύου, φόροι και δημοτικά τέλη δεν περιλαμβάνονται καθώς είναι κοινά μεταξύ παρόχων. Τελικές τιμές κατά την ενεργοποίηση.', 0, 'C' );

		return $pdf->Output( '', 'S' );
	}

	/** Truncate a string to n chars with an ellipsis (multibyte-safe). */
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
			$pdf->Cell( $col_w, 4, mb_strtoupper( (string) $label, 'UTF-8' ), 0, 0 );

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
