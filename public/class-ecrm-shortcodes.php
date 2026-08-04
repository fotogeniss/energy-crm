<?php
/**
 * Front-end markup.
 *
 *   [energy_crm_new_contract] — standalone New Contract form
 *   form_inner_html()         — shared partial reused by the app shell
 *
 * Behaviour lives in ecrm-form.js (exposes window.ECRMForm.init).
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Shortcodes {

	public static function init(): void {
		add_shortcode( 'energy_crm_new_contract', [ __CLASS__, 'new_contract' ] );
	}

	public static function enqueue_form_assets(): void {
		wp_enqueue_style( 'ecrm-form', ECRM_URL . 'public/assets/ecrm-form.css', [], ECRM_VERSION );
		wp_enqueue_script( 'ecrm-form', ECRM_URL . 'public/assets/ecrm-form.js', [], ECRM_VERSION, true );
		// Capabilities are sent so the UI can hide what the user cannot do.
		// This is presentation only — every one of them is enforced again on
		// the server, because anything reaching the browser is a suggestion.
		$caps = [];
		foreach ( \EnergyCRM\Access\Capability::all() as $capability ) {
			$caps[ $capability ] = current_user_can( $capability );
		}

		wp_localize_script( 'ecrm-form', 'ECRM', [
			'rest'     => esc_url_raw( rest_url( ECRM_REST::NS ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'statuses' => ECRM_DB::statuses(),
			'caps'     => $caps,
		] );
	}

	public static function new_contract(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="ecrm-gate">Συνδέσου για να καταχωρίσεις νέα σύμβαση.</div>';
		}
		self::enqueue_form_assets();
		return '<div class="ecrm" data-standalone="1">' . self::form_inner_html() . '</div>';
	}

	/**
	 * The inner form markup (no outer .ecrm wrapper, no enqueue).
	 * Reused inside the app shell's "Νέα Σύμβαση" view.
	 */
	public static function form_inner_html(): string {
		$cats = [ 'home' => '🏠 Οικιακό', 'business' => '🏢 Επαγγελματικό', 'communal' => '🏛 Κοινόχρηστο' ];
		$pts  = [ 'fixed' => '🔵 Σταθερό', 'special' => '🟢 Ειδικό', 'variable' => '🟡 Κυμαινόμενο', 'dynamic' => '🟠 Δυναμικό' ];
		$ctyp = [ 'individual' => '👤 Ιδιώτης', 'sole_prop' => '🧾 Ατομική Επιχείρηση', 'company' => '🏢 Εταιρεία' ];
		$acts = ECRM_DB::activation_types();

		ob_start();
		?>
		<div class="ecrm-form" data-step="config" lang="el">
			<header class="ecrm-head">
				<div class="ecrm-eyebrow">Αίτηση</div>
				<h2 class="ecrm-title" data-form-title>Δημιουργία Αίτησης</h2>
				<p class="ecrm-sub">Διάλεξε πάροχο και πρόγραμμα, σύρε τα έγγραφα και η φόρμα συμπληρώνεται μόνη της.</p>
			</header>

			<section class="ecrm-card">
				<div class="ecrm-step"><span class="ecrm-step__n">1</span> Επιλογή Παρόχου <span class="ecrm-step__hint" data-selprov></span></div>
				<div class="ecrm-providers" data-providers><div class="ecrm-loading">Φόρτωση παρόχων…</div></div>
			</section>

			<section class="ecrm-card">
				<div class="ecrm-step"><span class="ecrm-step__n">2</span> Είδος &amp; κατηγορία πελάτη</div>

				<div class="ecrm-row">
					<span class="ecrm-row__label">Είδος</span>
					<div class="ecrm-chips" data-field="energy_type">
						<button type="button" class="ecrm-chip is-on" data-val="power">⚡ Ηλεκτρισμός</button>
						<button type="button" class="ecrm-chip" data-val="gas">🔥 Φυσικό Αέριο</button>
						<button type="button" class="ecrm-chip" data-val="mobile">📱 Κινητή Τηλεφωνία</button>
					</div>
				</div>

				<div class="ecrm-row">
					<span class="ecrm-row__label">Κατηγορία</span>
					<div class="ecrm-chips" data-field="category">
						<?php $first = true; foreach ( $cats as $v => $l ) : ?>
							<button type="button" class="ecrm-chip <?php echo $first ? 'is-on' : ''; ?>" data-val="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></button>
						<?php $first = false; endforeach; ?>
					</div>
				</div>

				<div class="ecrm-row">
					<span class="ecrm-row__label">Χρώμα</span>
					<div class="ecrm-chips" data-field="price_type">
						<?php $first = true; foreach ( $pts as $v => $l ) : ?>
							<button type="button" class="ecrm-chip <?php echo $first ? 'is-on' : ''; ?>" data-val="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></button>
						<?php $first = false; endforeach; ?>
					</div>
				</div>

				<div class="ecrm-row">
					<span class="ecrm-row__label">Τιμολόγιο</span>
					<div class="ecrm-chips" data-field="invoice_code">
						<button type="button" class="ecrm-chip" data-val="Γ1">Γ1</button>
						<button type="button" class="ecrm-chip" data-val="Γ21">Γ21</button>
						<button type="button" class="ecrm-chip" data-val="Γ22">Γ22</button>
						<button type="button" class="ecrm-chip" data-val="Γ23">Γ23</button>
					</div>
				</div>

				<div class="ecrm-row">
					<span class="ecrm-row__label">Πρόγραμμα</span>
					<select class="ecrm-select" data-program data-field="program_id"><option value="">—</option></select>
				</div>

				<div class="ecrm-row">
					<span class="ecrm-row__label">Τύπος πελάτη</span>
					<div class="ecrm-chips" data-field="customer_type">
						<?php $first = true; foreach ( $ctyp as $v => $l ) : ?>
							<button type="button" class="ecrm-chip <?php echo $first ? 'is-on' : ''; ?>" data-val="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></button>
						<?php $first = false; endforeach; ?>
					</div>
				</div>

				<div class="ecrm-row">
					<span class="ecrm-row__label">Ενεργοποίηση</span>
					<div class="ecrm-chips ecrm-chips--wrap" data-field="activation_type">
						<?php foreach ( $acts as $v => $l ) : ?>
							<button type="button" class="ecrm-chip" data-val="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( strtoupper( $l ) ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			<!-- Auto: required documents from Knowledge Base -->
			<section class="ecrm-card ecrm-kbdocs" data-kbdocs hidden>
				<div class="ecrm-step"><span class="ecrm-step__n ecrm-step__n--kb">📋</span> Απαιτούμενα δικαιολογητικά</div>
				<div class="ecrm-kbdocs__body" data-kbdocs-body></div>
			</section>

			<!-- Auto: possible duplicate warning -->
			<div class="ecrm-dupwarn" data-dupwarn hidden></div>

			<section class="ecrm-card ecrm-card--ai">
				<div class="ecrm-step"><span class="ecrm-step__n ecrm-step__n--ai">AI</span> Εξαγωγή στοιχείων</div>
				<p class="ecrm-ai-lead">Ταυτότητα + λογαριασμός παρόχου → η AI βγάζει τα στοιχεία και γεμίζει τη φόρμα.</p>

				<div class="ecrm-drop" data-drop tabindex="0" role="button" aria-label="Σύρε αρχεία ή πάτα για επιλογή">
					<input type="file" data-files accept="application/pdf,image/jpeg,image/png" multiple hidden>
					<div class="ecrm-drop__icon">⬆</div>
					<div class="ecrm-drop__title">Σύρε αρχεία εδώ</div>
					<div class="ecrm-drop__hint">ή <button type="button" class="ecrm-link" data-pick>πάτα για επιλογή</button> · PDF, JPG, PNG · έως 10 αρχεία</div>
				</div>

				<ul class="ecrm-filelist" data-filelist></ul>
				<button type="button" class="ecrm-btn ecrm-btn--ai" data-extract disabled>✨ Εξαγωγή με AI</button>
				<div class="ecrm-ai-status" data-ai-status aria-live="polite"></div>
			</section>

			<?php
			// Helper to print a field. $extra=true marks it for the extra_json bag.
			$ecrm_field = function ( $name, $label, $type = 'text', $extra = false, $ph = '' ) {
				printf(
					'<label class="ecrm-field" data-for="%1$s"><span class="ecrm-field__label">%2$s</span><input type="%3$s" name="%1$s" class="ecrm-input"%4$s autocomplete="off" placeholder="%5$s"></label>',
					esc_attr( $name ), esc_html( $label ), esc_attr( $type ),
					$extra ? ' data-extra="1"' : '', esc_attr( $ph )
				);
			};
			?>

			<section class="ecrm-card">
				<div class="ecrm-step"><span class="ecrm-step__n">3</span> Στοιχεία Πελάτη</div>

				<div class="ecrm-grid">
					<label class="ecrm-field" data-for="afm">
						<span class="ecrm-field__label">ΑΦΜ</span>
						<span class="ecrm-input-wrap"><input type="text" name="afm" class="ecrm-input" autocomplete="off"><button type="button" class="ecrm-input-btn" data-afm-search aria-label="Αναζήτηση ΑΦΜ">🔍</button></span>
					</label>
					<?php $ecrm_field( 'doy', 'Δ.Ο.Υ' ); ?>
					<?php $ecrm_field( 'postal_code', 'Τ.Κ' ); ?>
				</div>

				<!-- φυσικό πρόσωπο -->
				<div class="ecrm-grid" data-when="individual,sole_prop">
					<?php $ecrm_field( 'first_name', 'Όνομα' ); ?>
					<?php $ecrm_field( 'last_name', 'Επίθετο' ); ?>
					<?php $ecrm_field( 'father_name', 'Πατρώνυμο' ); ?>
					<?php $ecrm_field( 'adt', 'Α.Δ.Τ / Διαβατήριο' ); ?>
					<?php $ecrm_field( 'birth_date', 'Ημ. Γέννησης', 'date' ); ?>
				</div>

				<!-- εταιρεία -->
				<div class="ecrm-grid" data-when="company,sole_prop">
					<?php $ecrm_field( 'company_name', 'Επωνυμία Εταιρείας' ); ?>
					<?php $ecrm_field( 'company_type', 'Τύπος Εταιρείας', 'text', true ); ?>
					<?php $ecrm_field( 'activity', 'Δραστηριότητα', 'text', true ); ?>
				</div>

				<div class="ecrm-subhead">Διεύθυνση</div>
				<div class="ecrm-grid">
					<?php $ecrm_field( 'region', 'Νομός' ); ?>
					<?php $ecrm_field( 'city', 'Πόλη' ); ?>
					<?php $ecrm_field( 'street', 'Οδός' ); ?>
					<?php $ecrm_field( 'street_no', 'Αριθμός' ); ?>
				</div>

				<div class="ecrm-subhead">Επικοινωνία</div>
				<div class="ecrm-grid">
					<?php $ecrm_field( 'phone', 'Τηλέφωνο' ); ?>
					<?php $ecrm_field( 'mobile', 'Κινητό' ); ?>
					<?php $ecrm_field( 'email', 'Email', 'email' ); ?>
				</div>
			</section>

			<!-- Νόμιμος Εκπρόσωπος (εταιρείες) -->
			<section class="ecrm-card" data-when="company,sole_prop">
				<div class="ecrm-step"><span class="ecrm-step__n">4</span> Νόμιμος Εκπρόσωπος</div>
				<div class="ecrm-grid">
					<?php $ecrm_field( 'rep_first_name', 'Όνομα', 'text', true ); ?>
					<?php $ecrm_field( 'rep_last_name', 'Επώνυμο', 'text', true ); ?>
					<?php $ecrm_field( 'rep_father_name', 'Πατρώνυμο', 'text', true ); ?>
					<?php $ecrm_field( 'rep_id', 'Αρ. Ταυτότητας / Διαβατηρίου', 'text', true ); ?>
					<?php $ecrm_field( 'rep_afm', 'ΑΦΜ Νόμιμου Εκπροσώπου', 'text', true ); ?>
					<?php $ecrm_field( 'rep_birth_date', 'Ημ. Γέννησης', 'date', true ); ?>
					<?php $ecrm_field( 'rep_phone', 'Σταθερό', 'text', true ); ?>
					<?php $ecrm_field( 'rep_mobile', 'Κινητό', 'text', true ); ?>
					<?php $ecrm_field( 'rep_email', 'Email', 'email', true ); ?>
				</div>
			</section>

			<!-- Υπεύθυνος Επικοινωνίας -->
			<section class="ecrm-card">
				<div class="ecrm-step"><span class="ecrm-step__n">5</span> Υπεύθυνος Επικοινωνίας</div>
				<label class="ecrm-syncbar"><input type="checkbox" data-sync="contact"> Ίδια στοιχεία με τον πελάτη / εκπρόσωπο — αυτόματος συγχρονισμός</label>
				<div class="ecrm-grid">
					<?php $ecrm_field( 'contact_first_name', 'Όνομα', 'text', true ); ?>
					<?php $ecrm_field( 'contact_last_name', 'Επώνυμο', 'text', true ); ?>
					<?php $ecrm_field( 'contact_father_name', 'Πατρώνυμο', 'text', true ); ?>
					<?php $ecrm_field( 'contact_adt', 'Α.Δ.Τ', 'text', true ); ?>
					<?php $ecrm_field( 'contact_mobile', 'Κινητό', 'text', true ); ?>
					<?php $ecrm_field( 'contact_email', 'Email', 'email', true ); ?>
					<?php $ecrm_field( 'contact_phone', 'Τηλέφωνο', 'text', true ); ?>
					<?php $ecrm_field( 'contact_afm', 'ΑΦΜ Υπεύθυνου', 'text', true ); ?>
				</div>
			</section>

			<!-- Στοιχεία Μετρητή -->
			<section class="ecrm-card">
				<div class="ecrm-step"><span class="ecrm-step__n">6</span> Στοιχεία Μετρητή</div>
				<label class="ecrm-syncbar"><input type="checkbox" data-sync="meter_addr"> Ίδια διεύθυνση με τα στοιχεία πελάτη — συγχρονισμός οδού/αριθμού/ΤΚ/πόλης</label>
				<div class="ecrm-grid">
					<?php $ecrm_field( 'supply_number', 'Αριθμός Παροχής / ΗΚΑΣΠ' ); ?>
					<?php $ecrm_field( 'meter_number', 'Αριθμός Μετρητή' ); ?>
					<?php $ecrm_field( 'region_code', 'Κωδ. Περιφέρειας', 'text', true ); ?>
					<?php $ecrm_field( 'successor_no', 'Διάδοχος', 'text', true ); ?>
					<?php $ecrm_field( 'meter_activity', 'Δραστηριότητα', 'text', true ); ?>
					<?php $ecrm_field( 'previous_provider', 'Προηγούμενος Πάροχος', 'text', true ); ?>
					<label class="ecrm-field" data-for="capacity_role">
						<span class="ecrm-field__label">Ιδιότητα</span>
						<select name="capacity_role" class="ecrm-input" data-extra="1">
							<option value="">—</option><option value="owner">Ιδιοκτήτης</option><option value="tenant">Ενοικιαστής</option><option value="manager">Διαχειριστής</option>
						</select>
					</label>
					<?php $ecrm_field( 'agreed_power', 'Συμφωνημένη Ισχύς (kVA)', 'text', true ); ?>
					<?php $ecrm_field( 'day_indication', 'Ένδειξη Ημέρας', 'text', true ); ?>
					<?php $ecrm_field( 'guarantee', 'Εγγύηση (€)', 'text', true ); ?>
					<?php $ecrm_field( 'promotion', 'Promotion', 'text', true ); ?>
				</div>
				<div class="ecrm-subhead">Διεύθυνση Μετρητή</div>
				<div class="ecrm-grid">
					<?php $ecrm_field( 'meter_postal', 'Τ.Κ', 'text', true ); ?>
					<?php $ecrm_field( 'meter_city', 'Πόλη', 'text', true ); ?>
					<?php $ecrm_field( 'meter_region', 'Νομός', 'text', true ); ?>
					<?php $ecrm_field( 'meter_street', 'Οδός', 'text', true ); ?>
					<?php $ecrm_field( 'meter_street_no', 'Αριθμός', 'text', true ); ?>
				</div>
			</section>

			<!-- Στοιχεία Τιμολόγησης -->
			<section class="ecrm-card">
				<div class="ecrm-step"><span class="ecrm-step__n">7</span> Στοιχεία Τιμολόγησης</div>
				<div class="ecrm-grid">
					<label class="ecrm-field" data-for="payment_method">
						<span class="ecrm-field__label">Τρόπος Πληρωμής</span>
						<select name="payment_method" class="ecrm-input" data-extra="1">
							<option value="">—</option><option value="standing_order">Πάγια Εντολή</option><option value="manual">Με την παραλαβή</option>
						</select>
					</label>
					<label class="ecrm-field" data-for="bill_delivery">
						<span class="ecrm-field__label">Τρόπος Αποστολής Λογαριασμού</span>
						<select name="bill_delivery" class="ecrm-input" data-extra="1">
							<option value="">—</option><option value="email">Email</option><option value="post">Ταχυδρομικώς</option><option value="both">Και τα δύο</option>
						</select>
					</label>
				</div>
				<div class="ecrm-subhead">Διάρκεια Σύμβασης</div>
				<div class="ecrm-grid">
					<?php $ecrm_field( 'start_date', 'Ημ. Έναρξης', 'date' ); ?>
					<label class="ecrm-field" data-for="term_months">
						<span class="ecrm-field__label">Διάρκεια Σύμβασης</span>
						<select name="term_months" class="ecrm-input">
							<option value="">—</option>
							<option value="0">Αορίστου</option>
							<option value="6">6 μήνες</option>
							<option value="12">12 μήνες</option>
							<option value="18">18 μήνες</option>
							<option value="24">24 μήνες</option>
							<option value="36">36 μήνες</option>
						</select>
					</label>
					<?php $ecrm_field( 'end_date', 'Ημ. Λήξης', 'date' ); ?>
				</div>
				<p class="ecrm-hint" data-term-hint>Αν συμπληρώσεις Έναρξη + Διάρκεια, η Λήξη υπολογίζεται αυτόματα.</p>
			</section>

			<!-- Ενέργειες σύμβασης -->
			<div class="ecrm-actbanners">
				<button type="button" class="ecrm-actbanner ecrm-actbanner--amber" data-act="pdf">
					<span class="ecrm-actbanner__ic">🖨</span>
					<span class="ecrm-actbanner__tx"><b>Εκτύπωση Σύμβασης</b><span>Δημιουργείται αυτόματα το PDF του συμβολαίου με τα στοιχεία της φόρμας</span></span>
				</button>
				<button type="button" class="ecrm-actbanner ecrm-actbanner--purple" data-act="email">
					<span class="ecrm-actbanner__ic">✉</span>
					<span class="ecrm-actbanner__tx"><b>Αποστολή στον πελάτη για ηλεκτρονική υπογραφή</b><span>Στέλνεται email στον πελάτη με σύνδεσμο υπογραφής</span></span>
				</button>
				<button type="button" class="ecrm-actbanner ecrm-actbanner--teal" data-act="livelink">
					<span class="ecrm-actbanner__ic">🔗</span>
					<span class="ecrm-actbanner__tx"><b>Δημιουργία συνδέσμου για live υπογραφή</b><span>Σύνδεσμος για υπογραφή επί τόπου ή αποστολή με μήνυμα</span></span>
				</button>
			</div>

			<section class="ecrm-card">
				<div class="ecrm-step"><span class="ecrm-step__n">8</span> Σχόλια Αίτησης</div>
				<textarea class="ecrm-textarea" data-notes name="notes" rows="3" placeholder="Επιπλέον σημειώσεις…"></textarea>
			</section>

			<footer class="ecrm-foot">
				<label class="ecrm-consent"><input type="checkbox" data-consent name="consent" value="1"> Ο πελάτης συναινεί στην επεξεργασία των προσωπικών του δεδομένων για τη σύναψη/διαχείριση της σύμβασης (GDPR).</label>
				<div class="ecrm-foot__mode">Λειτουργία: <strong>Νέα αίτηση</strong></div>
				<div class="ecrm-foot__actions">
					<button type="button" class="ecrm-btn ecrm-btn--ghost" data-save-draft>Προσωρινή Αποθήκευση</button>
					<button type="button" class="ecrm-btn ecrm-btn--primary" data-finalize>Οριστικοποίηση</button>
				</div>
			</footer>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
