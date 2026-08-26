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

	/**
	 * Every front-end module, as `import specifier => file`.
	 *
	 * The specifiers are bare on purpose. A relative import — './ecrm-util.js'
	 * — is a URL the browser caches on its own terms, and WordPress has no way
	 * to put a version on it: only the entry point got ?ver, so an edited
	 * helper stayed stale until somebody thought to hard-reload. That cost two
	 * rounds of a verification that looked like it had passed. A bare specifier
	 * is resolved through the import map instead, and the map carries a version
	 * for *every* file in the graph.
	 *
	 * @var array<string, string>
	 */
	private const MODULES = [
		'@energy-crm/util'             => 'ecrm-util.js',
		'@energy-crm/format'           => 'ecrm-format.js',
		'@energy-crm/scope'            => 'ecrm-scope.js',
		'@energy-crm/view-commissions' => 'ecrm-view-commissions.js',
		'@energy-crm/view-analytics'   => 'ecrm-view-analytics.js',
		'@energy-crm/export-modal'     => 'ecrm-export-modal.js',
		'@energy-crm/dialog'           => 'ecrm-dialog.js',
		'@energy-crm/view-detail'      => 'ecrm-view-detail.js',
		'@energy-crm/view-contracts'   => 'ecrm-view-contracts.js',
		'@energy-crm/view-pending'     => 'ecrm-view-pending.js',
		'@energy-crm/navigate'         => 'ecrm-navigate.js',
		'@energy-crm/view-leads'       => 'ecrm-view-leads.js',
		'@energy-crm/view-renewals'    => 'ecrm-view-renewals.js',
		'@energy-crm/view-tasks'       => 'ecrm-view-tasks.js',
		'@energy-crm/view-calc'        => 'ecrm-view-calc.js',
		'@energy-crm/view-customers'   => 'ecrm-view-customers.js',
		'@energy-crm/view-dashboard'   => 'ecrm-view-dashboard.js',
		'@energy-crm/view-import'      => 'ecrm-view-import.js',
		'@energy-crm/view-kb'          => 'ecrm-view-kb.js',
		'@energy-crm/view-network'     => 'ecrm-view-network.js',
		'@energy-crm/view-partner'     => 'ecrm-view-partner.js',
		'@energy-crm/view-team'        => 'ecrm-view-team.js',
		'@energy-crm/view-team-live'   => 'ecrm-view-team-live.js',
		'@energy-crm/form'             => 'ecrm-form.js',
		'@energy-crm/app'              => 'ecrm-app.js',
		'@energy-crm/litsa'            => 'ecrm-litsa.js',
	];

	/** What each module imports, so the map carries the whole graph. */
	private const MODULE_DEPS = [
		'@energy-crm/util'             => [],
		'@energy-crm/format'           => [],
		'@energy-crm/scope'            => [],
		'@energy-crm/view-commissions' => [ '@energy-crm/util' ],
		'@energy-crm/view-analytics'   => [ '@energy-crm/util' ],
		'@energy-crm/export-modal'     => [ '@energy-crm/util' ],
		'@energy-crm/dialog'           => [ '@energy-crm/util' ],
		'@energy-crm/view-detail'      => [
			'@energy-crm/util',
			'@energy-crm/format',
			'@energy-crm/navigate',
			'@energy-crm/dialog',
		],
		'@energy-crm/view-contracts'   => [
			'@energy-crm/util',
			'@energy-crm/format',
			'@energy-crm/navigate',
			'@energy-crm/export-modal',
			'@energy-crm/dialog',
			'@energy-crm/scope',
		],
		'@energy-crm/view-pending'     => [
			'@energy-crm/util',
			'@energy-crm/format',
			'@energy-crm/navigate',
			'@energy-crm/scope',
		],
		'@energy-crm/navigate'         => [],
		'@energy-crm/view-leads'       => [ '@energy-crm/util', '@energy-crm/format', '@energy-crm/navigate' ],
		'@energy-crm/view-renewals'    => [ '@energy-crm/util', '@energy-crm/format', '@energy-crm/navigate' ],
		'@energy-crm/view-tasks'       => [ '@energy-crm/util', '@energy-crm/format', '@energy-crm/navigate' ],
		'@energy-crm/view-calc'        => [ '@energy-crm/util', '@energy-crm/format' ],
		'@energy-crm/view-customers'   => [ '@energy-crm/util', '@energy-crm/format' ],
		'@energy-crm/view-dashboard'   => [ '@energy-crm/util', '@energy-crm/format' ],
		'@energy-crm/view-import'      => [ '@energy-crm/util', '@energy-crm/format' ],
		'@energy-crm/view-kb'          => [ '@energy-crm/util', '@energy-crm/format' ],
		'@energy-crm/view-network'     => [ '@energy-crm/util', '@energy-crm/format' ],
		'@energy-crm/view-partner'     => [ '@energy-crm/util', '@energy-crm/format', '@energy-crm/navigate' ],
		'@energy-crm/view-team'        => [ '@energy-crm/util', '@energy-crm/format', '@energy-crm/navigate' ],
		'@energy-crm/view-team-live'   => [ '@energy-crm/util', '@energy-crm/format' ],
		'@energy-crm/form'             => [ '@energy-crm/util' ],
		'@energy-crm/app'              => [
			'@energy-crm/util',
			'@energy-crm/format',
			'@energy-crm/view-commissions',
			'@energy-crm/view-analytics',
			'@energy-crm/view-calc',
			'@energy-crm/view-customers',
			'@energy-crm/view-dashboard',
			'@energy-crm/view-import',
			'@energy-crm/view-kb',
			'@energy-crm/view-network',
			'@energy-crm/view-partner',
			'@energy-crm/view-team',
			'@energy-crm/view-team-live',
			'@energy-crm/navigate',
			'@energy-crm/view-leads',
			'@energy-crm/view-renewals',
			'@energy-crm/view-tasks',
			'@energy-crm/export-modal',
			'@energy-crm/dialog',
			'@energy-crm/view-detail',
			'@energy-crm/view-contracts',
			'@energy-crm/view-pending',
		],
		'@energy-crm/litsa'            => [ '@energy-crm/util' ],
	];

	public static function init(): void {
		add_shortcode( 'energy_crm_new_contract', [ __CLASS__, 'new_contract' ] );
	}

	/**
	 * Register every module with a version taken from the file itself.
	 *
	 * filemtime() rather than ECRM_VERSION: the plugin version changes on a
	 * release, and a helper edited between releases would otherwise keep its
	 * old URL. The whole point of this exercise is that a changed file is a
	 * changed URL, without anyone having to remember anything.
	 *
	 * Idempotent — WordPress ignores a second registration of the same id, and
	 * both entry points call this.
	 */
	private static function register_modules(): void {
		foreach ( self::MODULES as $id => $file ) {
			wp_register_script_module(
				$id,
				ECRM_URL . 'public/assets/' . $file,
				self::MODULE_DEPS[ $id ] ?? [],
				self::asset_version( $file )
			);
		}
	}

	/**
	 * Η έκδοση ενός asset, από το ΙΔΙΟ το αρχείο.
	 *
	 * Ήταν ιδιωτική λογική μέσα στην register_modules() και κάλυπτε μόνο τα JS
	 * modules. Τα δύο CSS έβγαιναν με `ECRM_VERSION` — που αλλάζει σε release,
	 * όχι σε επεξεργασία αρχείου. Αποτέλεσμα: κάθε διόρθωση CSS έμενε αόρατη
	 * πίσω από την cache του browser μέχρι κάποιος να θυμηθεί hard-reload, και
	 * μια πραγματική διόρθωση έμοιαζε με «δεν δούλεψε». Το βρήκε ο ιδιοκτήτης
	 * στις 23/08: τρεις διαδοχικές διορθώσεις CSS για κινητό δεν φάνηκαν καμία.
	 *
	 * Το σκεπτικό της register_modules() ίσχυε ήδη λέξη προς λέξη — «ένα
	 * αλλαγμένο αρχείο είναι ένα αλλαγμένο URL, χωρίς να χρειάζεται να το
	 * θυμάται κανείς». Απλώς δεν είχε φτάσει ως το CSS.
	 */
	public static function asset_version( string $file ): string {
		$path = ECRM_DIR . 'public/assets/' . $file;

		return file_exists( $path ) ? (string) filemtime( $path ) : ECRM_VERSION;
	}

	/**
	 * The configuration object every module reads before it does anything.
	 *
	 * wp_localize_script() does not work with script modules — there is no
	 * module equivalent — so this is printed as a classic inline script. That
	 * is not a workaround so much as the guarantee: a classic script runs while
	 * the document is still parsing, and modules are deferred until after, so
	 * ECRM is always defined before any module body starts.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function print_config( array $data ): void {
		// The app shell calls enqueue_form_assets() and the standalone form
		// shortcode does too; on a page with both, the hook would be added
		// twice and window.ECRM assigned twice.
		static $done = false;

		if ( $done ) {
			return;
		}

		$done = true;

		add_action(
			'wp_footer',
			static function () use ( $data ): void {
				wp_print_inline_script_tag(
					'window.ECRM = ' . wp_json_encode( $data ) . ';',
					[ 'id' => 'ecrm-config' ]
				);
			},
			1
		);
	}

	public static function enqueue_form_assets(): void {
		wp_enqueue_style( 'ecrm-form', ECRM_URL . 'public/assets/ecrm-form.css', [], self::asset_version( 'ecrm-form.css' ) );

		self::register_modules();
		wp_enqueue_script_module( '@energy-crm/form' );

		// Capabilities are sent so the UI can hide what the user cannot do.
		// This is presentation only — every one of them is enforced again on
		// the server, because anything reaching the browser is a suggestion.
		$caps = [];
		foreach ( \EnergyCRM\Access\Capability::all() as $capability ) {
			$caps[ $capability ] = current_user_can( $capability );
		}

		self::print_config( [
			'rest'     => esc_url_raw( rest_url( \EnergyCRM\Http\Router::NAMESPACE ) ),
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
		// Οι ετικέτες είναι ΣΚΕΤΟ ΚΕΙΜΕΝΟ. Τα emoji που ήταν ψημένα εδώ μέσα
		// («🏠 Οικιακό») περνούσαν από esc_html() μαζί με τη λέξη, οπότε το
		// εικονίδιο δεν μπορούσε ποτέ να γίνει τίποτα άλλο από emoji — και το
		// ζωγράφιζε το λειτουργικό, με δικό του χρώμα και δικό του βάρος.
		//
		// Τώρα το εικονίδιο μπαίνει από το CSS πάνω στο data-val του chip, με
		// mask + currentColor: κληρονομεί το χρώμα του κειμένου, άρα γίνεται
		// πράσινο μόνο του όταν το chip είναι ενεργό. Δες ecrm-form.css.
		$cats = [ 'home' => 'Οικιακό', 'business' => 'Επαγγελματικό', 'communal' => 'Κοινόχρηστο' ];
		$pts  = [ 'fixed' => 'Σταθερό', 'special' => 'Ειδικό', 'variable' => 'Κυμαινόμενο', 'dynamic' => 'Δυναμικό' ];
		$ctyp = [ 'individual' => 'Ιδιώτης', 'sole_prop' => 'Ατομική Επιχείρηση', 'company' => 'Εταιρεία' ];
		$acts = ECRM_DB::activation_types();

		ob_start();
		?>
		<div class="ecrm-form" data-step="config" lang="el">
			<header class="ecrm-head">
				<div class="ecrm-eyebrow">Αίτηση</div>
				<h2 class="ecrm-title" data-form-title>Δημιουργία Αίτησης</h2>
				<p class="ecrm-sub">Διάλεξε πάροχο και πρόγραμμα, σύρε τα έγγραφα και η φόρμα συμπληρώνεται μόνη της.</p>
			</header>

			<!-- Ο wizard: τέσσερα βήματα, όπως οι B1-B4 του UX kit. Η μπάρα είναι
			     ΚΟΥΜΠΙΑ, όχι διακόσμηση: πίσω ελεύθερα, μπροστά όσο έχεις φτάσει. -->
			<nav class="ecrm-wsteps" data-wsteps aria-label="Βήματα αίτησης">
				<button type="button" class="ecrm-wstep__b is-on" data-wgo="1" aria-current="step"><strong>Βήμα 1</strong>Πάροχος &amp; τύπος</button>
				<button type="button" class="ecrm-wstep__b" data-wgo="2"><strong>Βήμα 2</strong>Έγγραφα &amp; AI</button>
				<button type="button" class="ecrm-wstep__b" data-wgo="3"><strong>Βήμα 3</strong>Στοιχεία &amp; πρόγραμμα</button>
				<button type="button" class="ecrm-wstep__b" data-wgo="4"><strong>Βήμα 4</strong>Έλεγχος &amp; υπογραφή</button>
			</nav>

			<div class="ecrm-wstep" data-wstep="1">

			<section class="ecrm-card">
				<div class="ecrm-step"><span class="ecrm-step__n">1</span> Επιλογή Παρόχου <span class="ecrm-step__hint" data-selprov></span></div>
				<div class="ecrm-providers" data-providers><div class="ecrm-loading">Φόρτωση παρόχων…</div></div>
			</section>

			<section class="ecrm-card">
				<div class="ecrm-step"><span class="ecrm-step__n">2</span> Είδος &amp; κατηγορία πελάτη</div>

				<div class="ecrm-row">
					<span class="ecrm-row__label">Είδος</span>
					<div class="ecrm-chips" data-field="energy_type">
						<button type="button" class="ecrm-chip is-on" data-val="power">Ηλεκτρισμός</button>
						<button type="button" class="ecrm-chip" data-val="gas">Φυσικό Αέριο</button>
						<button type="button" class="ecrm-chip" data-val="mobile">Κινητή Τηλεφωνία</button>
					</div>
				</div>

				<?php
				// data-when-energy: σε ποια είδη παροχής ανήκει η επιλογή.
				// Χωρίς αυτό, ο συνεργάτης που διάλεγε κινητή έβλεπε «Γ1/Γ21»,
				// «Σταθερό/Κυμαινόμενο» και «Επανασύνδεση» — έννοιες ρεύματος
				// που δεν σημαίνουν τίποτα σε γραμμή κινητής.
				?>
				<div class="ecrm-row">
					<span class="ecrm-row__label">Κατηγορία</span>
					<div class="ecrm-chips" data-field="category">
						<?php $first = true; foreach ( $cats as $v => $l ) : ?>
							<button type="button" class="ecrm-chip <?php echo $first ? 'is-on' : ''; ?>" data-val="<?php echo esc_attr( $v ); ?>"<?php echo $v === 'communal' ? ' data-when-energy="power,gas"' : ''; ?>><?php echo esc_html( $l ); ?></button>
						<?php $first = false; endforeach; ?>
					</div>
				</div>

				<div class="ecrm-row" data-when-energy="power,gas">
					<span class="ecrm-row__label">Χρώμα</span>
					<div class="ecrm-chips" data-field="price_type">
						<?php $first = true; foreach ( $pts as $v => $l ) : ?>
							<button type="button" class="ecrm-chip <?php echo $first ? 'is-on' : ''; ?>" data-val="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></button>
						<?php $first = false; endforeach; ?>
					</div>
				</div>

				<?php // Τα Γ-τιμολόγια είναι κωδικοί ρεύματος. ?>
				<div class="ecrm-row" data-when-energy="power">
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

				<?php
				// Η διαδοχή, η επανασύνδεση και η αλλαγή παρόχου περιγράφουν
				// μετρητή. Το αντίστοιχο της αλλαγής παρόχου στην κινητή είναι
				// η φορητότητα, και έχει δικό της έντυπο.
				$act_energy = [
					'change_provider' => 'power,gas',
					'succession'      => 'power,gas',
					'reconnection'    => 'power,gas',
					'program_change'  => 'power,gas',
					'portability'     => 'mobile',
				];
				?>
				<div class="ecrm-row">
					<span class="ecrm-row__label">Ενεργοποίηση</span>
					<div class="ecrm-chips ecrm-chips--wrap" data-field="activation_type">
						<?php foreach ( $acts as $v => $l ) : ?>
							<button type="button" class="ecrm-chip" data-val="<?php echo esc_attr( $v ); ?>"<?php
								echo isset( $act_energy[ $v ] ) ? ' data-when-energy="' . esc_attr( $act_energy[ $v ] ) . '"' : '';
							?>><?php echo esc_html( strtoupper( $l ) ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			</div>

			<div class="ecrm-wstep" data-wstep="2" hidden>

			<!-- Auto: required documents from Knowledge Base -->
			<section class="ecrm-card ecrm-kbdocs" data-kbdocs hidden>
				<div class="ecrm-step"><span class="ecrm-step__n ecrm-step__n--kb"><svg class="ecrm-i ecrm-i--step" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4h6v3H9zM7 5H5v16h14V5h-2M9 12h6M9 16h4"/></svg></span> Απαιτούμενα δικαιολογητικά</div>
				<div class="ecrm-kbdocs__body" data-kbdocs-body></div>
			</section>

			<section class="ecrm-card ecrm-card--ai">
				<div class="ecrm-step"><span class="ecrm-step__n ecrm-step__n--ai">AI</span> Εξαγωγή στοιχείων</div>
				<p class="ecrm-ai-lead">Ταυτότητα + λογαριασμός παρόχου → η AI βγάζει τα στοιχεία και γεμίζει τη φόρμα.</p>

				<div class="ecrm-drop" data-drop tabindex="0" role="button" aria-label="Σύρε αρχεία ή πάτα για επιλογή">
					<input type="file" data-files accept="application/pdf,image/jpeg,image/png" multiple hidden>
					<div class="ecrm-drop__icon">⬆</div>
					<div class="ecrm-drop__title">Σύρε αρχεία εδώ</div>
					<div class="ecrm-drop__hint">ή <button type="button" class="ecrm-link" data-pick>πάτα για επιλογή</button> · PDF, JPG, PNG · έως 10 αρχεία</div>
				</div>
				<?php
				/*
				 * Δεύτερη, ξεχωριστή είσοδος για τη ΦΩΤΟΓΡΑΦΙΑ — όχι το ίδιο input
				 * με `capture` προσθεμένο. Το `capture` σε είσοδο που δέχεται PDF
				 * ΚΑΙ πολλαπλά αρχεία έχει ασυνεπή συμπεριφορά ανά browser (συνήθως
				 * κλειδώνει σε μία φωτογραφία, αγνοώντας `multiple` και PDF) — θα
				 * έσπαγε την επιλογή αρχείου. Δύο εισόδους, ένα `addFiles()` και
				 * στις δύο (ecrm-form.js): ο χρήστης διαλέγει ρητά κάμερα ή αρχείο,
				 * όχι το browser για λογαριασμό του. Ζητήθηκε ρητά από τον
				 * ιδιοκτήτη, 23/08.
				 *
				 * Το κουμπί μένει στο DOM σε κάθε συσκευή — κρύβεται σε desktop
				 * μόνο με CSS (`@media (hover: hover) and (pointer: fine)` στο
				 * ecrm-form.css), ρητή αντιστροφή της 23/08 από τον ιδιοκτήτη,
				 * 2026-08-24: σε desktop δεν έχει νόημα κουμπί λήψης φωτογραφίας.
				 */
				?>
				<button type="button" class="ecrm-btn ecrm-btn--ghost ecrm-camera-btn" data-camera-pick>
					<svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l1.5-2h7L17 8h3a1 1 0 011 1v10a1 1 0 01-1 1H4a1 1 0 01-1-1V9a1 1 0 011-1z"/><circle cx="12" cy="13" r="3.5"/></svg>
					Λήψη φωτογραφίας
				</button>
				<input type="file" data-camera accept="image/jpeg,image/png" capture="environment" hidden>

				<ul class="ecrm-filelist" data-filelist></ul>
				<button type="button" class="ecrm-btn ecrm-btn--ai" data-extract disabled><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6z"/><path d="M18 15l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></svg> Εξαγωγή με AI</button>
				<div class="ecrm-ai-status" data-ai-status aria-live="polite"></div>
			</section>

			</div>

			<?php
			// Helper to print a field. $extra=true marks it for the extra_json bag.
			// $energy_when, when non-empty, adds data-when-energy so a field can be
			// scoped to power/gas or mobile without a wrapper element around it.
			// $ac is the autocomplete token: 'off' by default, since most of these
			// fields (ΑΦΜ, ΑΔΤ, αριθμός παροχής, μετρητή) have no browser-known
			// shape and a wrong guess is worse than none. Fields that DO map to a
			// standard token (name/address/phone/email) pass it explicitly below,
			// each scoped to its own section (section-cust/rep/contact/supply/
			// bill) so the browser doesn't offer the customer's mobile for the
			// legal representative's, or the shipping address for the billing one.
			// $when προστέθηκε 25/08 (αίτημα ιδιοκτήτη, αναδιάταξη Στοιχείων
			// Πελάτη): ίδιο νόημα με το data-when ενός ολόκληρου .ecrm-grid
			// wrapper, αλλά ΣΤΟ ΙΔΙΟ το πεδίο — ώστε ένα πεδίο μόνο για
			// φυσικά πρόσωπα (π.χ. ΑΔΤ) να μπορεί να μπει ΔΙΠΛΑ σε ένα κοινό
			// πεδίο (π.χ. ΑΦΜ) μέσα στο ίδιο .ecrm-grid, χωρίς να χρειαστεί
			// δύο ξεχωριστά grid. Το applyCustomerType() στο ecrm-form.js
			// ήδη διαβάζει ΚΑΘΕ [data-when] στη σελίδα (qa('[data-when]')),
			// όχι μόνο div wrappers — μηδέν αλλαγή JS χρειάστηκε.
			$ecrm_field = function ( $name, $label, $type = 'text', $extra = false, $ph = '', $energy_when = '', $ac = 'off', $when = '' ) {
				printf(
					'<label class="ecrm-field" data-for="%1$s"%6$s%8$s><span class="ecrm-field__label">%2$s</span><input type="%3$s" name="%1$s" class="ecrm-input"%4$s autocomplete="%7$s" placeholder="%5$s"></label>',
					esc_attr( $name ), esc_html( $label ), esc_attr( $type ),
					$extra ? ' data-extra="1"' : '', esc_attr( $ph ),
					$energy_when !== '' ? ' data-when-energy="' . esc_attr( $energy_when ) . '"' : '',
					esc_attr( $ac ),
					$when !== '' ? ' data-when="' . esc_attr( $when ) . '"' : ''
				);
			};

			// Yes/No select, used by the customer-facing questions in Στοιχεία
			// Τιμολόγησης. Defined here (not inline where it's used) because it
			// used to live inside the mobile section and moved out with the
			// fields it belongs to — see ORIZON-TODO.md #3.
			$ecrm_yesno = function ( $name, $label ) {
				printf(
					'<label class="ecrm-field" data-for="%1$s"><span class="ecrm-field__label">%2$s</span>'
					. '<select name="%1$s" class="ecrm-input" data-extra="1">'
					. '<option value="">—</option><option value="yes">Ναι</option><option value="no">Όχι</option>'
					. '</select></label>',
					esc_attr( $name ),
					esc_html( $label )
				);
			};
			?>

			<div class="ecrm-wstep" data-wstep="3" hidden>

			<!-- Auto: possible duplicate warning. Ζει ΕΔΩ και όχι πιο πάνω, γιατί
			     την πυροδοτούν το ΑΦΜ και ο αριθμός παροχής, που είναι αυτής της
			     οθόνης· σε άλλο βήμα θα εμφανιζόταν εκεί που κανείς δεν κοιτά. -->
			<div class="ecrm-dupwarn" data-dupwarn hidden></div>

			<section class="ecrm-card">
				<div class="ecrm-step"><span class="ecrm-step__n">3</span> Στοιχεία Πελάτη</div>

				<!-- Αναδιάταξη 25/08 (ζητήθηκε ρητά, live στην οθόνη): πρώτα η
				     ταυτότητα του φυσικού προσώπου (Όνομα/Επίθετο/Ημ.Γέννησης/
				     Πατρώνυμο), μετά οι αριθμοί (ΑΦΜ/ΑΔΤ/Δ.Ο.Υ) σε μία σειρά,
				     το Τ.Κ πήγε στη Διεύθυνση όπου ανήκει. Δύο grid αντί για
				     ένα ΜΟΝΟ επειδή το πρώτο είναι απών εξ ολοκλήρου για
				     εταιρεία (data-when στο ίδιο το div), ενώ το δεύτερο έχει
				     ΑΦΜ/Δ.Ο.Υ κοινά + ΑΔΤ μόνο για φυσικό πρόσωπο (data-when
				     στο ίδιο το πεδίο, νέα δυνατότητα του $ecrm_field). -->
				<!-- φυσικό πρόσωπο -->
				<div class="ecrm-grid" data-when="individual,sole_prop">
					<?php $ecrm_field( 'first_name', 'Όνομα', 'text', false, '', '', 'section-cust given-name' ); ?>
					<?php $ecrm_field( 'last_name', 'Επίθετο', 'text', false, '', '', 'section-cust family-name' ); ?>
					<?php $ecrm_field( 'birth_date', 'Ημ. Γέννησης', 'date', false, '', '', 'section-cust bday' ); ?>
					<?php $ecrm_field( 'father_name', 'Πατρώνυμο' ); ?>
				</div>

				<!-- Build queue 08, 25/08: εμφανίζεται όταν το ΑΦΜ που μόλις
				     γράφτηκε ανήκει ήδη σε γνωστό πελάτη (scoped στον
				     συνεργάτη — δες /customers/check). Άδειο by default, το
				     γεμίζει το ecrm-form.js σε blur του ΑΦΜ. -->
				<div class="ecrm-custmatch" data-custmatch hidden></div>

				<div class="ecrm-grid">
					<label class="ecrm-field" data-for="afm">
						<span class="ecrm-field__label">ΑΦΜ <abbr title="Υποχρεωτικό για να προχωρήσει η αίτηση πέρα από το πρόχειρο">*</abbr></span>
						<span class="ecrm-input-wrap"><input type="text" name="afm" class="ecrm-input" autocomplete="off"><button type="button" class="ecrm-input-btn" data-afm-search aria-label="Αναζήτηση ΑΦΜ"><svg class="ecrm-i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg></button></span>
					</label>
					<?php $ecrm_field( 'adt', 'Α.Δ.Τ / Διαβατήριο', 'text', false, '', '', 'off', 'individual,sole_prop' ); ?>
					<?php $ecrm_field( 'doy', 'Δ.Ο.Υ' ); ?>
				</div>

				<!-- εταιρεία -->
				<div class="ecrm-grid" data-when="company,sole_prop">
					<?php $ecrm_field( 'company_name', 'Επωνυμία Εταιρείας', 'text', false, '', '', 'section-cust organization' ); ?>
					<?php $ecrm_field( 'company_type', 'Νομική Μορφή', 'text', true, 'Α.Ε. / Ε.Π.Ε. / Ο.Ε.' ); ?>
					<?php $ecrm_field( 'activity', 'Αντικείμενο Δραστηριότητας', 'text', true ); ?>
					<?php // Ζητούνται ρητά στα επαγγελματικά έντυπα NRG και Protergia. ?>
					<?php $ecrm_field( 'kad', 'Κ.Α.Δ.', 'text', true ); ?>
					<?php $ecrm_field( 'gemi', 'Αρ. Γ.Ε.ΜΗ.', 'text', true ); ?>
				</div>

				<div class="ecrm-subhead">Διεύθυνση</div>
				<div class="ecrm-grid">
					<?php $ecrm_field( 'region', 'Νομός', 'text', false, '', '', 'section-cust address-level1' ); ?>
					<?php $ecrm_field( 'city', 'Πόλη', 'text', false, '', '', 'section-cust address-level2' ); ?>
					<?php $ecrm_field( 'street', 'Οδός', 'text', false, '', '', 'section-cust address-line1' ); ?>
					<?php $ecrm_field( 'street_no', 'Αριθμός' ); ?>
					<?php // Τ.Κ μετακόμισε εδώ 25/08 (ζητήθηκε ρητά) — είναι
					      // κομμάτι της διεύθυνσης, όχι της ταυτότητας του πελάτη. ?>
					<?php $ecrm_field( 'postal_code', 'Τ.Κ', 'text', false, '', '', 'section-cust postal-code' ); ?>
				</div>

				<div class="ecrm-subhead">Επικοινωνία</div>
				<div class="ecrm-grid">
					<?php $ecrm_field( 'phone', 'Τηλέφωνο', 'text', false, '', '', 'section-cust tel' ); ?>
					<?php $ecrm_field( 'mobile', 'Κινητό', 'text', false, '', '', 'section-cust tel' ); ?>
					<?php $ecrm_field( 'email', 'Email', 'email', false, '', '', 'section-cust email' ); ?>
				</div>
			</section>

			<!--
				Πεδία που ζητά συγκεκριμένα η αίτηση του επιλεγμένου παρόχου.
				Γεμίζει από το /forms/fields με τις ετικέτες του ίδιου του
				εντύπου, ώστε ο συνεργάτης να διαβάζει στην οθόνη ό,τι θα δει
				και στο χαρτί.
			-->
			<section class="ecrm-card ecrm-provform" data-provider-fields hidden>
				<div class="ecrm-step"><span class="ecrm-step__n"><svg class="ecrm-i ecrm-i--step" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg></span> <span data-provform-title>Απαιτούμενα από τον πάροχο</span></div>
				<div class="ecrm-grid" data-provform-grid></div>
			</section>

			<?php
			// Νόμιμος εκπρόσωπος και υπεύθυνος επικοινωνίας περιγράφουν μια
			// επιχειρηματική σύμβαση ρεύματος/αερίου· κανένα από τα τέσσερα
			// έντυπα Orizon δεν τα ζητά (επιβεβαιωμένο με grep στα JSON), οπότε
			// κρύβονται στην κινητή. Το εξωτερικό div είναι απαραίτητο για το
			// πρώτο section: το δικό του data-when (τύπος πελάτη) δεν μπορεί
			// να συνυπάρξει με data-when-energy στο ίδιο στοιχείο, γιατί το JS
			// applyCustomerType() καλεί applyEnergyType() αμέσως μετά και το
			// δεύτερο θα σκέπαζε το πρώτο.
			?>
			<div data-when-energy="power,gas">
			<!-- Νόμιμος Εκπρόσωπος (εταιρείες) -->
			<section class="ecrm-card" data-when="company,sole_prop">
				<div class="ecrm-step"><span class="ecrm-step__n">4</span> Νόμιμος Εκπρόσωπος</div>
				<!-- Ίδιο μοτίβο με το data-sync="contact" της επόμενης κάρτας: για
				     ατομική επιχείρηση ο εκπρόσωπος ΕΙΝΑΙ ο πελάτης, το ίδιο φυσικό
				     πρόσωπο — 9 πεδία που έχουν ήδη γραφτεί στα Στοιχεία Πελάτη. -->
				<label class="ecrm-syncbar"><input type="checkbox" data-sync="rep"> Ίδια στοιχεία με τον πελάτη — αυτόματος συγχρονισμός</label>
				<div class="ecrm-grid">
					<?php $ecrm_field( 'rep_first_name', 'Όνομα', 'text', true, '', '', 'section-rep given-name' ); ?>
					<?php $ecrm_field( 'rep_last_name', 'Επώνυμο', 'text', true, '', '', 'section-rep family-name' ); ?>
					<?php $ecrm_field( 'rep_father_name', 'Πατρώνυμο', 'text', true ); ?>
					<?php $ecrm_field( 'rep_id', 'Αρ. Ταυτότητας / Διαβατηρίου', 'text', true ); ?>
					<?php $ecrm_field( 'rep_afm', 'ΑΦΜ Νόμιμου Εκπροσώπου', 'text', true ); ?>
					<?php $ecrm_field( 'rep_birth_date', 'Ημ. Γέννησης', 'date', true, '', '', 'section-rep bday' ); ?>
					<?php $ecrm_field( 'rep_phone', 'Σταθερό', 'text', true, '', '', 'section-rep tel' ); ?>
					<?php $ecrm_field( 'rep_mobile', 'Κινητό', 'text', true, '', '', 'section-rep tel' ); ?>
					<?php $ecrm_field( 'rep_email', 'Email', 'email', true, '', '', 'section-rep email' ); ?>
				</div>
			</section>
			</div>

			<!-- Υπεύθυνος Επικοινωνίας -->
			<section class="ecrm-card" data-when-energy="power,gas">
				<div class="ecrm-step"><span class="ecrm-step__n">5</span> Υπεύθυνος Επικοινωνίας</div>
				<label class="ecrm-syncbar"><input type="checkbox" data-sync="contact"> Ίδια στοιχεία με τον πελάτη / εκπρόσωπο — αυτόματος συγχρονισμός</label>
				<div class="ecrm-grid">
					<?php $ecrm_field( 'contact_first_name', 'Όνομα', 'text', true, '', '', 'section-contact given-name' ); ?>
					<?php $ecrm_field( 'contact_last_name', 'Επώνυμο', 'text', true, '', '', 'section-contact family-name' ); ?>
					<?php $ecrm_field( 'contact_father_name', 'Πατρώνυμο', 'text', true ); ?>
					<?php $ecrm_field( 'contact_adt', 'Α.Δ.Τ', 'text', true ); ?>
					<?php $ecrm_field( 'contact_mobile', 'Κινητό', 'text', true, '', '', 'section-contact tel' ); ?>
					<?php $ecrm_field( 'contact_email', 'Email', 'email', true, '', '', 'section-contact email' ); ?>
					<?php $ecrm_field( 'contact_phone', 'Τηλέφωνο', 'text', true, '', '', 'section-contact tel' ); ?>
					<?php $ecrm_field( 'contact_afm', 'ΑΦΜ Υπεύθυνου', 'text', true ); ?>
				</div>
			</section>

			<!-- Στοιχεία Μετρητή -->
			<?php
			// Παροχή, μετρητής, ισχύς, τιμολόγιο, προηγούμενος πάροχος: κάθε
			// πεδίο εδώ περιγράφει ρολόι σε τοίχο. Σε αίτηση κινητής δεν
			// υπάρχει τίποτα από αυτά να συμπληρωθεί, και το τμήμα έμενε
			// ορατό επειδή ήταν το μόνο χωρίς δήλωση είδους παροχής.
			?>
			<section class="ecrm-card" data-when-energy="power,gas">
				<div class="ecrm-step"><span class="ecrm-step__n">6</span> Στοιχεία Μετρητή</div>
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
					<label class="ecrm-field" data-for="meter_position">
						<span class="ecrm-field__label">Θέση Μετρητή</span>
						<select name="meter_position" class="ecrm-input" data-extra="1">
							<option value="">—</option><option value="inside">Εσωτερικός</option><option value="outside">Εξωτερικός</option>
						</select>
					</label>
					<label class="ecrm-field" data-for="meter_reading_type">
						<span class="ecrm-field__label">Μέτρηση</span>
						<select name="meter_reading_type" class="ecrm-input" data-extra="1">
							<option value="">—</option>
							<option value="day">Ημερήσια</option>
							<option value="day_night">Ημερήσια &amp; Νυχτερινή</option>
							<option value="telemetry">Τηλεμετρούμενη</option>
						</select>
					</label>
					<?php $ecrm_field( 'eidiki_katigoria', 'Ειδική Κατηγορία', 'text', true, 'Ευάλωτος / Κ.Ο.Τ.' ); ?>
					<?php // Η εγγύηση μετακόμισε στα Στοιχεία Τιμολόγησης — τη ζητά και η κινητή, όχι μόνο ρεύμα/αέριο. ?>
					<?php $ecrm_field( 'promotion', 'Promotion', 'text', true ); ?>
				</div>
				<?php
				// Where the meter actually is. Real columns, not the extras bag:
				// every provider form has a "ΔΙΕΥΘΥΝΣΗ ΠΑΡΟΧΗΣ" box, and until
				// these were stored properly it printed the customer's home
				// address — right until the meter is in a rented shop.
				?>
				<div class="ecrm-subhead">Διεύθυνση Παροχής <span class="ecrm-hint">— εκεί που βρίσκεται ο μετρητής</span></div>
				<label class="ecrm-syncbar">
					<input type="checkbox" name="supply_addr_same" value="1" data-addr-same="supply" checked>
					Ίδια με τη διεύθυνση του πελάτη
				</label>
				<div class="ecrm-grid" data-addr-fields="supply" hidden>
					<?php $ecrm_field( 'supply_street', 'Οδός', 'text', false, '', '', 'section-supply shipping address-line1' ); ?>
					<?php $ecrm_field( 'supply_street_no', 'Αριθμός' ); ?>
					<?php $ecrm_field( 'supply_postal_code', 'Τ.Κ', 'text', false, '', '', 'section-supply shipping postal-code' ); ?>
					<?php $ecrm_field( 'supply_city', 'Πόλη', 'text', false, '', '', 'section-supply shipping address-level2' ); ?>
					<?php $ecrm_field( 'supply_region', 'Νομός', 'text', false, '', '', 'section-supply shipping address-level1' ); ?>
				</div>

				<?php
				// The box every provider form labels "εφόσον είναι διαφορετική
				// από τη διεύθυνση κατοικίας". It had no data behind it at all.
				?>
				<div class="ecrm-subhead">Διεύθυνση Αποστολής Λογαριασμού</div>
				<label class="ecrm-syncbar">
					<input type="checkbox" name="billing_addr_same" value="1" data-addr-same="billing" checked>
					Ίδια με τη διεύθυνση του πελάτη
				</label>
				<div class="ecrm-grid" data-addr-fields="billing" hidden>
					<?php $ecrm_field( 'billing_street', 'Οδός', 'text', false, '', '', 'section-bill billing address-line1' ); ?>
					<?php $ecrm_field( 'billing_street_no', 'Αριθμός' ); ?>
					<?php $ecrm_field( 'billing_postal_code', 'Τ.Κ', 'text', false, '', '', 'section-bill billing postal-code' ); ?>
					<?php $ecrm_field( 'billing_city', 'Πόλη', 'text', false, '', '', 'section-bill billing address-level2' ); ?>
					<?php $ecrm_field( 'billing_region', 'Νομός', 'text', false, '', '', 'section-bill billing address-level1' ); ?>
				</div>
			</section>

			<?php
			// Κινητή τηλεφωνία. Ένα δικό της τμήμα και όχι πεδία σκορπισμένα
			// στα υπάρχοντα: η αίτηση κινητής περιγράφει γραμμή, όχι μετρητή,
			// και τίποτα εδώ δεν έχει νόημα σε ρεύμα ή αέριο.
			?>
			<section class="ecrm-card" data-when-energy="mobile">
				<div class="ecrm-step"><span class="ecrm-step__n">6β</span> Στοιχεία Κινητής</div>
				<div class="ecrm-grid">
					<?php
					// Το «Τύπος Αίτησης» έφυγε από εδώ 2026-08-24: μπέρδευε τον
					// πράκτορα δίπλα στο ήδη υπάρχον «Ενεργοποίηση» του Βήματος
					// 1, που για mobile δείχνει «Νέα σύνδεση» / «Ανανέωση» /
					// «Φορητότητα» — τρεις πραγματικές τιμές (ECRM_DB::
					// activation_types() έχει και τις τρεις, οι δύο πρώτες
					// αφύλακτες στο $act_energy παρακάτω, ήδη ορατές). Το
					// request_type παράγεται πλέον αυτόματα από εκείνη την
					// επιλογή στο backend (ContractSaveMapping::contractFrom,
					// τρίτη περίπτωση 'renewal' — διόρθωση 2026-08-24, η πρώτη
					// γραφή αυτού του σημείου νόμιζε ότι υπήρχαν μόνο δύο
					// τιμές) — μία επιλογή στην οθόνη, όχι δύο συγχρονισμένα
					// πεδία.
					?>

					<?php
					// Μία επιλογή και όχι δύο διακόπτες: οι δύο προσφορές δίνουν
					// την ίδια τιμή για διαφορετικό λόγο — η συνδυαστική δένει
					// κινητή με κινητή, το COMBO κινητή με ρεύμα — και δεν
					// συνδυάζονται. Δύο ανεξάρτητα κουτάκια θα επέτρεπαν να
					// τσεκαριστούν και τα δύο.
					?>
					<label class="ecrm-field" data-for="mobile_offer">
						<span class="ecrm-field__label">Συνδυαστική Έκπτωση</span>
						<select name="mobile_offer" class="ecrm-input" data-extra="1">
							<option value="">Καμία</option>
							<option value="family">Συνδυαστική Προσφορά (κινητή + κινητή)</option>
							<option value="combo">COMBO Έκπτωση Ρεύματος (κινητή + ρεύμα)</option>
						</select>
					</label>

					<?php $ecrm_field( 'mobile_msisdn', 'Αριθμός Κινητού', 'text', true, '69…' ); ?>
					<?php $ecrm_field( 'sim_number', 'Αριθμός Κάρτας SIM', 'text', true ); ?>
					<?php $ecrm_field( 'subsidy_type', 'Τύπος Επιδότησης', 'text', true, 'π.χ. Έκπτωση Παγίου' ); ?>
				</div>

				<?php
				// Το COMBO είναι το μόνο σημείο όπου η κινητή αγγίζει το ρεύμα:
				// το έντυπό του ζητά την παροχή και το πρόγραμμα ενέργειας του
				// ίδιου πελάτη, και ποιος από τους δύο είναι ο κύριος χρήστης
				// της γραμμής (orizon_combo.json: xristis_kyrios/xristis_defterevon).
				// Εμφανίζεται μόνο όταν επιλεγεί, γιατί σε κάθε άλλη περίπτωση
				// είναι ακριβώς το είδος πεδίου που δεν έχει θέση σε αίτηση κινητής.
				?>
				<div class="ecrm-grid" data-when-offer="combo" hidden>
					<?php $ecrm_field( 'combo_supply_number', 'Αριθμός Παροχής / ΗΚΑΣΠ Ρεύματος', 'text', true ); ?>
					<?php $ecrm_field( 'combo_energy_program', 'Πρόγραμμα Ρεύματος', 'text', true ); ?>
					<label class="ecrm-field" data-for="combo_user_role">
						<span class="ecrm-field__label">Χρήστης Γραμμής</span>
						<select name="combo_user_role" class="ecrm-input" data-extra="1">
							<option value="">—</option>
							<option value="main">Κύριος Χρήστης</option>
							<option value="secondary">Δευτερεύων Χρήστης</option>
						</select>
					</label>
				</div>

				<?php
				// Τρεις τιμές, γιατί η προσφορά αλλάζει δύο φορές: τι κοστίζει
				// κανονικά, τι πληρώνει όσο τρέχει η προσφορά, και πού
				// επιστρέφει μετά. Μία τιμή για τα τρία σημαίνει ότι κάποιος
				// μαθαίνει λάθος τιμή για τον 25ο μήνα.
				?>
				<?php
				// Το πλάνο έχει σταθερή, δημοσιευμένη τιμή — δεν είναι κάτι που
				// διαπραγματεύεται ο συνεργάτης. readonly ώστε να φαίνεται η
				// πραγματική τιμή που θα τυπωθεί αντί για κενά κουτιά που θα
				// αγνοηθούν σιωπηλά (values.php πάντα προτιμά το MobilePlans
				// όταν υπάρχει έγκυρο program_code). Γεμίζουν από JS
				// (updateMobilePricing) όταν αλλάζει πρόγραμμα ή προσφορά.
				?>
				<div class="ecrm-subhead">Τιμολόγηση Προγράμματος <span class="ecrm-hint">— υπολογίζεται αυτόματα από το πρόγραμμα</span></div>
				<div class="ecrm-grid">
					<label class="ecrm-field" data-for="base_price">
						<span class="ecrm-field__label">Αρχική Τιμή Παγίου (€)</span>
						<input type="text" name="base_price" class="ecrm-input" data-extra="1" readonly autocomplete="off">
					</label>
					<label class="ecrm-field" data-for="offer_price">
						<span class="ecrm-field__label">Τιμή Προσφοράς ανά Μήνα (€)</span>
						<input type="text" name="offer_price" class="ecrm-input" data-extra="1" readonly autocomplete="off">
					</label>
					<label class="ecrm-field" data-for="price_after">
						<span class="ecrm-field__label">Πάγιο μετά τη Λήξη Προσφοράς (€)</span>
						<input type="text" name="price_after" class="ecrm-input" data-extra="1" readonly autocomplete="off">
					</label>
				</div>
			</section>

			<!-- Στοιχεία Τιμολόγησης -->
			<section class="ecrm-card">
				<div class="ecrm-step"><span class="ecrm-step__n">7</span> Στοιχεία Τιμολόγησης</div>
				<div class="ecrm-grid">
					<label class="ecrm-field" data-for="payment_method" data-when-energy="power,gas">
						<span class="ecrm-field__label">Τρόπος Πληρωμής</span>
						<select name="payment_method" class="ecrm-input" data-extra="1">
							<option value="">—</option><option value="standing_order">Πάγια Εντολή</option><option value="manual">Με την παραλαβή</option>
						</select>
					</label>
					<label class="ecrm-field" data-for="bill_delivery" data-when-energy="power,gas">
						<span class="ecrm-field__label">Τρόπος Αποστολής Λογαριασμού</span>
						<select name="bill_delivery" class="ecrm-input" data-extra="1">
							<option value="">—</option><option value="email">Email</option><option value="post">Ταχυδρομικώς</option><option value="both">Και τα δύο</option>
						</select>
					</label>
					<?php
					// Το πεδίο IBAN αφαιρέθηκε 2026-08-11. Κανένα από τα 20 έντυπα
					// δεν είχε θέση να το τυπώσει, οπότε ζητούσαμε τραπεζικό
					// λογαριασμό που δεν πήγαινε πουθενά. Το αποδεικτικό
					// λογαριασμού παραμένει ως είδος ανεβασμένου εγγράφου, όταν
					// το ζητά ο πάροχος.
					?>
					<?php // Χωρίς guard: το orizon_mobile.json ζητά κι αυτό εγγύηση, όχι μόνο ρεύμα/αέριο. ?>
					<?php $ecrm_field( 'guarantee', 'Εγγύηση (€)', 'text', true ); ?>
					<?php $ecrm_field( 'ar_koinoxristou', 'Αρ. Κοινόχρηστου Μετρητή', 'text', true, '', 'power,gas' ); ?>
				</div>

				<?php
				// Απαντήσεις του πελάτη, όχι ρυθμίσεις της εταιρείας. Μόνο
				// ρεύμα/αέριο: κανένα από τα τέσσερα έντυπα Orizon δεν τυπώνει
				// αυτά τα κλειδιά (επιβεβαιωμένο με grep σε όλα τα JSON) — πριν
				// ζούσαν μέσα στο τμήμα κινητής, όπου ήταν ακριβώς ανάποδα
				// ορατά: φαινόντουσαν μόνο όταν δεν εξυπηρετούσαν κανέναν.
				?>
				<div data-when-energy="power,gas">
					<div class="ecrm-subhead">Ερωτήσεις προς τον πελάτη <span class="ecrm-hint">— απαντά ο ίδιος</span></div>
					<div class="ecrm-grid">
						<?php
						$ecrm_yesno( 'bill_cap', 'Θέλει ανώτατο όριο λογαριασμού;' );
						$ecrm_field( 'anotato_orio', 'Ανώτατο Όριο (€)', 'text', true );
						$ecrm_yesno( 'no_marketing_calls', 'Μητρώο άρθρου 11 — να ΜΗΝ δέχεται προωθητικές κλήσεις;' );
						$ecrm_yesno( 'group_data_consent', 'Συναινεί στην επεξεργασία δεδομένων από τον όμιλο;' );
						// Χωριστή ερώτηση από την προηγούμενη: τα νέα οικιακά έντυπα
						// Protergia έχουν δικό τους ΝΑΙ/ΟΧΙ γι' αυτήν (όρος Η), και
						// συναίνεση που δεν τη ζήτησες δεν μπορείς να τη σημειώσεις.
						$ecrm_yesno( 'survey_consent', 'Δέχεται τηλεφωνικές έρευνες ικανοποίησης πελατών;' );
						$ecrm_yesno( 'waive_withdrawal', 'Θέλει άμεση έναρξη, παραιτούμενος από το δικαίωμα υπαναχώρησης;' );
						$ecrm_yesno( 'no_directory_listing', 'Να ΜΗΝ καταχωρηθεί στους τηλεφωνικούς καταλόγους;' );
						?>
					</div>
				</div>

				<?php
				// Το «Ημ. Έναρξης» έφυγε εντελώς 2026-08-24: κανένα από τα ~20
				// έντυπα δεν το τυπώνει ποτέ (επιβεβαιωμένο με grep σε όλα τα
				// JSON) — ζητούσε ημερομηνία που δεν πήγαινε πουθενά, ίδιο σχήμα
				// με το IBAN της (78). Η στήλη `start_date` ΜΕΝΕΙ στη βάση: την
				// ξαναγράφει το backend στις ανανεώσεις (`RenewalsController`).
				//
				// Το «Διάρκεια Σύμβασης» κρύβεται μόνο για mobile (Orizon): το
				// `orizon_*.json` δεν έχει `diarkeia_symvasis` σε κανένα από τα
				// τέσσερα πρότυπά του — το «24 ΜΗΝΕΣ» στο χαρτί είναι στατικό
				// κείμενο. Παραμένει αναγκαίο για nrg_fa/nrg_he/volton_fa/
				// volton_he, οπότε δεν φεύγει το πεδίο, μόνο κρύβεται με
				// data-when-energy. Το «Ημ. Λήξης» ΔΕΝ κρύβεται — το
				// `orizon_mobile.json` το τυπώνει («imerominia_liksis»), οπότε
				// μένει ελεύθερα επεξεργάσιμο ώστε ο πράκτορας να το γράφει με
				// το χέρι όταν η αυτόματη πρόταση (Έναρξη+Διάρκεια) δεν τρέχει.
				?>
				<div class="ecrm-subhead">Διάρκεια Σύμβασης</div>
				<div class="ecrm-grid">
					<label class="ecrm-field" data-for="term_months" data-when-energy="power,gas">
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

			</div>

			<div class="ecrm-wstep" data-wstep="4" hidden>

			<!-- Ενέργειες σύμβασης -->
			<div class="ecrm-actbanners">
				<button type="button" class="ecrm-actbanner ecrm-actbanner--primary" data-act="pdf">
					<span class="ecrm-actbanner__ic"><svg class="ecrm-i ecrm-i--banner" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V3h10v6M7 18H5a2 2 0 01-2-2v-4a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2h-2M7 15h10v6H7z"/></svg></span>
					<span class="ecrm-actbanner__tx"><b>Εκτύπωση Σύμβασης</b><span>Δημιουργείται αυτόματα το PDF του συμβολαίου με τα στοιχεία της φόρμας</span></span>
				</button>
				<button type="button" class="ecrm-actbanner ecrm-actbanner--dark" data-act="email">
					<span class="ecrm-actbanner__ic"><svg class="ecrm-i ecrm-i--banner" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v12H3zM3 7l9 6 9-6"/></svg></span>
					<span class="ecrm-actbanner__tx"><b>Αποστολή στον πελάτη για ηλεκτρονική υπογραφή</b><span>Στέλνεται email στον πελάτη με σύνδεσμο υπογραφής</span></span>
				</button>
				<button type="button" class="ecrm-actbanner ecrm-actbanner--outline" data-act="livelink">
					<span class="ecrm-actbanner__ic"><svg class="ecrm-i ecrm-i--banner" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 007 0l3-3a5 5 0 00-7-7l-1.5 1.5M14 11a5 5 0 00-7 0l-3 3a5 5 0 007 7l1.5-1.5"/></svg></span>
					<span class="ecrm-actbanner__tx"><b>Δημιουργία συνδέσμου για live υπογραφή</b><span>Σύνδεσμος για υπογραφή επί τόπου ή αποστολή με μήνυμα</span></span>
				</button>
			</div>

			<section class="ecrm-card">
				<div class="ecrm-step"><span class="ecrm-step__n">8</span> Σχόλια Αίτησης</div>
				<textarea class="ecrm-textarea" data-notes name="notes" rows="3" placeholder="Επιπλέον σημειώσεις…"></textarea>
			</section>

			</div>

			<footer class="ecrm-foot">
				<?php
				// Ticked by default: the agent is with the customer when they
				// fill this in and consent is part of that conversation, so the
				// box was being ticked on every single application anyway.
				//
				// What it therefore stops being is evidence. It records the
				// agent's assertion and the agent's IP, not the customer's, and
				// a field that is always true demonstrates nothing. The consent
				// that counts is the one the customer ticks themselves on the
				// signing page (ECRM_Tracking) — their own tick, their own IP,
				// their own timestamp. That one stays untouched and unticked.
				?>
				<label class="ecrm-consent"><input type="checkbox" data-consent name="consent" value="1" checked> Ο πελάτης ενημερώθηκε και συναινεί στην επεξεργασία των προσωπικών του δεδομένων για τη σύναψη/διαχείριση της σύμβασης (GDPR), συμπεριλαμβανομένης της αυτόματης ανάγνωσης των εγγράφων που υποβάλλει (ταυτότητα, λογαριασμός) από εξωτερική υπηρεσία τεχνητής νοημοσύνης.</label>
				<div class="ecrm-foot__mode">Λειτουργία: <strong>Νέα αίτηση</strong></div>
				<div class="ecrm-foot__actions">
					<?php
					/*
					 * Three buttons, of which the agent ever sees two — which
					 * pair depends on the stage, and ecrm-form.js setStage()
					 * decides. Nothing transitions TO draft in ContractStatus,
					 * and only draft transitions to new, so the first two are
					 * legal in exactly one situation: an application that is
					 * unsaved or still a draft. Past that, editing a field is
					 * still allowed — it is not a transition at all — and that
					 * is what the third one does, by sending no status.
					 */
					?>
					<button type="button" class="ecrm-btn ecrm-btn--ghost" data-wprev hidden>&larr; Πίσω</button>
					<button type="button" class="ecrm-btn ecrm-btn--ghost" data-save-draft>Προσωρινή Αποθήκευση</button>
					<button type="button" class="ecrm-btn ecrm-btn--primary" data-wnext>Συνέχεια &rarr;</button>
					<button type="button" class="ecrm-btn ecrm-btn--primary" data-finalize hidden>Οριστικοποίηση</button>
					<button type="button" class="ecrm-btn ecrm-btn--primary" data-save-changes hidden>Αποθήκευση Αλλαγών</button>
				</div>
			</footer>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
