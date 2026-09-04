<?php
/**
 * Notifications & follow-up.
 *
 * - REST GET /notifications: live list of contracts that need attention
 *   (stale items sitting too long in a non-final status, in visible scope).
 * - Email on status change to "pending" (εκκρεμότητα) to the owner.
 * - Daily WP-Cron digest emailing each partner their open follow-ups.
 *
 * Threshold (days) and the email toggles live in Energy CRM → Ρυθμίσεις.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Notifications {

	const CRON_HOOK = 'ecrm_daily_followups';

	/**
	 * Statuses that are "open" and may need follow-up.
	 *
	 * ΜΕΤΡΗΜΕΝΟ κενό, όχι εικασία (31/08/2026): μέχρι εδώ έλειπαν τα
	 * 'routed', 'signed' και 'resolved' -- δηλαδή ΑΚΡΙΒΩΣ το στάδιο «στάλθηκε
	 * στον πάροχο, περιμένουμε απάντηση» (§1.13, καμία API παρόχου δεν
	 * υπάρχει ακόμα, άρα η μόνη παρακολούθηση είναι ανθρώπινη) ήταν αόρατο
	 * και στο `followups_for()` ΚΑΙ στο `escalations()` (197) -- μια αίτηση
	 * routed μπορούσε να κάτσει επ' άπειρον χωρίς να το μάθει ούτε ο
	 * συνεργάτης ούτε ο προϊστάμενός του.
	 *
	 * Δεν ήταν σκόπιμη επιλογή: το `DashboardRepository::NOT_OPEN` (άλλο
	 * σημείο του ίδιου plugin) θεωρεί «ανοιχτό» οτιδήποτε δεν είναι
	 * ['active', 'cancelled', 'terminated', 'rejected'] -- δηλαδή ήδη
	 * συμπεριλάμβανε routed/signed/resolved ως ανοιχτά. Το `open_statuses()`
	 * εδώ απλώς δεν είχε ενημερωθεί όταν μπήκαν αυτές οι καταστάσεις.
	 *
	 * Ίδιο κατώφλι μερών με τα υπόλοιπα -- ρωτήθηκε ρητά ο ιδιοκτήτης αν το
	 * routed (περιμένει πάροχο, όχι συνεργάτη) χρειάζεται μεγαλύτερο
	 * κατώφλι, και προτίμησε το ίδιο threshold_days() παρά τη διαφορά:
	 * λιγότερη πολυπλοκότητα τώρα, μπορεί να αλλάξει αργότερα αν φανεί
	 * θόρυβος στην πράξη.
	 *
	 * Επίδραση: το `doc_check_statuses()` (ένα σύνολο πιο κάτω) παίρνει
	 * αυτόματα τα τρία νέα statuses -- σκόπιμο, όχι παράπλευρη ζημιά: αν ένα
	 * δικαιολογητικό λείψει ΜΕΤΑ το routing (π.χ. διαγράφηκε αρχείο), αξίζει
	 * να εμφανιστεί στο digest όπως και πριν.
	 */
	public static function open_statuses(): array {
		return [
			'draft', 'new', 'pending', 'processing', 'pending_signature', 'awaiting_signature',
			'routed', 'signed', 'resolved',
		];
	}

	/**
	 * Statuses to check for missing documents -- ίδιο "open" σύνολο με τα
	 * follow-ups, μείον 'draft': μια πρόχειρη αίτηση αναμένεται ελλιπής, δεν
	 * είναι κάτι να ειδοποιηθεί κανείς κάθε μέρα γι' αυτό.
	 */
	public static function doc_check_statuses(): array {
		return array_values( array_diff( self::open_statuses(), [ 'draft' ] ) );
	}

	/**
	 * Ανοιχτές αιτήσεις (ανά συνεργάτη) που δεν έχουν όλα τα απαιτούμενα
	 * δικαιολογητικά -- ίδιος υπολογισμός με ECRM_Docs::missing_labels(),
	 * απλά σε παρτίδα ανά συνεργάτη για το digest.
	 *
	 * `age_days` προστέθηκε 31/08 -- το dashboard widget «Ελλείψεις &
	 * προθεσμίες» ενοποιεί αυτή τη λίστα με leads/ληγμένα έγγραφα και τα
	 * ταξινομεί όλα μαζί κατά ηλικία· το email digest δεν το χρησιμοποιεί
	 * (ήδη ταξινομεί με δικό του τρόπο), αλλά δεν βλάπτει να υπάρχει.
	 *
	 * @return list<array{id:int, code:string, customer:string, missing:list<string>, age_days:int}>
	 */
	public static function missing_docs_for( array $ids ): array {
		global $wpdb;
		if ( ! $ids ) {
			return [];
		}
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$statuses = self::doc_check_statuses();
		$sph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.code, c.activation_type, c.energy_type,
				DATEDIFF(NOW(), c.updated_at) AS age_days,
				cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 WHERE c.partner_user_id IN ($ph) AND c.status IN ($sph)
			 ORDER BY c.updated_at ASC LIMIT 200",
			array_merge( $ids, $statuses )
		), ARRAY_A );

		$out = [];
		foreach ( $rows as $r ) {
			$missing = ECRM_Docs::missing_labels( (int) $r['id'], $r['activation_type'], $r['energy_type'] );
			if ( ! $missing ) {
				continue;
			}
			$name = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			$out[] = [
				'id'       => (int) $r['id'],
				'code'     => $r['code'],
				'customer' => $name ?: '—',
				'missing'  => $missing,
				'age_days' => (int) $r['age_days'],
			];
		}
		return $out;
	}

	/**
	 * Έγγραφα λήξιμου είδους σε ανοιχτές αιτήσεις ενός συνεργάτη που έχουν
	 * ήδη λήξει -- ίδια παρτίδα ανά partner_user_id με το missing_docs_for(),
	 * πάνω στο ECRM_Docs::expired_docs() (per-contract) που ήδη μπλοκάρει το
	 * routing (199), εδώ συγκεντρωμένο σε ΟΛΕΣ τις ανοιχτές αιτήσεις ενός
	 * συνεργάτη -- για το dashboard widget «Ελλείψεις & προθεσμίες» και,
	 * αργότερα αν χρειαστεί, ένα ακόμη digest section.
	 *
	 * Ζει εδώ και όχι στο ECRM_Docs επειδή είναι η ΙΔΙΑ δουλειά με το
	 * missing_docs_for() από πάνω -- ίδιο σχήμα ερωτήματος, ίδιος
	 * καταναλωτής -- και το ECRM_Docs ήδη καλείται από εδώ, όχι το
	 * αντίστροφο.
	 *
	 * @return list<array{contract_id:int, code:string, customer:string, kind:string, label:string, expires_at:string, age_days:int}>
	 */
	public static function expired_docs_for( array $ids ): array {
		global $wpdb;
		if ( ! $ids || ! class_exists( 'ECRM_Docs' ) ) {
			return [];
		}
		$kinds = ECRM_Docs::expirable_kinds();
		if ( ! $kinds ) {
			return [];
		}
		$ct = ECRM_DB::table( 'contracts' );
		$cu = ECRM_DB::table( 'customers' );
		$fl = ECRM_DB::table( 'files' );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$statuses = self::doc_check_statuses();
		$sph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$kph = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT f.id AS file_id, f.contract_id, f.doc_kind, f.expires_at,
				c.code, cu.first_name, cu.last_name, cu.company_name
			 FROM {$fl} f
			 INNER JOIN {$ct} c ON c.id = f.contract_id
			 LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 WHERE c.partner_user_id IN ($ph) AND c.status IN ($sph)
			   AND f.doc_kind IN ($kph) AND f.expires_at IS NOT NULL
			 ORDER BY f.contract_id ASC, f.id DESC",
			array_merge( $ids, $statuses, $kinds )
		), ARRAY_A );

		// Μόνο το πιο πρόσφατο έγγραφο ανά (σύμβαση, είδος) -- ίδιο σκεπτικό
		// με το ECRM_Docs::expired_docs(): ORDER BY f.id DESC σημαίνει η
		// πρώτη γραμμή που βλέπουμε ανά κλειδί είναι η νεότερη.
		$latest = [];
		foreach ( $rows as $r ) {
			$key = $r['contract_id'] . '|' . $r['doc_kind'];
			if ( ! isset( $latest[ $key ] ) ) {
				$latest[ $key ] = $r;
			}
		}

		$today = current_time( 'Y-m-d' );
		$out   = [];
		foreach ( $latest as $r ) {
			if ( $r['expires_at'] >= $today ) {
				continue;
			}
			$name = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			$out[] = [
				'contract_id' => (int) $r['contract_id'],
				'code'        => $r['code'],
				'customer'    => $name ?: '—',
				'kind'        => $r['doc_kind'],
				'label'       => ECRM_Docs::label( $r['doc_kind'] ),
				'expires_at'  => $r['expires_at'],
				'age_days'    => max( 0, (int) floor( ( strtotime( $today ) - strtotime( $r['expires_at'] ) ) / DAY_IN_SECONDS ) ),
			];
		}
		return $out;
	}

	public static function threshold_days(): int {
		$d = class_exists( 'ECRM_Admin' ) ? (int) ECRM_Admin::get( 'followup_days', 5 ) : 5;
		return max( 1, $d ?: 5 );
	}

	/**
	 * Πόσες μέρες αδράνειας πριν μια εκκρεμότητα ανέβει και στον προϊστάμενο,
	 * όχι μόνο στον ιδιοκτήτη της.
	 *
	 * ΜΕΤΡΗΜΕΝΟ κενό, όχι εικασία (31/08): το digest ήδη ενημερώνει ΚΑΘΕ
	 * χρήστη -- συνεργάτη ΚΑΙ προϊστάμενο -- αλλά μόνο για τις ΔΙΚΕΣ ΤΟΥ
	 * συμβάσεις (`run_digest()` καλεί `followups_for([$u->ID])`, ποτέ με τα
	 * ID της ομάδας). Δηλαδή μια εκκρεμότητα που ο συνεργάτης αγνοεί δεν
	 * φτάνει ΠΟΤΕ πουθενά αλλού -- το ΙΔΙΟ email, στον ΙΔΙΟ άνθρωπο που ήδη
	 * το αγνοεί, επ' άπειρον. Το bell (`ContractNotices`) ανεβαίνει την
	 * ιεραρχία σε πραγματικό χρόνο σε αλλαγή κατάστασης, αλλά αυτό το nag
	 * επαναλαμβανόμενης αδράνειας όχι.
	 *
	 * Προεπιλογή διπλάσιο του threshold_days(): αν κάτι δεν έχει προσεχθεί
	 * ούτε μετά από ΔΙΠΛΟ το συνηθισμένο παράθυρο, δεν είναι πια «ακόμα δεν
	 * το είδε» -- ο συνεργάτης το έχει ήδη αγνοήσει μία φορά. Filter, όχι
	 * νέα ρύθμιση στην οθόνη: κλιμακώνει αυτόματα αν αλλάξει το
	 * `followup_days`, χωρίς δεύτερο πεδίο να ξεσυγχρονιστεί μαζί του.
	 */
	public static function escalation_days(): int {
		return (int) apply_filters( 'ecrm_notifications_escalation_days', self::threshold_days() * 2 );
	}

	/**
	 * Ανοιχτές συμβάσεις αδρανείς πέρα από το escalation_days(), ομαδοποιημένες
	 * ανά ΠΡΟΪΣΤΑΜΕΝΟ (upline του ιδιοκτήτη) -- όχι ανά ιδιοκτήτη, αυτός τις
	 * βλέπει ήδη στη δική του ενότητα «εκκρεμότητες» πιο πάνω στο ίδιο email.
	 *
	 * Ένα πέρασμα σε ΟΛΗ την εταιρεία αντί για ένα per-partner, γιατί ο
	 * παραλήπτης εδώ δεν είναι ο ιδιοκτήτης της σύμβασης -- δεν ξέρουμε ποιον
	 * να ρωτήσουμε πριν υπολογίσουμε ποιος είναι ο προϊστάμενος του καθενός.
	 *
	 * Γνωστό όριο, ίδιο σχήμα με το `missing_docs_for()`: ένα
	 * `NetworkRepository::uplineOf()` (άρα `get_user_meta`) ανά αδρανή
	 * σύμβαση, όχι ανά ανοιχτή -- μόνο όσες έχουν ήδη περάσει το διπλάσιο
	 * όριο, δηλαδή μια μικρή μειοψηφία στην πράξη. Αποδεκτό στη σημερινή
	 * κλίμακα, πρώτο σημείο να μετρηθεί αν μεγαλώσει η βάση συνεργατών.
	 *
	 * Το `id`/`status` προστέθηκαν 31/08 -- η αρχική γραφή είχε μόνο
	 * `status_label` (κείμενο για το digest email), αλλά η νέα οθόνη CRM
	 * `/team/escalations` χρειάζεται το ΚΑΝΟΝΙΚΟ status για να διαλέξει
	 * σωστό χρώμα badge (ίδιες κλάσεις `.ecrm-badge--{status}` παντού) και
	 * το id για να ανοίξει τη σύμβαση -- προσθετική αλλαγή, δεν σπάει το
	 * email digest που ήδη διαβάζει μόνο το status_label.
	 *
	 * @return array<int, list<array{id:int, code:string, customer:string, status:string, status_label:string, age_days:int, owner_name:string}>> προϊστάμενος => γραμμές
	 */
	public static function escalations(): array {
		global $wpdb;
		$ct   = ECRM_DB::table( 'contracts' );
		$cu   = ECRM_DB::table( 'customers' );
		$open = self::open_statuses();
		$sph  = implode( ',', array_fill( 0, count( $open ), '%s' ) );
		$days = self::escalation_days();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.code, c.status, c.partner_user_id,
				DATEDIFF(NOW(), c.updated_at) AS age_days,
				cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 WHERE c.status IN ($sph) AND DATEDIFF(NOW(), c.updated_at) >= %d
			 ORDER BY c.updated_at ASC LIMIT 200",
			array_merge( $open, [ $days ] )
		), ARRAY_A );

		if ( ! $rows || ! class_exists( '\\EnergyCRM\\Services' ) ) {
			return [];
		}

		$labels  = ECRM_DB::statuses();
		$network = \EnergyCRM\Services::network();
		$out     = [];

		foreach ( $rows as $r ) {
			$owner_id = (int) $r['partner_user_id'];
			if ( $owner_id <= 0 ) {
				continue;
			}
			$owner = get_userdata( $owner_id );
			$name  = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			$line  = [
				'id'           => (int) $r['id'],
				'code'         => $r['code'],
				'customer'     => $name ?: '—',
				'status'       => $r['status'],
				'status_label' => $labels[ $r['status'] ] ?? $r['status'],
				'age_days'     => (int) $r['age_days'],
				'owner_name'   => $owner ? $owner->display_name : ( '#' . $owner_id ),
			];
			foreach ( $network->uplineOf( $owner_id ) as $manager_id ) {
				$out[ $manager_id ][] = $line;
			}
		}

		return $out;
	}

	/**
	 * Leads με ραντεβού επανάκλησης (`callback_at`) που έχει ήδη περάσει,
	 * ακόμα ανοιχτά -- ίδιο ερώτημα με το badge «Υποψήφιοι» της πλαϊνής
	 * μπάρας (`public/class-ecrm-app.php`, `$leads_due`), εδώ σε παρτίδα
	 * ανά συνεργάτη για το digest, ίδιο σχήμα με `followups_for()` και
	 * `missing_docs_for()`.
	 *
	 * ΜΕΤΡΗΜΕΝΟ κενό, όχι εικασία (31/08): πριν από αυτό η μόνη ένδειξη
	 * ήταν το badge -- παθητικό, μόνο όποιος ανοίξει το CRM το βλέπει. Ένα
	 * lead που περιμένει τηλέφωνο και ο συνεργάτης δεν άνοιξε καθόλου την
	 * εφαρμογή εκείνη τη μέρα δεν έφτανε ΠΟΥΘΕΝΑ αλλιώς -- σε αντίθεση με
	 * τις συμβάσεις, που ήδη είχαν το δικό τους digest section.
	 *
	 * @return list<array{id:int, name:string, phone:string, stage_label:string, callback_at:string, age_days:int}>
	 */
	public static function overdue_leads_for( array $ids ): array {
		global $wpdb;
		if ( ! $ids ) {
			return [];
		}
		$lt = ECRM_DB::table( 'leads' );
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, phone, stage, callback_at,
				DATEDIFF(NOW(), callback_at) AS age_days
			 FROM {$lt}
			 WHERE partner_user_id IN ($ph) AND stage NOT IN ('won','lost')
			   AND callback_at IS NOT NULL AND callback_at <= %s
			 ORDER BY callback_at ASC LIMIT 200",
			array_merge( $ids, [ current_time( 'mysql' ) ] )
		), ARRAY_A );

		$labels = class_exists( 'ECRM_Leads' ) ? ECRM_Leads::stages() : [];
		$out    = [];
		foreach ( $rows as $r ) {
			$out[] = [
				'id'          => (int) $r['id'],
				'name'        => $r['name'] ?: '—',
				'phone'       => (string) ( $r['phone'] ?? '' ),
				'stage_label' => $labels[ $r['stage'] ] ?? $r['stage'],
				'callback_at' => $r['callback_at'],
				'age_days'    => max( 0, (int) $r['age_days'] ),
			];
		}
		return $out;
	}

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_digest' ] );

		// Αυτοπρογραμματισμός, όπως κάνουν Retention, DocumentProtection και
		// PiiBackfill. Η schedule() καλούνταν ΜΟΝΟ από την ενεργοποίηση, οπότε
		// ένα site που ενεργοποιήθηκε πριν υπάρξει αυτή η δουλειά — ή που έχασε
		// το πρόγραμμα για οποιονδήποτε λόγο — δεν το ξαναποκτούσε ποτέ.
		// Το βρήκε η οθόνη Υγεία την πρώτη μέρα που υπήρξε.
		self::schedule();
	}

	/** Schedule/unschedule the daily digest (called on activation/deactivation). */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// 08:00 server time, daily.
			wp_schedule_event( strtotime( 'tomorrow 8:00' ), 'daily', self::CRON_HOOK );
		}
	}
	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Follow-up rows for a set of partner user IDs.
	 *
	 * @return array{rows: array, count: int, stale: int}
	 */
	public static function followups_for( array $ids ): array {
		global $wpdb;
		if ( ! $ids ) {
			return [ 'rows' => [], 'count' => 0, 'stale' => 0 ];
		}
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$open = self::open_statuses();
		$sph  = implode( ',', array_fill( 0, count( $open ), '%s' ) );
		$days = self::threshold_days();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.code, c.status, c.updated_at,
				DATEDIFF(NOW(), c.updated_at) AS age_days,
				cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c LEFT JOIN {$cu} cu ON cu.id = c.customer_id
			 WHERE c.partner_user_id IN ($ph) AND c.status IN ($sph)
			 ORDER BY c.updated_at ASC LIMIT 200",
			array_merge( $ids, $open )
		), ARRAY_A );

		$out = []; $stale = 0;
		$labels = ECRM_DB::statuses();
		foreach ( $rows as $r ) {
			$age = (int) $r['age_days'];
			$is_stale = $age >= $days;
			if ( $is_stale ) { $stale++; }
			$name = $r['company_name'] ?: trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
			$out[] = [
				'id'       => (int) $r['id'],
				'code'     => $r['code'],
				'customer' => $name ?: '—',
				'status'   => $r['status'],
				'status_label' => $labels[ $r['status'] ] ?? $r['status'],
				'age_days' => $age,
				'stale'    => $is_stale,
			];
		}
		// Stale first.
		usort( $out, function ( $a, $b ) { return $b['age_days'] <=> $a['age_days']; } );

		return [ 'rows' => $out, 'count' => count( $out ), 'stale' => $stale ];
	}

	/**
	 * Email στον κάτοχο όταν η σύμβαση μπαίνει σε «Εκκρεμότητα».
	 *
	 * Ο κάτοχος βγαίνει από τη ΣΥΜΒΑΣΗ, όχι από παράμετρο. Ως τις 19/08/2026
	 * τον δεχόταν απ' έξω, και ο `ContractLifecycle` της περνούσε το
	 * `$options['user_id']` — τιμή που τρεις από τις πέντε πόρτες γεμίζουν με
	 * τον **δράστη**. Δηλαδή όταν το back office έβαζε μια αίτηση σε
	 * εκκρεμότητα, που είναι ο συνήθης τρόπος, το «⚠ Εκκρεμότητα» έφτανε στο
	 * back office και ο συνεργάτης που έπρεπε να ενεργήσει δεν έπαιρνε τίποτα.
	 *
	 * Η ίδια τιμή δεν μπορεί να απαντά και «ποιος το έκανε» και «σε ποιον το
	 * λέμε». Τώρα το γεγονός κρατά τον δράστη και η ειδοποίηση βρίσκει μόνη της
	 * τον παραλήπτη, από το `partner_user_id` της ίδιας σύμβασης — μέσα στο
	 * ερώτημα που έτσι κι αλλιώς γινόταν, χωρίς δεύτερο.
	 */
	public static function notify_status_change( int $contract_id, string $to ): void {
		if ( $to !== 'pending' ) {
			return;
		}
		if ( class_exists( 'ECRM_Admin' ) && ! ECRM_Admin::get( 'notify_email', '1' ) ) {
			return;
		}
		global $wpdb;
		$ct  = ECRM_DB::table( 'contracts' );
		$cu  = ECRM_DB::table( 'customers' );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.code, c.partner_user_id, cu.first_name, cu.last_name, cu.company_name
			 FROM {$ct} c LEFT JOIN {$cu} cu ON cu.id=c.customer_id WHERE c.id=%d", $contract_id
		), ARRAY_A );

		$user = $row ? get_userdata( (int) $row['partner_user_id'] ) : null;
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$name    = $row ? ( $row['company_name'] ?: trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ) ) : '';
		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name', get_bloginfo( 'name' ) ) : get_bloginfo( 'name' );
		$subject = '⚠ Εκκρεμεί: ' . ( $row['code'] ?? '' ) . ' - ' . $name;
		$body    = sprintf(
			"Η σύμβαση %s (%s) μπήκε σε κατάσταση «Εκκρεμεί» και χρειάζεται ενέργεια.\n\nΣυνδέσου στο CRM για λεπτομέρειες.\n\n%s",
			$row['code'] ?? '', $name ?: 'πελάτης', $company
		);
		wp_mail( $user->user_email, $subject, $body );
	}

	/** Daily digest: email each partner with open follow-ups. */
	public static function run_digest(): void {
		\EnergyCRM\Infrastructure\Heartbeat::mark( self::CRON_HOOK );

		if ( class_exists( 'ECRM_Admin' ) && ! ECRM_Admin::get( 'notify_digest', '1' ) ) {
			return;
		}
		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name', get_bloginfo( 'name' ) ) : get_bloginfo( 'name' );
		$days    = self::threshold_days();

		// Ένα πέρασμα σε όλη την εταιρεία, πριν τον βρόχο ανά χρήστη -- όχι
		// ένα escalations() per partner. Η ίδια λίστα απλώς φιλτράρεται εδώ
		// per προϊστάμενο, ίδιο σχήμα με το followups_for()/missing_docs_for()
		// που ήδη παίρνουν [$u->ID] ένα-ένα, αλλά αυτή είναι company-wide εξ
		// ορισμού (δες escalations()).
		$escalations = self::escalations();

		$users = get_users( [ 'role__in' => array_keys( ECRM_DB::roles() ), 'fields' => [ 'ID', 'user_email', 'display_name' ] ] );
		foreach ( $users as $u ) {
			if ( ! is_email( $u->user_email ) ) { continue; }
			$data  = self::followups_for( [ (int) $u->ID ] );
			$tasks = class_exists( 'ECRM_Tasks' ) ? ECRM_Tasks::due_list( (int) $u->ID ) : [];
			$missing_docs = self::missing_docs_for( [ (int) $u->ID ] );
			$escalated    = $escalations[ (int) $u->ID ] ?? [];
			$overdue_leads = self::overdue_leads_for( [ (int) $u->ID ] );
			if ( ! $data['stale'] && ! $tasks && ! $missing_docs && ! $escalated && ! $overdue_leads ) { continue; } // nothing to report

			$sections = [];

			if ( $data['stale'] ) {
				$lines = [];
				foreach ( $data['rows'] as $r ) {
					if ( ! $r['stale'] ) { continue; }
					$lines[] = sprintf( '• %s — %s (%s, %d ημέρες)', $r['code'], $r['customer'], $r['status_label'], $r['age_days'] );
				}
				$sections[] = sprintf( "Συμβάσεις που εκκρεμούν πάνω από %d ημέρες:\n\n%s", $days, implode( "\n", $lines ) );
			}

			if ( $tasks ) {
				$tlines = [];
				foreach ( $tasks as $tk ) {
					$when = $tk['due_at'] ? mysql2date( 'd/m H:i', $tk['due_at'] ) : 'χωρίς ημ/νία';
					$code = $tk['contract_code'] ? ' [' . $tk['contract_code'] . ']' : '';
					$tlines[] = sprintf( '• %s — %s%s', $tk['title'], $when, $code );
				}
				$sections[] = sprintf( "Εργασίες προς διεκπεραίωση (%d):\n\n%s", count( $tasks ), implode( "\n", $tlines ) );
			}

			if ( $missing_docs ) {
				$mlines = [];
				foreach ( $missing_docs as $m ) {
					$mlines[] = sprintf( '• %s — %s: λείπει %s', $m['code'], $m['customer'], implode( ', ', $m['missing'] ) );
				}
				$sections[] = sprintf(
					"Αιτήσεις με ελλείψεις δικαιολογητικών (%d):\n\n%s",
					count( $missing_docs ),
					implode( "\n", $mlines )
				);
			}

			if ( $overdue_leads ) {
				$llines = [];
				foreach ( $overdue_leads as $l ) {
					$when = mysql2date( 'd/m H:i', $l['callback_at'] );
					$llines[] = sprintf(
						'• %s — %s (%s, ραντεβού %s, %d ημέρες καθυστέρηση)',
						$l['name'], $l['phone'] ?: 'χωρίς τηλέφωνο', $l['stage_label'], $when, $l['age_days']
					);
				}
				$sections[] = sprintf(
					"Υποψήφιοι με ραντεβού που πέρασε (%d):\n\n%s",
					count( $overdue_leads ),
					implode( "\n", $llines )
				);
			}

			// Δεν είναι δικές του συμβάσεις -- ξεχωριστή ενότητα, ξεκάθαρα
			// επισημασμένη ΠΟΙΟΣ τις κρατά, ώστε να μη μοιάζει με δική του
			// δουλειά που ξέχασε.
			if ( $escalated ) {
				$elines = [];
				foreach ( $escalated as $e ) {
					$elines[] = sprintf(
						'• %s — %s (%s, %d ημέρες) — συνεργάτης: %s',
						$e['code'], $e['customer'], $e['status_label'], $e['age_days'], $e['owner_name']
					);
				}
				$sections[] = sprintf(
					"Της ομάδας σου, πάνω από %d ημέρες αδράνειας (%d):\n\n%s",
					self::escalation_days(),
					count( $escalated ),
					implode( "\n", $elines )
				);
			}

			$counts  = [];
			if ( $data['stale'] ) { $counts[] = $data['stale'] . ' εκκρεμότητες'; }
			if ( $tasks ) { $counts[] = count( $tasks ) . ' εργασίες'; }
			if ( $missing_docs ) { $counts[] = count( $missing_docs ) . ' ελλείψεις'; }
			if ( $escalated ) { $counts[] = count( $escalated ) . ' της ομάδας'; }
			if ( $overdue_leads ) { $counts[] = count( $overdue_leads ) . ' υποψήφιοι'; }
			$subject = sprintf( '📋 %s - %s', implode( ' & ', $counts ), $company );
			$body    = sprintf(
				"Καλημέρα %s,\n\n%s\n\nΣυνδέσου στο CRM για να τα διαχειριστείς.\n\n%s",
				$u->display_name, implode( "\n\n", $sections ), $company
			);
			wp_mail( $u->user_email, $subject, $body );
		}
	}
}
