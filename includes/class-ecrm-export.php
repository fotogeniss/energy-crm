<?php
/**
 * Excel export for contracts.
 *
 * Builds a genuine .xlsx (a zip of XML parts) with no external library —
 * uses ZipArchive, which is available on virtually all WP hosts. Cells are
 * written as inline strings so Greek text renders correctly without a
 * sharedStrings table.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Export {

	/**
	 * Build the contracts dataset for the current user, honouring the same
	 * status + search filters as the list view.
	 *
	 * @return array{headers:string[], rows:array<int,array<int,string>>}
	 */
	public static function contracts_dataset( string $status, string $q, array $ids = [], array $scope_ids = [], string $from = '', string $to = '' ): array {
		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$pr  = ECRM_DB::table( 'providers' );
		$pg  = ECRM_DB::table( 'programs' );
		$uid = get_current_user_id();
		$st  = ECRM_DB::statuses();
		$acts = ECRM_DB::activation_types();

		if ( $ids ) {
			// Explicit selection (bulk export): restrict to ids within the allowed scope.
			$scope  = $scope_ids ?: [ $uid ];
			$in     = implode( ',', array_map( 'intval', $ids ) );
			$sin    = implode( ',', array_fill( 0, count( $scope ), '%d' ) );
			$where  = [ "c.id IN ($in)", "c.partner_user_id IN ($sin)" ];
			$params = $scope;
		} else {
			// Scope: own user, or the whole visible team when provided.
			$scope = $scope_ids ?: [ $uid ];
			$sin   = implode( ',', array_fill( 0, count( $scope ), '%d' ) );
			$where  = [ "c.partner_user_id IN ($sin)" ];
			$params = $scope;
			if ( $status && array_key_exists( $status, $st ) ) {
				$where[]  = 'c.status = %s';
				$params[] = $status;
			}
			if ( $from !== '' ) { $where[] = 'c.created_at >= %s'; $params[] = $from . ' 00:00:00'; }
			if ( $to !== '' )   { $where[] = 'c.created_at <= %s'; $params[] = $to . ' 23:59:59'; }
			if ( $q !== '' ) {
				$like    = '%' . $wpdb->esc_like( $q ) . '%';
				$where[] = '( cu.first_name LIKE %s OR cu.last_name LIKE %s OR cu.company_name LIKE %s OR cu.afm LIKE %s OR c.supply_number LIKE %s OR c.code LIKE %s )';
				array_push( $params, $like, $like, $like, $like, $like, $like );
			}
		}

		$sql = "SELECT c.code, p.name AS provider, c.energy_type, c.category, c.customer_type,
				cu.first_name, cu.last_name, cu.company_name, cu.mobile,
				c.supply_number, c.invoice_code, c.status, c.partner_user_id, c.payout_id,
				c.start_date, c.end_date, c.created_at, c.updated_at
			FROM {$ct} c
			LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			LEFT JOIN {$pr} p  ON p.id  = c.provider_id
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY c.created_at DESC LIMIT 5000";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$headers = [
			'CLIENT', 'ΗΜ/ΝΙΑ ΔΗΜΙΟΥΡΓΙΑΣ', 'ΜΗΝΑΣ', 'ΠΑΡΟΧΟΣ', 'ΚΑΤΗΓΟΡΙΑ ΠΕΛΑΤΗ',
			'ΕΙΔΟΣ ΠΑΡΟΧΗΣ', 'ΤΥΠΟΣ ΜΕΤΡΗΤΗ', 'ΑΡ. ΠΑΡΟΧΗΣ/ΗΚΑΣΠ', 'STATUS', 'ΣΥΝΕΡΓΑΤΗΣ',
			'ΠΩΛΗΤΗΣ', 'ΚΙΝΗΤΟ ΠΕΛΑΤΗ', 'ΕΚΚΑΘΑΡΙΣΤΗΚΕ', 'ΜΗΝΑΣ ΕΚΚΑΘΑΡΙΣΗΣ', 'ΗΜΕΡΟΜΗΝΙΑ THALIS',
			'ΗΜΕΡΟΜΗΝΙΑ ΕΚΠΡΟΣΩΠΗΣΗΣ', 'ΗΜΕΡΟΜΗΝΙΑ ΤΕΡΜΑΤΙΣΜΟΥ', 'ΗΜΕΡΟΜΗΝΙΑ ΛΗΞΗΣ',
		];

		$months  = [ 1 => 'ΙΑΝΟΥΑΡΙΟΣ', 'ΦΕΒΡΟΥΑΡΙΟΣ', 'ΜΑΡΤΙΟΣ', 'ΑΠΡΙΛΙΟΣ', 'ΜΑΪΟΣ', 'ΙΟΥΝΙΟΣ', 'ΙΟΥΛΙΟΣ', 'ΑΥΓΟΥΣΤΟΣ', 'ΣΕΠΤΕΜΒΡΙΟΣ', 'ΟΚΤΩΒΡΙΟΣ', 'ΝΟΕΜΒΡΙΟΣ', 'ΔΕΚΕΜΒΡΙΟΣ' ];
		$energy  = [ 'power' => 'EL', 'gas' => 'ΦΑ', 'mobile' => 'ΚΙΝ' ];
		$cats    = [ 'home' => 'ΟΙΚΙΑΚΟΣ', 'business' => 'ΕΠΑΓΓΕΛΜΑΤΙΚΟΣ' ];
		$partner = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : '';
		$names   = []; // partner_user_id -> display name cache

		$fmt = static function ( $d ) {
			if ( empty( $d ) || $d === '0000-00-00' || strpos( (string) $d, '0000-00-00' ) === 0 ) { return ''; }
			$ts = strtotime( (string) $d );
			return $ts ? gmdate( 'd/m/Y', $ts ) : '';
		};

		$out = [];
		foreach ( (array) $rows as $r ) {
			$client = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			$cts    = strtotime( (string) $r['created_at'] );
			$pid    = (int) $r['partner_user_id'];
			if ( $pid && ! isset( $names[ $pid ] ) ) {
				$u = get_userdata( $pid );
				$names[ $pid ] = $u ? $u->display_name : '';
			}
			$out[] = [
				$client ?: '—',
				$fmt( $r['created_at'] ),
				$cts ? ( $months[ (int) gmdate( 'n', $cts ) ] ?? '' ) : '',
				$r['provider'],
				$cats[ $r['category'] ] ?? '',
				$energy[ $r['energy_type'] ] ?? strtoupper( (string) $r['energy_type'] ),
				$r['invoice_code'],
				$r['supply_number'],
				mb_strtoupper( $st[ $r['status'] ] ?? (string) $r['status'], 'UTF-8' ),
				$partner,
				$pid ? ( $names[ $pid ] ?? '' ) : '',
				$r['mobile'],
				! empty( $r['payout_id'] ) ? 'ΝΑΙ' : 'ΟΧΙ',
				'', // ΜΗΝΑΣ ΕΚΚΑΘΑΡΙΣΗΣ
				'', // ΗΜΕΡΟΜΗΝΙΑ THALIS
				$fmt( $r['start_date'] ),
				( $r['status'] === 'terminated' ) ? $fmt( $r['updated_at'] ) : '',
				$fmt( $r['end_date'] ),
			];
		}

		return [ 'headers' => $headers, 'rows' => $out ];
	}

	/**
	 * Produce raw .xlsx bytes from a header row + data rows.
	 */
	public static function build_xlsx( array $headers, array $rows ): string {
		$sheet = self::sheet_xml( $headers, $rows );

		$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '</Types>';

		$root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';

		$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="Αιτήσεις" sheetId="1" r:id="rId1"/></sheets></workbook>';

		$workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '</Relationships>';

		$tmp = tempnam( sys_get_temp_dir(), 'ecrm_xlsx_' );
		$zip = new ZipArchive();
		$zip->open( $tmp, ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', $content_types );
		$zip->addFromString( '_rels/.rels', $root_rels );
		$zip->addFromString( 'xl/workbook.xml', $workbook );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $workbook_rels );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet );
		$zip->close();

		$bytes = (string) file_get_contents( $tmp );
		@unlink( $tmp );
		return $bytes;
	}

	private static function sheet_xml( array $headers, array $rows ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

		$all = array_merge( [ $headers ], $rows );
		$r   = 1;
		foreach ( $all as $row ) {
			$xml .= '<row r="' . $r . '">';
			$c = 0;
			foreach ( $row as $val ) {
				$ref  = self::col_letter( $c ) . $r;
				$text = self::xml_escape( (string) ( $val ?? '' ) );
				$xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
				$c++;
			}
			$xml .= '</row>';
			$r++;
		}

		$xml .= '</sheetData></worksheet>';
		return $xml;
	}

	private static function col_letter( int $n ): string {
		$s = '';
		$n++;
		while ( $n > 0 ) {
			$m = ( $n - 1 ) % 26;
			$s = chr( 65 + $m ) . $s;
			$n = intval( ( $n - $m ) / 26 );
		}
		return $s;
	}

	private static function xml_escape( string $s ): string {
		// Strip control chars Excel rejects, then escape XML.
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s );
		return htmlspecialchars( $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
