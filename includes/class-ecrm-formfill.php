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
	/**
	 * Distance from a field's stored y (the top of the line) down to the text
	 * baseline, in mm.
	 *
	 * Measured, not guessed: the labels Protergia prints are 7pt and every one
	 * of them puts its baseline 2.51 mm below the top of its box. Matching that
	 * makes a value sit *on* the dotted rule instead of hanging under it.
	 *
	 * It was 3.0, which put every value on every form half a millimetre low —
	 * individually invisible, collectively the reason the whole sheet read as
	 * slightly off.
	 */
	const BASELINE = 2.5;

	// Νεκρός κώδικας από τα παλιά έντυπα κινητής (πριν τον ξανασχεδιασμό Orizon):
	// υπήρχαν εδώ ένα MOBILE_CONNECTIONS const + choice_label() για το
	// 'eidos_syndesis'/'mobile_connection' και ένα 'ekptosi_pagiou' => $xg('subsidy_amount').
	// Κανένα από τα δύο δεν έχει πεδίο εισαγωγής στη φόρμα ούτε χρησιμοποιείται
	// από κανένα από τα τρέχοντα assets/forms/*.json (επιβεβαιωμένο με grep σε
	// όλα τα αρχεία) — αφαιρέθηκαν 2026-08-09, βλ. CHANGELOG.md.

	/**
	 * Resolve a provider name + energy type to a bundled template key.
	 * Returns '' when we don't have a template for that combination yet.
	 *
	 * This answers "which contract", never "which sheets". For energy that is
	 * the same question; for mobile it is not, and template_keys() is the one
	 * to ask. $program and $activation_type are kept because callers pass them
	 * and a provider may yet need them to pick between contracts.
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
		// Η Orizon είναι κινητή τηλεφωνία: ένα συμβόλαιο, πολλά έντυπα. Εδώ
		// επιστρέφεται μόνο η σύμβαση — ποια φύλλα τη συνοδεύουν το αποφασίζει
		// το MobilePaperwork, μέσω του template_keys().
		if ( $has( 'orizon' ) || $has( 'οριζον' ) ) {
			return \EnergyCRM\Domain\Forms\MobilePaperwork::CONTRACT;
		}

		return '';
	}

	/**
	 * Does the supply carry a night register?
	 *
	 * Read from the tariff code: Γ1 is a single register, Γ1Ν adds the night
	 * one. Accepts both the Greek Ν and the Latin N, because both get typed.
	 */
	private static function has_night( array $c ): bool {
		$code = strtoupper( trim( (string) ( $c['invoice_code'] ?? '' ) ) );
		return $code !== '' && ( substr( $code, -1 ) === 'Ν' || substr( $code, -1 ) === 'N' );
	}

	/**
	 * Reading type: day | day_night | telemetry.
	 *
	 * @param callable $xg Reader for the contract's extra bag.
	 */
	private static function reading_type( array $c, callable $xg ): string {
		$chosen = (string) $xg( 'meter_reading_type' );

		if ( in_array( $chosen, [ 'day', 'day_night', 'telemetry' ], true ) ) {
			return $chosen;
		}

		return self::has_night( $c ) ? 'day_night' : 'day';
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

		// The three addresses every provider form asks for. Usually identical,
		// which is why one used to be enough; different exactly when it matters
		// (a meter in a rented shop, bills going to the accountant).
		$addr = \EnergyCRM\Domain\Contract\ContractAddresses::from( $c );

		$street = trim( (string) ( $c['street'] ?? '' ) . ' ' . (string) ( $c['street_no'] ?? '' ) );

		// Full one-line address for forms that have a single "ΔΙΕΥΘΥΝΣΗ" field
		// (street + number, city, postal code) — used where there is no separate city/TK box.
		$addr_full = $addr->home->oneLine();

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

		// Κινητή: το πρόγραμμα δεν είναι ελεύθερη τιμή σαν το ρεύμα, είναι ένα
		// από τα τέσσερα σταθερά πλάνα της Orizon. Η αντιστοίχιση γίνεται πάνω
		// στο programs.code, όχι στο όνομα — άσχετο με το πώς το λένε στο
		// wp-admin, ώστε μια μετονομασία να μην αχρηστεύει σιωπηλά μια
		// τυπωμένη τιμή. Άγνωστος ή κενός κωδικός τυπώνει χωρίς πρόγραμμα αντί
		// να μαντέψει μία από τις τέσσερις τιμές.
		$mobile = [];
		if ( ( $c['energy_type'] ?? '' ) === 'mobile' ) {
			$combined = in_array( $xg( 'mobile_offer' ), [ 'family', 'combo' ], true );
			$mobile   = \EnergyCRM\Domain\Forms\MobilePlans::fillValues( (string) ( $c['program_code'] ?? '' ), $combined )
				+ \EnergyCRM\Domain\Forms\MobilePaperwork::connectionTicks( $xg( 'request_type' ) )
				+ \EnergyCRM\Domain\Forms\MobilePaperwork::comboUserTicks( $xg( 'combo_user_role' ) );
		}

		return $mobile + [
			'onomateponymo_pelati'    => $name,
			'eponymo_pelati'          => (string) ( $c['last_name'] ?? '' ),
			'onoma_pelati'            => (string) ( $c['first_name'] ?? '' ),
			'patronymo_pelati'        => (string) ( $c['father_name'] ?? '' ),
			'eponymia_etaireias'      => (string) ( $c['company_name'] ?? '' ),
			'afm_pelati'              => (string) ( $c['afm'] ?? '' ),
			'afm_etaireias'           => (string) ( $c['afm'] ?? '' ),
			'doy_pelati'              => (string) ( $c['doy'] ?? '' ),
			'adt_pelati'              => (string) ( $c['adt'] ?? '' ),
			'imerominia_gennisis'     => (string) ( $c['birth_date'] ?? '' ),
			'tilefono_pelati'         => (string) ( $c['phone'] ?? '' ),
			'kinito_pelati'           => (string) ( $c['mobile'] ?? '' ),
			'email_pelati'            => (string) ( $c['email'] ?? '' ),
			'odos_arithmos_katoikias' => $street,
			'dieuthynsi_katoikias'    => $addr_full,
			'arithmos_odou_katoikias' => (string) ( $c['street_no'] ?? '' ),
			'poli_katoikias'          => (string) ( $c['city'] ?? '' ),
			'tk_katoikias'            => (string) ( $c['postal_code'] ?? '' ),
			'nomos_katoikias'         => (string) ( $c['region'] ?? '' ),
			'arithmos_paroxis'        => (string) ( $c['supply_number'] ?? '' ),
			'hkasp'                   => (string) ( $c['supply_number'] ?? '' ),
			'arithmos_metriti'        => (string) ( $c['meter_number'] ?? '' ),

			// --- Διεύθυνση παροχής: where the meter is ---------------------
			// Four keys because the forms disagree about how much goes in one
			// box: some print everything on one line, some split off the number,
			// some have Τ.Κ. and ΠΟΛΗ beside the street. Picking the wrong one
			// prints the town twice.
			'dieuthynsi_paroxis'    => $addr->supply->oneLine(),   // + ΤΚ + πόλη
			'odos_arithmos_paroxis' => trim( $addr->supply->street . ' ' . $addr->supply->streetNo ),
			'odos_paroxis'          => $addr->supply->street,
			'arithmos_odou_paroxis' => $addr->supply->streetNo,
			'poli_paroxis'          => $addr->supply->city,
			'tk_paroxis'            => $addr->supply->postalCode,
			'nomos_paroxis'         => $addr->supply->region,

			// --- Διεύθυνση αποστολής λογαριασμού --------------------------
			// The box every form labels "εφόσον είναι διαφορετική από τη
			// διεύθυνση κατοικίας". Until now it had no data behind it at all.
			'dieuthynsi_apostolis'     => $addr->billing->oneLine(),
			'odos_arithmos_apostolis'  => trim( $addr->billing->street . ' ' . $addr->billing->streetNo ),
			'odos_apostolis'           => $addr->billing->street,
			'arithmos_odou_apostolis'  => $addr->billing->streetNo,
			'poli_apostolis'           => $addr->billing->city,
			'tk_apostolis'             => $addr->billing->postalCode,
			'nomos_apostolis'          => $addr->billing->region,
			'kodikos_timologiou'       => (string) ( $c['invoice_code'] ?? '' ),
			'onoma_programmatos'       => (string) ( $c['program_name'] ?? '' ),
			'diarkeia_symvasis'        => $diarkeia,
			'arithmos_aitisis'         => (string) ( $c['code'] ?? '' ),
			'imerominia_aitisis'       => $created ? gmdate( 'd/m/Y', $created ) : '',
			'imerominia_liksis'        => ! empty( $c['end_date'] ) ? gmdate( 'd/m/Y', strtotime( (string) $c['end_date'] ) ) : '',
			'topos_aitisis'            => (string) ( $c['city'] ?? '' ),
			'topos_imerominia_aitisis' => $topos_imer,
			'eponymia_etaireias_mas'   => $company,
			'onomateponymo_politi'     => $partner,
			'kodikos_synergati'        => $xg( 'kod_synergati' ),

			// Legal representative (companies).
			'onomateponymo_ekprosopou' => $rep_name,

			// Fields the provider forms ask for that the CRM keeps in the
			// contract's extra bag rather than in a column of its own.
			// Fields the provider forms ask for. Where the CRM form already had
			// a field under a different name, that one is reused rather than
			// duplicated — company_type, activity, capacity_role and
			// payment_method all predate this.
			'kad'                     => $xg( 'kad' ),
			'gemi'                    => $xg( 'gemi' ),
			'nomiki_morfi'            => $xg( 'company_type' ),
			'antikeimeno_epixeirisis' => $xg( 'activity' ),
			'eidiki_katigoria'        => $xg( 'eidiki_katigoria' ),
			'iban'                    => $xg( 'iban' ),
			'anotato_orio'            => $xg( 'anotato_orio' ),
			'arithmos_koinoxristou'   => $xg( 'ar_koinoxristou' ),

			// Single-choice groups: every option is its own checkbox key, and
			// only the selected one carries an X.
			'idiotita_idioktitis'      => ( $xg( 'capacity_role' ) === 'owner'  ? 'X' : '' ),
			'idiotita_misthotis'       => ( $xg( 'capacity_role' ) === 'tenant' ? 'X' : '' ),
			'thesi_metriti_esoterikos' => ( $xg( 'meter_position' ) === 'inside'  ? 'X' : '' ),
			'thesi_metriti_exoterikos' => ( $xg( 'meter_position' ) === 'outside' ? 'X' : '' ),
			'pliromi_pagia_entoli'     => ( $xg( 'payment_method' ) === 'standing_order' ? 'X' : '' ),

			// Communication contact (Υπεύθυνος Επικοινωνίας).
			'onomateponymo_epikoinonias' => $contact_name,
			'adt_epikoinonias'           => $xg( 'contact_adt' ),
			'afm_epikoinonias'           => $xg( 'contact_afm' ),
			'tilefono_epikoinonias'      => $xg( 'contact_phone' ),
			'kinito_epikoinonias'        => $xg( 'contact_mobile' ),
			'email_epikoinonias'         => $xg( 'contact_email' ),

			// Meter / billing extras.
			'ipistamenos_promitheftis'  => $xg( 'previous_provider' ),
			'poso_eggiisis'             => $xg( 'guarantee' ),
			'teleftaia_endeixi_metriti' => $xg( 'day_indication' ),
			'isxis_paroxis'             => $xg( 'agreed_power' ),
			'epaggelma_pelati'          => $xg( 'activity' ),

			// Checkboxes (engine stamps 'X' when the value is non-empty).
			'typos_pelati_idiotis'  => ( $ct === 'individual' ? 'X' : '' ),
			'typos_pelati_atomiki'  => ( $ct === 'sole_prop' ? 'X' : '' ),
			'typos_pelati_etaireia' => ( $ct === 'company' ? 'X' : '' ),
			// Supply category: home vs business (derived from customer type).
			// Μέτρηση: επιλογή του συνεργάτη στη φόρμα. Όταν δεν έχει δηλωθεί,
			// συμπεραίνεται από τον κωδικό τιμολογίου (Γ1 απλή, Γ1Ν με
			// νυχτερινό) ώστε οι παλιές συμβάσεις να μη βγαίνουν κενές.
			'metrisi_imerisia'           => ( self::reading_type( $c, $xg ) === 'day'       ? 'X' : '' ),
			'metrisi_imerisia_nyxterini' => ( self::reading_type( $c, $xg ) === 'day_night' ? 'X' : '' ),
			'metrisi_tilemetroumeni'     => ( self::reading_type( $c, $xg ) === 'telemetry' ? 'X' : '' ),

			'katigoria_paroxis_oikiaki'       => ( $ct === 'individual' ? 'X' : '' ),
			'katigoria_paroxis_epaggelmatiki' => ( in_array( $ct, [ 'company', 'sole_prop' ], true ) ? 'X' : '' ),
			// Activation / connection type.
			'energopoiisi_allagi_paroxou'      => ( $at === 'change_provider' ? 'X' : '' ),
			'energopoiisi_diadoxi'             => ( $at === 'succession' ? 'X' : '' ),
			'energopoiisi_epanasyndesi'        => ( $at === 'reconnection' ? 'X' : '' ),
			'energopoiisi_ananeosi'            => ( $at === 'renewal' ? 'X' : '' ),
			'energopoiisi_nea_syndesi'         => ( $at === 'new_connection' ? 'X' : '' ),
			'energopoiisi_allagi_programmatos' => ( $at === 'program_change' ? 'X' : '' ),
			// Generic "activation required" (new connection or reconnection).
			'energopoiisi_apaiteitai' => ( in_array( $at, [ 'new_connection', 'reconnection' ], true ) ? 'X' : '' ),
			'diarkeia_aoristou'       => ( $tm === 0 ? 'X' : '' ),
			'diarkeia_6_mines'        => ( $tm === 6 ? 'X' : '' ),
			'diarkeia_12_mines'       => ( $tm === 12 ? 'X' : '' ),
			'diarkeia_18_mines'       => ( $tm === 18 ? 'X' : '' ),
			'diarkeia_24_mines'       => ( $tm === 24 ? 'X' : '' ),
			'diarkeia_36_mines'       => ( $tm === 36 ? 'X' : '' ),

			// --- Κινητή τηλεφωνία -------------------------------------------
			// A mobile application describes a line, not a meter: the number
			// being activated, the SIM it goes on, and the tariff over time.
			'arithmos_kinitou'        => $xg( 'mobile_msisdn' ),
			'arithmos_sim'            => $xg( 'sim_number' ),
			'typos_epidotisis'        => $xg( 'subsidy_type' ),
			// Three prices because the offer changes twice: what the plan
			// normally costs, what the customer pays during the offer, and what
			// it reverts to afterwards. Printing one of them for all three is
			// how a customer is told the wrong price for month 25.
			'arxiki_timi_pagiou'      => $xg( 'base_price' ),
			'timi_prosforas'          => $xg( 'offer_price' ),
			'pagio_meta_ti_prosfora'  => $xg( 'price_after' ),

			// --- COMBO: το ηλεκτρικό σκέλος -----------------------------------
			// Το έντυπο COMBO ζητά, σε δικές του θέσεις, την παροχή και το
			// πρόγραμμα ρεύματος του ίδιου πελάτη — ξεχωριστά κλειδιά από τα
			// 'arithmos_paroxis'/'onoma_programmatos' που ήδη χρησιμοποιεί η
			// σύμβαση ρεύματος (και, τώρα, το ίδιο το πρόγραμμα κινητής): αν
			// μοιράζονταν όνομα, το COMBO θα τύπωνε το πρόγραμμα κινητής στο
			// κουτί του ρεύματος στη σελίδα 3.
			'combo_arithmos_paroxis'   => $xg( 'combo_supply_number' ),
			'combo_onoma_programmatos' => $xg( 'combo_energy_program' ),

			// --- Επιλογές που δηλώνει ο πελάτης ------------------------------
			// Answers, not settings. Each is a Ναι/Όχι the agent asks and the
			// customer decides, so an unanswered one prints neither box rather
			// than guessing — a provider form with a default opinion on the
			// customer's consent is worse than one with a gap.
			'orio_logariasmou_nai'    => ( $xg( 'bill_cap' ) === 'yes' ? 'X' : '' ),
			'orio_logariasmou_oxi'    => ( $xg( 'bill_cap' ) === 'no'  ? 'X' : '' ),
			'mitroo_11_nai'           => ( $xg( 'no_marketing_calls' ) === 'yes' ? 'X' : '' ),
			'mitroo_11_oxi'           => ( $xg( 'no_marketing_calls' ) === 'no'  ? 'X' : '' ),
			'synainesi_omilou_nai'    => ( $xg( 'group_data_consent' ) === 'yes' ? 'X' : '' ),
			'synainesi_omilou_oxi'    => ( $xg( 'group_data_consent' ) === 'no'  ? 'X' : '' ),
			'enarksi_stin_ypanaxorisi' => ( $xg( 'waive_withdrawal' ) === 'yes' ? 'X' : '' ),
			// Μονό κουτί, όχι Ναι/Όχι: το έντυπο γράφει «Δηλώνω ότι ΔΕΝ
			// επιθυμώ», οπότε το X σημαίνει άρνηση καταχώρησης.
			'mi_katachorisi_katalogous' => ( $xg( 'no_directory_listing' ) === 'yes' ? 'X' : '' ),

			// Το ίδιο flag που κρατά η σύμβαση για τη διεύθυνση αποστολής,
			// ως δύο κουτιά όπως το ζητά το έντυπο.
			'apostoli_idia_me_katoikias' => ( $addr->billingIsHome() ? 'X' : '' ),
			'apostoli_diaforetiki'       => ( $addr->billingIsHome() ? '' : 'X' ),

			// «Τρόπος υποβολής αίτησης: Ηλεκτρονικά / Ταχυδρομικά». Constant,
			// not a field: the CRM produces the application as a PDF and it is
			// submitted electronically. If that ever stops being true it needs a
			// setting, not a blank box.
			'ypovoli_ilektronika' => 'X',
			'ypovoli_taxydromika' => '',
		];
	}

	/**
	 * Fill every sheet the application needs, as one document.
	 *
	 * Electricity and gas are one form, so this used to mean "render the
	 * first (only) template". Mobile is not: a porting request or a combined-
	 * offer form rides along with the contract (ORIZON-TODO.md #5), and a
	 * partner who prints "the application" and gets only the contract has an
	 * application the provider will reject as incomplete. Merged into one
	 * PDF rather than a list of files, so the download button keeps its
	 * existing one-click behaviour.
	 *
	 * @param array       $c        Joined contract+customer row.
	 * @param string|null $sig_path Optional absolute path to a signature PNG.
	 * @return array{ok:bool,error?:string,bytes?:string,filename?:string}
	 */
	public static function fill( array $c, ?string $sig_path = null ): array {
		$keys = self::template_keys( $c );
		if ( ! $keys ) {
			return [ 'ok' => false, 'error' => 'Δεν υπάρχει ακόμη πρότυπο εντύπου για αυτόν τον πάροχο/τύπο παροχής.' ];
		}

		return self::render_merged( $keys, $c, $sig_path );
	}

	/**
	 * Every template this application needs, not just the first.
	 *
	 * Electricity and gas are one application, one form. Mobile is not: the
	 * contract is always printed, and the customer's choices add sheets of
	 * their own — a porting request, or one of the two combined-offer forms.
	 * Handing the provider the contract alone gets the application rejected.
	 *
	 * @param array<string, mixed> $c Joined contract+customer row.
	 *
	 * @return list<string>
	 */
	public static function template_keys( array $c ): array {
		$key = self::template_key(
			(string) ( $c['provider_name'] ?? $c['provider'] ?? '' ),
			(string) ( $c['energy_type'] ?? '' ),
			(string) ( $c['customer_type'] ?? '' ),
			(string) ( $c['program_name'] ?? '' ),
			(string) ( $c['activation_type'] ?? '' )
		);

		if ( $key === '' ) {
			return [];
		}

		if ( $key !== \EnergyCRM\Domain\Forms\MobilePaperwork::CONTRACT ) {
			return [ $key ];
		}

		$x = self::extras( $c );

		return \EnergyCRM\Domain\Forms\MobilePaperwork::forApplication(
			(string) ( $x['request_type'] ?? '' ),
			(string) ( $x['mobile_offer'] ?? '' )
		);
	}

	/**
	 * Render every sheet the application needs.
	 *
	 * One failure does not cancel the rest: an agent who can print three of
	 * four forms is better off than one who gets an error page, and the
	 * failure is reported alongside so it cannot pass unnoticed.
	 *
	 * @param array<string, mixed> $c
	 *
	 * @return list<array{key:string, ok:bool, error?:string, bytes?:string, filename?:string}>
	 */
	public static function fill_all( array $c, ?string $sig_path = null ): array {
		$out = [];

		foreach ( self::template_keys( $c ) as $key ) {
			$out[] = [ 'key' => $key ] + self::render( $key, $c, $sig_path );
		}

		return $out;
	}

	/**
	 * The contract's extras bag, decoded.
	 *
	 * @param array<string, mixed> $c
	 *
	 * @return array<string, mixed>
	 */
	private static function extras( array $c ): array {
		if ( empty( $c['extra_json'] ) ) {
			return [];
		}

		$d = json_decode( (string) $c['extra_json'], true );

		return is_array( $d ) ? $d : [];
	}

	/**
	 * Normalise a field entry into a list of placements.
	 *
	 * A field is usually printed once, so the map writes one object. But the
	 * same value legitimately appears in several boxes — the application code
	 * and signature date repeat on every page of a nine-page contract, and an
	 * e-mail can be asked for twice on one page. Those write a list instead.
	 *
	 * @param array<string, mixed>|list<array<string, mixed>> $entry
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function placements( $entry ): array {
		if ( ! is_array( $entry ) ) {
			return [];
		}

		// A list of placements, rather than one placement: a numeric first key.
		return isset( $entry[0] ) && is_array( $entry[0] ) ? array_values( $entry ) : [ $entry ];
	}

	/**
	 * Draw every page of one template into an already-open document.
	 *
	 * Shared by render() (one file per template, used when each Orizon sheet
	 * is stored separately) and renderMerged() (one file per application,
	 * used for the download button) so the two paths cannot silently drift
	 * apart on how a page gets drawn.
	 *
	 * @param array<string, mixed> $map
	 * @param array<string, mixed> $values
	 */
	private static function draw_pages( $pdf, string $dir, string $key, array $map, array $values, ?string $sig_path ): void {
		$w = (float) ( $map['page_w'] ?? 210 );
		$h = (float) ( $map['page_h'] ?? 297 );
		$orient = ( $w > $h ) ? 'L' : 'P';

		$p = 1;
		while ( file_exists( $dir . $key . '-' . $p . '.jpg' ) ) {
			$pdf->AddPage( $orient, [ $w, $h ] );
			$pdf->Image( $dir . $key . '-' . $p . '.jpg', 0, 0, $w, $h );

			$pdf->SetTextColor( 0, 0, 150 );
			foreach ( $map['fields'] as $field => $placements ) {
				$val = $values[ $field ] ?? '';
				if ( $val === '' ) { continue; }

				foreach ( self::placements( $placements ) as $pos ) {
					if ( (int) ( $pos['page'] ?? 1 ) !== $p ) { continue; }
					if ( ! empty( $pos['check'] ) ) {
						// Size/bold are per-field opt-ins (default: 10, regular —
						// unchanged from before this existed) so that turning one
						// checkbox bold on one template cannot shift how every
						// other provider's forms have already been printed.
						$style = ! empty( $pos['bold'] ) ? 'B' : '';
						$pdf->SetFont( 'DejaVu', $style, (float) ( $pos['size'] ?? 10 ) );
						$pdf->Text( (float) $pos['x'], (float) $pos['y'] + self::BASELINE, 'X' );
						continue;
					}
					$pdf->SetFont( 'DejaVu', '', (float) ( $pos['size'] ?? 8.5 ) );
					$pdf->Text( (float) $pos['x'], (float) $pos['y'] + self::BASELINE, (string) $val );
				}
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
	}

	/**
	 * Load one template's field map, or throw if it's missing/broken.
	 *
	 * @return array<string, mixed>
	 */
	private static function load_map( string $dir, string $key ): array {
		$mapf = $dir . $key . '.json';
		if ( ! file_exists( $dir . $key . '-1.jpg' ) || ! file_exists( $mapf ) ) {
			throw new \RuntimeException( 'Λείπει το αρχείο προτύπου για ' . $key . '.' );
		}
		$map = json_decode( (string) file_get_contents( $mapf ), true );
		if ( ! is_array( $map ) || empty( $map['fields'] ) ) {
			throw new \RuntimeException( 'Άκυρος χάρτης πεδίων για ' . $key . '.' );
		}
		return $map;
	}

	/**
	 * Draw one template into its own document.
	 *
	 * @param array<string, mixed> $c
	 *
	 * @return array{ok:bool,error?:string,bytes?:string,filename?:string}
	 */
	private static function render( string $key, array $c, ?string $sig_path ): array {
		$dir = ECRM_DIR . 'assets/forms/';

		// Each template page is bundled as a background image (assets/forms/{key}-{n}.jpg);
		// we overlay the Greek values with tFPDF (DejaVu Unicode). No PDF-import library is
		// used, so this works identically on any host regardless of PDF parser support.
		require_once ECRM_DIR . 'includes/lib/tfpdf/tfpdf.php';

		@ini_set( 'memory_limit', '256M' );
		@set_time_limit( 60 );
		$er = error_reporting();
		error_reporting( 0 );
		try {
			$map = self::load_map( $dir, $key );
			ob_start();

			$w = (float) ( $map['page_w'] ?? 210 );
			$h = (float) ( $map['page_h'] ?? 297 );
			$orient = ( $w > $h ) ? 'L' : 'P';

			$pdf = new tFPDF( $orient, 'mm', [ $w, $h ] );
			$pdf->fontpath = __DIR__ . '/lib/tfpdf/font/';
			$pdf->SetAutoPageBreak( false );
			$pdf->AddFont( 'DejaVu', '', 'DejaVuSans.ttf', true );
			$pdf->AddFont( 'DejaVu', 'B', 'DejaVuSans-Bold.ttf', true );

			self::draw_pages( $pdf, $dir, $key, $map, self::values( $c ), $sig_path );

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

	/**
	 * Draw every template an application needs into one document.
	 *
	 * tFPDF draws pages, it does not import existing PDFs — so "merge" here
	 * means keeping a single document open across every template, exactly
	 * like a multi-page contract already spans several pages of one file.
	 * Each AddPage() states its own size, so templates are free to differ in
	 * page dimensions without the earlier ones being affected.
	 *
	 * @param list<string>          $keys
	 * @param array<string, mixed>  $c
	 *
	 * @return array{ok:bool,error?:string,bytes?:string,filename?:string}
	 */
	private static function render_merged( array $keys, array $c, ?string $sig_path ): array {
		$dir    = ECRM_DIR . 'assets/forms/';
		$values = self::values( $c );

		require_once ECRM_DIR . 'includes/lib/tfpdf/tfpdf.php';

		@ini_set( 'memory_limit', '256M' );
		@set_time_limit( 60 );
		$er = error_reporting();
		error_reporting( 0 );
		try {
			ob_start();

			// The constructor's size is only a default: every AddPage() below
			// states its own, so this never has to match any real template.
			$pdf = new tFPDF( 'P', 'mm', [ 210, 297 ] );
			$pdf->fontpath = __DIR__ . '/lib/tfpdf/font/';
			$pdf->SetAutoPageBreak( false );
			$pdf->AddFont( 'DejaVu', '', 'DejaVuSans.ttf', true );
			$pdf->AddFont( 'DejaVu', 'B', 'DejaVuSans-Bold.ttf', true );

			foreach ( $keys as $key ) {
				$map = self::load_map( $dir, $key );
				self::draw_pages( $pdf, $dir, $key, $map, $values, $sig_path );
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

		$fname = 'aitisi-' . ( $c['code'] ?? '' ) . '.pdf';
		return [ 'ok' => true, 'bytes' => $bytes, 'filename' => $fname ];
	}
}
