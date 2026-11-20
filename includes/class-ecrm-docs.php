<?php
/**
 * Document requirements — required uploads per activation type + status gating.
 *
 * Defines a catalog of document kinds (slug => Greek label) and which kinds are
 * required for each activation type. Advancing a contract into a "gate" status
 * (routed / active) is blocked until every required document is attached.
 *
 * All maps are filterable so a site can tune them without touching the plugin:
 *   - ecrm_doc_kinds            (catalog)
 *   - ecrm_required_docs        (activation_type => slugs[])
 *   - ecrm_doc_gate_statuses    (statuses that require completeness)
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Docs {

	/** Catalog of document kinds: slug => Greek label. */
	public static function kinds(): array {
		return apply_filters( 'ecrm_doc_kinds', [
			'id_card'       => 'Ταυτότητα/Διαβατήριο',
			'provider_bill' => 'Λογαριασμός παρόχου',
			// Κινητή/σταθερή/internet. Ξεχωριστό από τον provider_bill: σε
			// σύμβαση ρεύματος δεν αντικαθιστά τον λογαριασμό ρεύματος (δεν έχει
			// αριθμό παροχής), σε σύμβαση κινητής όμως είναι ακριβώς αυτός.
			'telecom_bill'  => 'Λογαριασμός τηλεφωνίας',
			'sim_card'      => 'Κάρτα SIM',
			'authorization' => 'Εξουσιοδότηση',
			'residence'     => 'Αποδεικτικό κατοικίας',
			'e9'            => 'Ε9 / στοιχεία ακινήτου',
			'death_cert'    => 'Πιστοποιητικό θανάτου',
			'heir_cert'     => 'Πιστοποιητικό εγγυτέρων συγγενών',
			'gemi'          => 'ΓΕΜΗ / Καταστατικό',
			'iban'          => 'IBAN / αποδεικτικό λογαριασμού',
			// (432) Όσα ζητά η Protergia (Back Office 19-20/11).
			'meter_photo'         => 'Φωτογραφία μετρητή',
			'lease'               => 'Μισθωτήριο (TAXISnet)',
			'connection_contract' => 'Σύμβαση σύνδεσης ΔΕΔΔΗΕ/ΕΔΑ',
			'kad'                 => 'Έναρξη δραστηριότητας / ΚΑΔ',
			'fek'                 => 'ΦΕΚ εκπροσώπησης',
			'payment_proof'       => 'Απόδειξη εξόφλησης',
			'installments'        => 'Δοσολόγιο διακανονισμού',
			'manager_decl'        => 'ΥΔ διαχειριστή πολυκατοικίας',
			'other'         => 'Άλλο έγγραφο',
		] );
	}

	/** Greek label for a kind slug. */
	public static function label( string $slug ): string {
		$k = self::kinds();
		return $k[ $slug ] ?? 'Έγγραφο';
	}

	/** Built-in default required-doc map (activation_type => slugs[]). */
	public static function default_required(): array {
		return [
			'change_provider' => [ 'id_card', 'provider_bill' ],
			'succession'      => [ 'id_card', 'provider_bill', 'death_cert', 'heir_cert' ],
			'reconnection'    => [ 'id_card', 'provider_bill' ],
			'renewal'         => [ 'id_card' ],
			'new_connection'  => [ 'id_card', 'e9' ],
			'program_change'  => [ 'id_card' ],
		];
	}

	/** Effective required map: saved admin option (if any) over defaults, then filter. */
	public static function required_map(): array {
		$map   = self::default_required();
		$saved = get_option( ECRM_PREFIX . 'required_docs_map', null );
		if ( is_array( $saved ) ) {
			foreach ( $saved as $type => $slugs ) {
				$map[ $type ] = array_values( array_filter( (array) $slugs ) );
			}
		}
		return apply_filters( 'ecrm_required_docs', $map );
	}

	/**
	 * Required document kinds for an activation type.
	 *
	 * $energy_type strips 'e9' for mobile, 2026-08-24: the map is keyed only
	 * by activation_type, and 'new_connection' => ['id_card', 'e9'] is real
	 * for power/gas (Ε9 proves who may open a connection at that address) but
	 * meaningless for a mobile line — a new Orizon number was blocked from
	 * ever reaching 'finalisation'/'active' waiting for a property-tax document
	 * that has nothing to do with a SIM card. Caught by the site owner on
	 * ORIZON-0002. Filtered for every activation_type, not only
	 * 'new_connection', because nothing about Ε9 becomes relevant to mobile
	 * under any of the others either — a saved admin override
	 * (required_map()) could add it there too, and the filter should still
	 * hold.
	 */
	public static function required_for( ?string $activation_type, ?string $energy_type = null ): array {
		$map = self::required_map();
		$at  = (string) $activation_type;
		$req = $map[ $at ] ?? [ 'id_card', 'provider_bill' ];

		if ( 'mobile' === (string) $energy_type ) {
			$req = array_values( array_diff( $req, [ 'e9' ] ) );
		}

		return $req;
	}

	/**
	 * Είδη εγγράφου που έχουν πραγματική ημερομηνία λήξης τυπωμένη πάνω τους.
	 *
	 * ΜΕΤΡΗΜΕΝΟ (31/08/2026): από τα 11 είδη του kinds(), μόνο η ταυτότητα/
	 * διαβατήριο έχει κάτι τέτοιο -- λογαριασμός παρόχου, εξουσιοδότηση,
	 * αποδεικτικό κατοικίας, Ε9 κ.λπ. δεν λήγουν με την ίδια έννοια (ή
	 * χρειάζονται να είναι «πρόσφατα», όχι «μη ληγμένα» -- διαφορετικό
	 * πρόβλημα, όχι εδώ). Filter, ίδιο μοτίβο με το ecrm_doc_kinds, ώστε ένα
	 * site να προσθέσει π.χ. άδεια οδήγησης χωρίς αλλαγή στον πυρήνα.
	 */
	public static function expirable_kinds(): array {
		return apply_filters( 'ecrm_expirable_doc_kinds', [ 'id_card' ] );
	}

	/**
	 * Συνημμένα έγγραφα λήξιμου είδους που έχουν ΗΔΗ λήξει -- μόνο το πιο
	 * πρόσφατο ανά είδος (ίδιο σκεπτικό με το `FileRepository::latestPathOfKind()`:
	 * αν ανέβηκε νεότερη, έγκυρη ταυτότητα πάνω από μια ληγμένη, δεν υπάρχει
	 * πια πρόβλημα).
	 *
	 * @return list<array{kind:string, label:string, expires_at:string}>
	 */
	public static function expired_docs( int $contract_id ): array {
		global $wpdb;
		$kinds = self::expirable_kinds();
		if ( ! $kinds || $contract_id <= 0 ) {
			return [];
		}
		$fl = ECRM_DB::table( 'files' );
		$ph = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT doc_kind, expires_at FROM {$fl}
			 WHERE contract_id = %d AND doc_kind IN ($ph) AND expires_at IS NOT NULL
			 ORDER BY id DESC",
			array_merge( [ $contract_id ], $kinds )
		), ARRAY_A );

		$latest = [];
		foreach ( $rows as $r ) {
			// ORDER BY id DESC -- η πρώτη γραμμή που βλέπουμε ανά kind είναι
			// η νεότερη, οι επόμενες αγνοούνται.
			if ( ! isset( $latest[ $r['doc_kind'] ] ) ) {
				$latest[ $r['doc_kind'] ] = $r['expires_at'];
			}
		}

		$today = current_time( 'Y-m-d' );
		$out   = [];
		foreach ( $latest as $kind => $expires_at ) {
			if ( $expires_at < $today ) {
				$out[] = [ 'kind' => $kind, 'label' => self::label( $kind ), 'expires_at' => $expires_at ];
			}
		}
		return $out;
	}

	/** Statuses whose entry requires all documents to be present. */

	public static function gate_statuses(): array {
		// 07/09/2026: `routed` -> `finalisation`, ίδιο στάδιο με νέο όνομα.
		//
		// Η `registration` ΔΕΝ μπαίνει εδώ, παρότι εξ ορισμού σημαίνει «έχουμε
		// όλα τα χαρτιά»: η `PaperworkGate` απαιτεί ΚΑΙ υπογραφή πελάτη, και
		// στην καταχώρηση ο πελάτης δεν έχει υπογράψει ακόμα. Θα κλείδωνε τη
		// ροή στο πρώτο της βήμα.
		return apply_filters( 'ecrm_doc_gate_statuses', [ 'finalisation', 'active' ] );
	}

	/** Distinct doc kinds already attached to a contract. */
	public static function present_kinds( int $contract_id ): array {
		global $wpdb;
		$fl = ECRM_DB::table( 'files' );
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT doc_kind FROM {$fl} WHERE contract_id = %d", $contract_id
		) );
		return array_values( array_filter( (array) $rows ) );
	}

	/**
	 * Build a checklist for a contract: each required kind + whether satisfied.
	 *
	 * @return array{items: array<array{kind:string,label:string,ok:bool}>, missing: string[], complete: bool}
	 */
	public static function checklist( int $contract_id, ?string $activation_type, ?string $energy_type = null ): array {
		$present = self::present_kinds( $contract_id );

		// (432) Protergia, αιτήσεις από το ProtergiaDocs::FROM: ο δικός της πίνακας,
		// με «ένα από» και υπενθυμίσεις. Οι παλιότερες μένουν στον γενικό.
		$protergia = self::protergia( $contract_id, (string) $activation_type, (string) $energy_type );
		if ( null !== $protergia ) {
			return self::grouped( $protergia['requirements'], $present, $protergia['extras'] );
		}

		$required = self::required_for( $activation_type, $energy_type );
		// Σε κινητή, «λογαριασμός παρόχου» είναι ο λογαριασμός τηλεφωνίας.
		if ( 'mobile' === (string) $energy_type && in_array( 'telecom_bill', $present, true ) ) {
			$present[] = 'provider_bill';
		}
		$items = []; $missing = [];
		foreach ( $required as $slug ) {
			$ok = in_array( $slug, $present, true );
			$items[] = [ 'kind' => $slug, 'label' => self::label( $slug ), 'ok' => $ok ];
			if ( ! $ok ) { $missing[] = $slug; }
		}
		return [ 'items' => $items, 'missing' => $missing, 'complete' => empty( $missing ) ];
	}

	/**
	 * Missing-doc labels for a contract (for error messages).
	 *
	 * (432) Από τις ετικέτες των γραμμών του checklist, όχι από το είδος: μια
	 * γραμμή «Ε9, μισθωτήριο ή τελικός λογαριασμός» δεν είναι ένα είδος.
	 */
	public static function missing_labels( int $contract_id, ?string $activation_type, ?string $energy_type = null ): array {
		$c = self::checklist( $contract_id, $activation_type, $energy_type );
		$labels = [];
		foreach ( $c['items'] as $it ) {
			if ( empty( $it['ok'] ) ) {
				$labels[] = (string) $it['label'];
			}
		}
		return $labels;
	}

	/**
	 * (432) Ο πίνακας της Protergia για αυτή την αίτηση, ή null.
	 *
	 * Πρώτα ένα φτηνό ερώτημα (πάροχος, ημερομηνία)· η πλήρης ανάγνωση της
	 * αίτησης (με αποκρυπτογραφημένα extra: νομική μορφή, ισχύς, ενδείξεις)
	 * μόνο όταν ο πίνακας ισχύει.
	 *
	 * @return array{requirements: array, extras: array<string, mixed>}|null
	 */
	private static function protergia( int $contract_id, string $activation_type, string $energy_type ): ?array {
		global $wpdb;

		if ( $contract_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT c.created_at, p.slug FROM %i c LEFT JOIN %i p ON p.id = c.provider_id WHERE c.id = %d',
			ECRM_DB::table( 'contracts' ),
			ECRM_DB::table( 'providers' ),
			$contract_id
		), ARRAY_A );

		if ( ! $row || ! \EnergyCRM\Domain\Document\ProtergiaDocs::applies( (string) $row['slug'], $energy_type, (string) $row['created_at'] ) ) {
			return null;
		}

		$full = \EnergyCRM\Services::contractDetails()->forDocument( $contract_id );
		if ( null === $full ) {
			return null;
		}

		$extras = json_decode( (string) ( $full['extra_json'] ?? '' ), true );
		$extras = is_array( $extras ) ? $extras : [];

		$requirements = \EnergyCRM\Domain\Document\ProtergiaDocs::requirements( [
			'activation'    => $activation_type,
			'energy'        => $energy_type,
			'category'      => (string) ( $full['category'] ?? '' ),
			'customer_type' => (string) ( $full['customer_type'] ?? '' ),
			'company_type'  => (string) ( $extras['company_type'] ?? '' ),
			'kva'           => \EnergyCRM\Domain\Forms\DeiCatalogue::kva( (string) ( $extras['agreed_power'] ?? '' ) ),
		] );

		return null === $requirements ? null : [ 'requirements' => $requirements, 'extras' => $extras ];
	}

	/**
	 * (432) Checklist από ομάδες: κάθε γραμμή περνά με ΕΝΑ από τα είδη της, ή με
	 * το πεδίο της φόρμας όπου η γραμμή το δέχεται (ενδείξεις μετρητή).
	 *
	 * @param array{required: list<array{key: string, label: string, hint: string, kinds: list<string>, field: string}>, reminders: list<string>} $req
	 * @param list<string>         $present
	 * @param array<string, mixed> $extras
	 */
	private static function grouped( array $req, array $present, array $extras ): array {
		$items = []; $missing = [];
		foreach ( $req['required'] as $r ) {
			$ok = (bool) array_intersect( $r['kinds'], $present )
				|| ( '' !== $r['field'] && '' !== trim( (string) ( $extras[ $r['field'] ] ?? '' ) ) );
			$items[] = [
				'kind'  => $r['kinds'][0],
				'kinds' => $r['kinds'],
				'label' => $r['label'],
				'hint'  => $r['hint'],
				'ok'    => $ok,
			];
			if ( ! $ok ) { $missing[] = $r['key']; }
		}
		return [ 'items' => $items, 'missing' => $missing, 'complete' => empty( $missing ), 'reminders' => $req['reminders'] ];
	}
}
