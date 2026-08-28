<?php
/**
 * Import provider status updates from an Excel/CSV file.
 *
 * Reads .xlsx (ZipArchive + SimpleXML, handles sharedStrings + inlineStr)
 * or .csv (UTF-8, BOM-tolerant), with no external library. The caller maps
 * which column holds the supply number and which holds the provider's status
 * text, plus a value-map of provider status -> our status slug. Matching is
 * done on contracts.supply_number within the user's visible downline.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Import {

	/**
	 * Parse an uploaded file into a header row + data rows.
	 *
	 * @return array{ok:bool, columns?:string[], rows?:array<int,array<int,string>>, total?:int, error?:string}
	 */
	public static function parse( string $path, string $filename ): array {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( $ext === 'csv' || $ext === 'txt' ) {
			$rows = self::read_csv( $path );
		} elseif ( $ext === 'xlsx' || $ext === 'xlsm' ) {
			$rows = self::read_xlsx( $path );
		} else {
			return [ 'ok' => false, 'error' => 'Υποστηρίζονται μόνο .xlsx ή .csv αρχεία.' ];
		}

		if ( $rows === null ) {
			return [ 'ok' => false, 'error' => 'Δεν διαβάστηκε το αρχείο.' ];
		}
		$rows = array_values( array_filter( $rows, fn( $r ) => count( array_filter( $r, fn( $v ) => $v !== '' ) ) > 0 ) );
		if ( ! $rows ) {
			return [ 'ok' => false, 'error' => 'Το αρχείο είναι κενό.' ];
		}

		$columns = array_shift( $rows );
		$total   = count( $rows );
		if ( $total > 5000 ) {
			$rows = array_slice( $rows, 0, 5000 );
		}

		// Normalise row widths to the header width.
		$w = count( $columns );
		foreach ( $rows as &$r ) {
			$r = array_pad( array_slice( $r, 0, $w ), $w, '' );
		}
		unset( $r );

		return [ 'ok' => true, 'columns' => array_map( 'strval', $columns ), 'rows' => $rows, 'total' => $total ];
	}

	// ---------------------------------------------------------------------
	private static function read_csv( string $path ): ?array {
		$raw = file_get_contents( $path );
		if ( $raw === false ) {
			return null;
		}
		if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) { $raw = substr( $raw, 3 ); } // strip UTF-8 BOM
		$delim = ( substr_count( $raw, ';' ) > substr_count( $raw, ',' ) ) ? ';' : ',';

		$rows  = [];
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		foreach ( $lines as $line ) {
			if ( $line === '' ) { continue; }
			$rows[] = str_getcsv( $line, $delim );
		}
		return $rows;
	}

	private static function read_xlsx( string $path ): ?array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}
		$zip = new ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			return null;
		}

		// Shared strings (optional).
		$shared = [];
		$ss = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( $ss !== false ) {
			$xml = @simplexml_load_string( $ss );
			if ( $xml ) {
				foreach ( $xml->si as $si ) {
					$shared[] = self::si_text( $si );
				}
			}
		}

		// First worksheet (resolve via workbook rels, fallback to sheet1).
		$sheet_path = self::first_sheet_path( $zip );
		$data = $zip->getFromName( $sheet_path );
		$zip->close();
		if ( $data === false ) {
			return null;
		}

		$xml = @simplexml_load_string( $data );
		if ( ! $xml ) {
			return null;
		}

		$rows = [];
		foreach ( $xml->sheetData->row as $row ) {
			$cells = [];
			$max   = 0;
			foreach ( $row->c as $c ) {
				$ref  = (string) $c['r'];
				$col  = self::col_index( $ref );
				$type = (string) $c['t'];
				$val  = '';
				if ( $type === 's' ) {
					$idx = (int) $c->v;
					$val = $shared[ $idx ] ?? '';
				} elseif ( $type === 'inlineStr' ) {
					$val = self::si_text( $c->is );
				} else {
					$val = isset( $c->v ) ? (string) $c->v : '';
				}
				$cells[ $col ] = $val;
				$max = max( $max, $col );
			}
			$line = [];
			for ( $i = 0; $i <= $max; $i++ ) {
				$line[] = $cells[ $i ] ?? '';
			}
			$rows[] = $line;
		}
		return $rows;
	}

	private static function first_sheet_path( ZipArchive $zip ): string {
		// Try workbook rels to find the first sheet target.
		$rels = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		$wb   = $zip->getFromName( 'xl/workbook.xml' );
		if ( $rels && $wb ) {
			$wx = @simplexml_load_string( $wb );
			$rx = @simplexml_load_string( $rels );
			if ( $wx && $rx ) {
				$wx->registerXPathNamespace( 'r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
				$first = $wx->sheets->sheet[0] ?? null;
				if ( $first ) {
					$rid = (string) $first->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
					foreach ( $rx->Relationship as $rel ) {
						if ( (string) $rel['Id'] === $rid ) {
							$target = ltrim( (string) $rel['Target'], '/' );
							return ( strpos( $target, 'xl/' ) === 0 ) ? $target : 'xl/' . $target;
						}
					}
				}
			}
		}
		return 'xl/worksheets/sheet1.xml';
	}

	private static function si_text( $si ): string {
		// <si> may have a single <t> or several <r><t>.
		if ( isset( $si->t ) ) {
			return (string) $si->t;
		}
		$txt = '';
		if ( isset( $si->r ) ) {
			foreach ( $si->r as $r ) {
				$txt .= (string) $r->t;
			}
		}
		return $txt;
	}

	private static function col_index( string $ref ): int {
		if ( ! preg_match( '/^([A-Z]+)/', $ref, $m ) ) {
			return 0;
		}
		$letters = $m[1];
		$n = 0;
		$len = strlen( $letters );
		for ( $i = 0; $i < $len; $i++ ) {
			$n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return $n - 1;
	}

	// ---------------------------------------------------------------------
	// Apply: update contract statuses by supply number
	// ---------------------------------------------------------------------
	/*
	 * Το κόψιμο του σχολίου ΔΕΝ ζει πια εδώ.
	 *
	 * Γεννήθηκε σε αυτό το αρχείο στην (159) και ήταν λάθος που παραδέχτηκα
	 * στην ίδια εγγραφή: κανόνας περιεχομένου μέσα σε legacy κλάση ανάγνωσης
	 * υπολογιστικού φύλλου. Μετακόμισε στο ProviderNote (§1.12: ό,τι αγγίζεται
	 * σε legacy μετακομίζει προς το Domain όταν είναι φθηνό).
	 *
	 * Η απόδειξη ότι ανήκει εκεί ήρθε αμέσως: τον ίδιο κανόνα τον χρειάζεται
	 * και η διαδρομή που θα φέρνει καταστάσεις από API παρόχου (§1.13).
	 */

	/**
	 * @param array $pairs       List of [ 'supply' => string, 'status' => slug, 'message'?: string ]
	 * @param bool  $dry         If true, only report; don't write.
	 * @param int   $provider_id Περιορίζει το ταίριασμα σε αυτόν τον πάροχο· 0 = χωρίς περιορισμό.
	 * @return array report
	 */
	public static function apply( array $pairs, bool $dry, int $provider_id = 0 ): array {
		global $wpdb;
		$ct     = ECRM_DB::table( 'contracts' );
		$uid    = get_current_user_id();
		$ids    = ECRM_DB::visible_user_ids( $uid );
		$ph     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$valid  = ECRM_DB::statuses();

		/*
		 * ΣΦΑΛΜΑ ΤΑΙΡΙΑΣΜΑΤΟΣ, βρέθηκε 28/08/2026 σχεδιάζοντας τον χάρτη.
		 *
		 * Το ερώτημα παρακάτω έψαχνε ΜΟΝΟ με αριθμό παροχής και `LIMIT 1`,
		 * χωρίς ταξινόμηση. Ο αριθμός παροχής (ΗΚΑΣΠ) όμως ανήκει στο ΣΗΜΕΙΟ
		 * ΚΑΤΑΝΑΛΩΣΗΣ, όχι στον πάροχο: πελάτης που άλλαξε πάροχο έχει ΔΥΟ
		 * συμβάσεις με τον ίδιο αριθμό. Τότε το `LIMIT 1` διάλεγε αυθαίρετα,
		 * και ένα αρχείο Protergia μπορούσε να ενημερώσει την παλιά σύμβαση
		 * ΔΕΗ του ίδιου σημείου.
		 *
		 * Ο πάροχος έρχεται από την οθόνη, όπου ο χρήστης τον διαλέγει ούτως ή
		 * άλλως για να φορτωθεί ο χάρτης — οπότε η διόρθωση ήταν δωρεάν.
		 *
		 * Με 0 (καμία επιλογή) δεν μπαίνει φίλτρο παρόχου -- η διόρθωση δεν
		 * επιβάλλεται σιωπηλά σε ροή που δεν ξέρει πάροχο.
		 *
		 * Το `ORDER BY id DESC` όμως μπαίνει ΠΑΝΤΑ, και είναι δεύτερη μικρή
		 * αλλαγή που αξίζει να ειπωθεί: παλιά, δύο συμβάσεις με τον ίδιο
		 * αριθμό παροχής έδιναν απροσδιόριστο αποτέλεσμα -- ό,τι επέστρεφε η
		 * MySQL εκείνη τη στιγμή. Τώρα δίνουν πάντα τη ΝΕΟΤΕΡΗ, που είναι και
		 * η σωστή απάντηση στο «ποια σύμβαση αφορά αυτή η γραμμή». Χωρίς
		 * ταξινόμηση, το ίδιο αρχείο μπορούσε να δώσει άλλο αποτέλεσμα σε δύο
		 * εκτελέσεις χωρίς να αλλάξει τίποτα.
		 */
		$scope_provider = $provider_id > 0 ? ' AND provider_id = %d' : '';

		$matched = 0; $updated = 0; $unchanged = 0; $noted = 0; $unmatched = []; $rejected = [];

		foreach ( $pairs as $pair ) {
			$supply = trim( (string) ( $pair['supply'] ?? '' ) );
			$status = (string) ( $pair['status'] ?? '' );
			if ( $supply === '' || ! isset( $valid[ $status ] ) ) {
				continue;
			}
			$note = \EnergyCRM\Providers\Domain\ProviderNote::fromRaw( (string) ( $pair['message'] ?? '' ) );

			$params = array_merge( [ $supply ], $ids );
			if ( $provider_id > 0 ) {
				$params[] = $provider_id;
			}

			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, status, signed_at, partner_user_id FROM {$ct}"
					. " WHERE supply_number = %s AND partner_user_id IN ($ph){$scope_provider}"
					. ' ORDER BY id DESC LIMIT 1',
				$params
			), ARRAY_A );

			if ( ! $row ) {
				$unmatched[] = $supply;
				continue;
			}
			$matched++;
			if ( $row['status'] === $status ) {
				/*
				 * Ίδια κατάσταση, αλλά αν ήρθε ΝΕΟ σχόλιο από τον πάροχο αξίζει
				 * να καταγραφεί — «είπε κάτι» είναι πληροφορία ακόμα κι όταν δεν
				 * κουνήθηκε τίποτα. Απόφαση ιδιοκτήτη 27/08/2026.
				 *
				 * Γεγονός τύπου 'note', ΟΧΙ 'status_change' με ίδιο from/to: θα
				 * ζωγράφιζε «Ακυρώθηκε → Ακυρώθηκε» στο χρονολόγιο, που δεν λέει
				 * τίποτα σε κανέναν.
				 */
				if ( null !== $note ) {
					$noted++;
					if ( ! $dry ) {
						\EnergyCRM\Services::events()->record( (int) $row['id'], $uid, 'note', [
							'message' => 'Ο πάροχος (Excel): «' . $note . '»',
						] );
					}
				} else {
					$unchanged++;
				}
				continue;
			}

			// Ο γράφος ελέγχεται ΠΡΙΝ από τη διάκριση dry/live, αλλιώς η
			// προεπισκόπηση λέει ψέματα: μετρούσε ως «θα ενημερωθούν» και όσες
			// η ροή θα αρνιόταν, και ο χρήστης έβλεπε άλλο νούμερο στο dry run
			// από ό,τι στο πραγματικό — χωρίς καμία εξήγηση για τη διαφορά.
			$from   = \EnergyCRM\Domain\Contract\ContractStatus::tryFromSlug( (string) $row['status'] );
			$target = \EnergyCRM\Domain\Contract\ContractStatus::tryFromSlug( $status );

			if ( null === $target || ( null !== $from && ! $from->canMoveTo( $target ) ) ) {
				$rejected[] = $supply;
				continue;
			}

			// 2026-08-24: τέταρτη πόρτα που γράφει status βρέθηκε σε γενικό
			// έλεγχο μετά τις (114)/(115) — αυτή περνούσε από ContractLifecycle
			// (σωστά, σέβεται τον γράφο) αλλά καμία από τις τρεις πύλες που
			// εμποδίζουν ψεύτικο χειροκίνητο «Υπογράφηκε» δεν καλύπτει αυτό
			// το αρχείο. Ένα Excel παρόχου με μια γραμμή status=signed θα
			// δήλωνε δεκάδες αιτήσεις υπογεγραμμένες χωρίς καμία πραγματική
			// υπογραφή πελάτη. Ίδιος έλεγχος, ίδιος λόγος με τον
			// ContractStatusController/ContractsBulkController/
			// ContractSaveController.
			if (
				\EnergyCRM\Domain\Contract\ContractStatus::Signed === $target
				&& empty( $row['signed_at'] )
			) {
				$rejected[] = $supply;
				continue;
			}

			if ( ! $dry ) {
				// ΜΕΣΩ ContractLifecycle. Το σκέτο UPDATE + insert που ήταν εδώ
				// ήταν το μοναδικό σημείο σε όλο τον κώδικα που έγραφε status
				// έξω από τη ροή, και έχανε και τα τέσσερα που κάνει η moveTo():
				// τον γράφο μεταβάσεων, την ειδοποίηση στον συνεργάτη, το SMS
				// στον πελάτη, και το ecrm_contract_status_changed που ξεκινά
				// τον AutoProcess.
				//
				// Το τρίτο ήταν το χειρότερο: η κύρια ροή ενημέρωσης καταστάσεων
				// στην πράξη είναι το Excel του παρόχου, δηλαδή ήταν ακριβώς η
				// ροή που δεν ειδοποιούσε κανέναν, ενώ η ΙΔΙΑ μετάβαση από το UI
				// έστελνε κανονικά.
				//
				// user_id = ο εισαγωγέας, δηλαδή ποιος το έκανε.
				//
				// Ως τις 19/08/2026 εδώ έμπαινε ο ΚΑΤΟΧΟΣ, και ο λόγος ήταν
				// σωστός με τον τότε κώδικα: η moveTo() προωθούσε την τιμή στη
				// notify_status_change(), που τη χρησιμοποιούσε ως παραλήπτη.
				// Δηλαδή η ίδια τιμή απαντούσε σε δύο ερωτήσεις, και το ιστορικό
				// πλήρωνε — έγραφε ότι τη μαζική ενημέρωση την έκανε ο
				// συνεργάτης. Τώρα η ειδοποίηση βρίσκει τον κάτοχο μόνη της από
				// τη σύμβαση, οπότε το πεδίο σημαίνει ένα πράγμα παντού.
				$moved = \EnergyCRM\Services::lifecycle()->moveTo( (int) $row['id'], $status, [
					'user_id' => get_current_user_id(),
					'from'    => (string) $row['status'],
					// Το σχόλιο του παρόχου γίνεται μέρος του ΙΔΙΟΥ μηνύματος
					// που ήδη γράφεται — όχι δεύτερο πεδίο, όχι νέα στήλη. Το
					// «Ενημέρωση από Excel παρόχου» παραμένει έτσι κι αλλιώς,
					// ώστε το ιστορικό να λέει ΠΩΣ έγινε η αλλαγή ακόμα κι όταν
					// ο πάροχος δεν έστειλε αιτιολογία.
					'message' => 'Ενημέρωση από Excel παρόχου' . ( null !== $note ? ': «' . $note . '»' : '' ),
				] );

				if ( ! $moved ) {
					$rejected[] = $supply;
					continue;
				}
			}
			$updated++;
		}

		return [
			'ok'             => true,
			'dry'            => $dry,
			'matched'        => $matched,
			'updated'        => $updated,
			'unchanged'      => $unchanged,
			'noted'          => $noted,
			'unmatched'      => array_slice( $unmatched, 0, 100 ),
			'unmatched_total'=> count( $unmatched ),
			'rejected'       => array_slice( $rejected, 0, 100 ),
			'rejected_total' => count( $rejected ),
		];
	}
}
