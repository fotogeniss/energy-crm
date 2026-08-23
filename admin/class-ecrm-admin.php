<?php
/**
 * Admin: settings page for the Claude API key + model, and a menu entry.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Admin {

	/**
	 * Το χρώμα τονισμού όταν ο διαχειριστής δεν έχει ορίσει δικό του.
	 *
	 * Ήταν γραμμένο `#f59e0b` σε ΤΕΣΣΕΡΑ σημεία — στις δύο σελίδες πελάτη, στο
	 * placeholder αυτής της οθόνης και στην περιγραφή της. Το amber έπαψε να
	 * είναι το χρώμα της μάρκας στις 2026-08-17 και κανένα από τα τέσσερα δεν
	 * το έμαθε.
	 *
	 * Είναι το ΣΚΟΥΡΟ πράσινο, όχι το `#16c217` της εφαρμογής, και ο λόγος
	 * μετρήθηκε: λευκό πάνω στο #16c217 δίνει 2,40:1 — κάτω από το 4,5 που
	 * θέλει κανονικό κείμενο. Το κουμπί «Υπογράφω» το πατά πελάτης, συχνά σε
	 * κινητό και στον ήλιο. Το #0e8610 δίνει 4,61:1.
	 */
	const DEFAULT_ACCENT = '#0e8610';

	public static function init(): void {
		// Priority 9: the parent menu must exist in $menu before any submenu is
		// attached to it. WordPress only creates the implicit "back to parent"
		// link if it can find the parent at that moment, and a submenu that
		// registers first leaves the settings page with no entry at all.
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 9 );
		add_action( 'admin_init', [ __CLASS__, 'settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'media_enqueue' ] );
	}

	public static function menu(): void {
		add_menu_page(
			'Energy CRM',
			'Energy CRM',
			'manage_options',
			'energy-crm',
			[ __CLASS__, 'render_settings' ],
			'dashicons-buddicons-buddypress-logo',
			56
		);

		// Declared explicitly rather than relying on the implicit first entry
		// WordPress adds: that one is conditional on registration order, and it
		// would be labelled "Energy CRM → Energy CRM", which tells nobody that
		// the API key lives behind it.
		add_submenu_page(
			'energy-crm',
			'Energy CRM — Ρυθμίσεις',
			'Ρυθμίσεις',
			'manage_options',
			'energy-crm',
			[ __CLASS__, 'render_settings' ]
		);
	}

	/**
	 * Store the API key encrypted, and treat an empty field as "leave it alone".
	 *
	 * The field is never pre-filled, so an empty submission means the admin did
	 * not intend to change the key — not that they wanted to erase it.
	 */
	public static function sanitize_api_key( $value ) {
		$secrets = \EnergyCRM\Services::secrets();
		$value   = sanitize_text_field( (string) $value );

		if ( $value === '' ) {
			return get_option( ECRM_PREFIX . 'claude_api_key', '' );
		}

		$secrets->put( 'claude_api_key', $value );

		// put() has already written the encrypted value; returning it keeps the
		// Settings API from overwriting it with the raw input.
		return get_option( ECRM_PREFIX . 'claude_api_key', '' );
	}

	public static function settings(): void {
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'claude_api_key', [
			'sanitize_callback' => [ __CLASS__, 'sanitize_api_key' ],
		] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'claude_model', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'company_name', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'company_info', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'company_logo', [ 'sanitize_callback' => 'esc_url_raw' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'accent_color', [ 'sanitize_callback' => 'sanitize_hex_color' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'pdf_footer', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'followup_days', [ 'sanitize_callback' => 'absint' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'renewal_reminder_days', [ 'sanitize_callback' => 'absint' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'extraction_retention_days', [ 'sanitize_callback' => 'absint' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sla_escalation_days', [ 'sanitize_callback' => 'absint' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'notify_email', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'notify_digest', [ 'sanitize_callback' => 'sanitize_text_field' ] );

		// --- Customer messaging (SMS / Viber) ---
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sms_enabled', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sms_provider', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sms_sender', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sms_apifon_token', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sms_apifon_secret', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sms_apifon_channel', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sms_generic_url', [ 'sanitize_callback' => 'esc_url_raw' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sms_generic_method', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sms_generic_body', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sms_generic_headers', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sms_tpl', [ 'sanitize_callback' => [ __CLASS__, 'sanitize_kv' ] ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'sms_on', [ 'sanitize_callback' => [ __CLASS__, 'sanitize_list' ] ] );
		register_setting( 'ecrm_settings', ECRM_PREFIX . 'required_docs_map', [ 'sanitize_callback' => [ __CLASS__, 'sanitize_docs_map' ] ] );
	}

	/** Sanitize the required-docs matrix: type => [slug,…]. */
	public static function sanitize_docs_map( $v ): array {
		if ( ! is_array( $v ) ) {
			return [];
		}
		$valid_kinds = class_exists( 'ECRM_Docs' ) ? array_keys( ECRM_Docs::kinds() ) : [];
		$out = [];
		foreach ( $v as $type => $slugs ) {
			$t = sanitize_key( $type );
			$clean = [];
			foreach ( (array) $slugs as $s ) {
				$s = sanitize_key( $s );
				if ( ! $valid_kinds || in_array( $s, $valid_kinds, true ) ) { $clean[] = $s; }
			}
			$out[ $t ] = array_values( array_unique( $clean ) );
		}
		return $out;
	}

	/** Sanitize an assoc array of status => template text. */
	public static function sanitize_kv( $v ): array {
		if ( ! is_array( $v ) ) {
			return [];
		}
		$out = [];
		foreach ( $v as $k => $val ) {
			$out[ sanitize_key( $k ) ] = sanitize_textarea_field( (string) $val );
		}
		return $out;
	}

	/** Sanitize a list of status slugs. */
	public static function sanitize_list( $v ): array {
		if ( ! is_array( $v ) ) {
			return [];
		}
		return array_values( array_map( 'sanitize_key', $v ) );
	}

	/** Option getter with default. */
	public static function get( string $key, $default = '' ) {
		$v = get_option( ECRM_PREFIX . $key, $default );
		return ( $v === '' || $v === false ) ? $default : $v;
	}

	public static function media_enqueue( string $hook ): void {
		if ( strpos( $hook, 'energy-crm' ) === false || strpos( $hook, 'providers' ) !== false ) {
			return;
		}
		wp_enqueue_media();
		wp_add_inline_script( 'jquery-core', "jQuery(function($){\n  $(document).on('click','.ecrm-pick-clogo',function(e){e.preventDefault();var f=wp.media({title:'Logo',multiple:false,library:{type:'image'}});f.on('select',function(){var a=f.state().get('selection').first().toJSON();$('#ecrm_clogo').val(a.url);$('#ecrm_clogo_prev').attr('src',a.url).show();});f.open();});\n});" );
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$secrets = \EnergyCRM\Services::secrets();
		$model   = ECRM_Extractor::model();
		$masked  = $secrets->mask( 'claude_api_key' );
		$pinned  = $secrets->isPinned( 'claude_api_key' );
		?>
		<div class="wrap">
			<h1>Energy CRM — Ρυθμίσεις</h1>
			<p>Η AI εξαγωγή στοιχείων από έγγραφα χρησιμοποιεί το Claude API.</p>
			<?php if ( ! $pinned ) : ?>
				<div class="notice notice-info inline"><p>
					<strong>Προτεινόμενο:</strong> όρισε το κλειδί στο <code>wp-config.php</code> ώστε να μη βρίσκεται καθόλου στη βάση:
					<br><code>define( 'ECRM_CLAUDE_API_KEY', 'sk-ant-...' );</code>
					<br>Όσο δεν είναι εκεί, αποθηκεύεται κρυπτογραφημένο — προστασία για αντίγραφα βάσης, όχι για πλήρη πρόσβαση στον server.
				</p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'ecrm_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ecrm_key">Claude API key</label></th>
						<td>
							<?php if ( $pinned ) : ?>
								<p class="description">Ορίζεται στο <code>wp-config.php</code> ή σε μεταβλητή περιβάλλοντος και δεν επεξεργάζεται από εδώ.</p>
								<p class="description">Τρέχον: <code><?php echo esc_html( $masked ); ?></code></p>
							<?php else : ?>
								<?php // The value is never sent to the browser: an empty field means "keep the current key". ?>
								<input type="password" id="ecrm_key" name="<?php echo esc_attr( ECRM_PREFIX . 'claude_api_key' ); ?>"
									value="" class="regular-text" autocomplete="off" placeholder="sk-ant-...">
								<?php if ( $masked ) : ?>
									<p class="description">Τρέχον: <code><?php echo esc_html( $masked ); ?></code> — άφησε το πεδίο κενό για να παραμείνει.</p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_model">Μοντέλο</label></th>
						<td>
							<input type="text" id="ecrm_model" name="<?php echo esc_attr( ECRM_PREFIX . 'claude_model' ); ?>"
								value="<?php echo esc_attr( $model ); ?>" class="regular-text" placeholder="<?php echo esc_attr( ECRM_Extractor::DEFAULT_MODEL ); ?>">
							<p class="description">Προεπιλογή: <code><?php echo esc_html( ECRM_Extractor::DEFAULT_MODEL ); ?></code></p>
						</td>
					</tr>
				</table>

				<h2>Εταιρεία &amp; Εμφάνιση</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ecrm_cname">Επωνυμία</label></th>
						<td><input type="text" id="ecrm_cname" name="<?php echo esc_attr( ECRM_PREFIX . 'company_name' ); ?>" value="<?php echo esc_attr( self::get( 'company_name' ) ); ?>" class="regular-text" placeholder="π.χ. Demo Energy Συνεργάτες Μ.ΕΠΕ"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_cinfo">Στοιχεία (στο PDF)</label></th>
						<td><textarea id="ecrm_cinfo" name="<?php echo esc_attr( ECRM_PREFIX . 'company_info' ); ?>" class="large-text" rows="2" placeholder="Διεύθυνση · ΑΦΜ · τηλέφωνο"><?php echo esc_textarea( self::get( 'company_info' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_clogo">Logo</label></th>
						<td>
							<input type="url" id="ecrm_clogo" name="<?php echo esc_attr( ECRM_PREFIX . 'company_logo' ); ?>" value="<?php echo esc_attr( self::get( 'company_logo' ) ); ?>" class="regular-text" placeholder="https://…">
							<button class="button ecrm-pick-clogo">Επιλογή</button>
							<br><img id="ecrm_clogo_prev" src="<?php echo esc_attr( self::get( 'company_logo' ) ); ?>" style="max-height:48px;margin-top:8px;<?php echo self::get( 'company_logo' ) ? '' : 'display:none'; ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_accent">Χρώμα τόνου</label></th>
						<td><input type="text" id="ecrm_accent" name="<?php echo esc_attr( ECRM_PREFIX . 'accent_color' ); ?>" value="<?php echo esc_attr( self::get( 'accent_color' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( self::DEFAULT_ACCENT ); ?>"><p class="description">Hex χρώμα για τονισμό στις σελίδες που βλέπει ο πελάτης. Κενό = προεπιλογή (πράσινο μάρκας).</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_pfooter">Υποσέλιδο PDF</label></th>
						<td><input type="text" id="ecrm_pfooter" name="<?php echo esc_attr( ECRM_PREFIX . 'pdf_footer' ); ?>" value="<?php echo esc_attr( self::get( 'pdf_footer' ) ); ?>" class="regular-text" placeholder="προαιρετικό κείμενο υποσέλιδου"></td>
					</tr>
				</table>
				<h2>Ειδοποιήσεις &amp; Follow-up</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ecrm_fdays">Όριο εκκρεμότητας (ημέρες)</label></th>
						<td><input type="number" min="1" id="ecrm_fdays" name="<?php echo esc_attr( ECRM_PREFIX . 'followup_days' ); ?>" value="<?php echo esc_attr( self::get( 'followup_days', 5 ) ); ?>" class="small-text"><p class="description">Μια ανοιχτή σύμβαση χωρίς ενημέρωση πάνω από τόσες ημέρες θεωρείται εκκρεμότητα.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_rdays">Υπενθύμιση λήξης (ημέρες πριν)</label></th>
						<td><input type="number" min="0" id="ecrm_rdays" name="<?php echo esc_attr( ECRM_PREFIX . 'renewal_reminder_days' ); ?>" value="<?php echo esc_attr( self::get( 'renewal_reminder_days', 30 ) ); ?>" class="small-text"><p class="description">Δημιουργείται αυτόματη εργασία επανάκλησης ανανέωσης τόσες ημέρες πριν τη λήξη σύμβασης. 0 = απενεργοποίηση.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_sla">Κλιμάκωση SLA (ημέρες)</label></th>
						<td><input type="number" min="0" id="ecrm_sla" name="<?php echo esc_attr( ECRM_PREFIX . 'sla_escalation_days' ); ?>" value="<?php echo esc_attr( self::get( 'sla_escalation_days', 10 ) ); ?>" class="small-text"><p class="description">Σύμβαση σε μη-τελική κατάσταση (Νέα/Περιμένει υπογραφή/Σε επεξεργασία/Εκκρεμεί) που μένει στάσιμη τόσες ημέρες κλιμακώνεται αυτόματα στον υπεύθυνο ομάδας (εργασία υψηλής προτεραιότητας + email). 0 = απενεργοποίηση.</p></td>
					</tr>
					<tr>
						<th scope="row">Email ειδοποιήσεων</th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( ECRM_PREFIX . 'notify_email' ); ?>" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr( ECRM_PREFIX . 'notify_email' ); ?>" value="1" <?php checked( (string) self::get( 'notify_email', '1' ), '1' ); ?>> Email στον συνεργάτη όταν σύμβαση μπει σε «Εκκρεμεί»</label><br>
							<input type="hidden" name="<?php echo esc_attr( ECRM_PREFIX . 'notify_digest' ); ?>" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr( ECRM_PREFIX . 'notify_digest' ); ?>" value="1" <?php checked( (string) self::get( 'notify_digest', '1' ), '1' ); ?>> Ημερήσιο email σύνοψης εκκρεμοτήτων</label>
						</td>
					</tr>
				</table>

				<h2>Μηνύματα πελάτη (SMS / Viber)</h2>
				<?php
				$sms_on   = (array) get_option( ECRM_PREFIX . 'sms_on', [ 'routed', 'active' ] );
				$tpls     = class_exists( 'ECRM_Messaging' ) ? ECRM_Messaging::templates() : [];
				$provider = (string) self::get( 'sms_provider', 'apifon' );
				$statuses = ECRM_DB::statuses();
				?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Ενεργοποίηση</th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( ECRM_PREFIX . 'sms_enabled' ); ?>" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr( ECRM_PREFIX . 'sms_enabled' ); ?>" value="1" <?php checked( (string) self::get( 'sms_enabled', '0' ), '1' ); ?>> Αποστολή μηνύματος στον πελάτη όταν αλλάζει η κατάσταση της αίτησης</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_sms_provider">Πάροχος</label></th>
						<td>
							<select id="ecrm_sms_provider" name="<?php echo esc_attr( ECRM_PREFIX . 'sms_provider' ); ?>">
								<option value="apifon" <?php selected( $provider, 'apifon' ); ?>>Apifon (native)</option>
								<option value="generic" <?php selected( $provider, 'generic' ); ?>>Άλλος πάροχος (generic HTTP)</option>
							</select>
							<p class="description">Διάλεξε «generic» για Yuboto / Routee / Mitto κ.λπ. και όρισε URL + headers + body παρακάτω.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_sms_sender">Sender ID</label></th>
						<td><input type="text" id="ecrm_sms_sender" name="<?php echo esc_attr( ECRM_PREFIX . 'sms_sender' ); ?>" value="<?php echo esc_attr( self::get( 'sms_sender' ) ); ?>" class="regular-text" placeholder="π.χ. EnergyCRM (έως 11 χαρακτήρες)"></td>
					</tr>
				</table>

				<h3 style="margin:.4em 0;">Apifon</h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ecrm_sms_at">API Token</label></th>
						<td><input type="text" id="ecrm_sms_at" name="<?php echo esc_attr( ECRM_PREFIX . 'sms_apifon_token' ); ?>" value="<?php echo esc_attr( self::get( 'sms_apifon_token' ) ); ?>" class="regular-text" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_sms_as">API Secret</label></th>
						<td><input type="password" id="ecrm_sms_as" name="<?php echo esc_attr( ECRM_PREFIX . 'sms_apifon_secret' ); ?>" value="<?php echo esc_attr( self::get( 'sms_apifon_secret' ) ); ?>" class="regular-text" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_sms_ch">Κανάλι</label></th>
						<td>
							<select id="ecrm_sms_ch" name="<?php echo esc_attr( ECRM_PREFIX . 'sms_apifon_channel' ); ?>">
								<option value="sms" <?php selected( (string) self::get( 'sms_apifon_channel', 'sms' ), 'sms' ); ?>>SMS</option>
								<option value="viber" <?php selected( (string) self::get( 'sms_apifon_channel', 'sms' ), 'viber' ); ?>>Viber (με SMS fallback)</option>
							</select>
						</td>
					</tr>
				</table>

				<h3 style="margin:.4em 0;">Generic HTTP (οποιοσδήποτε πάροχος)</h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ecrm_sms_gurl">Endpoint URL</label></th>
						<td><input type="text" id="ecrm_sms_gurl" name="<?php echo esc_attr( ECRM_PREFIX . 'sms_generic_url' ); ?>" value="<?php echo esc_attr( self::get( 'sms_generic_url' ) ); ?>" class="large-text" placeholder="https://services.example.gr/send?to={to}&amp;text={text}"><p class="description">Placeholders: <code>{to}</code> <code>{text}</code> <code>{sender}</code> (auto-encoded στο URL).</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_sms_gm">Method</label></th>
						<td>
							<select id="ecrm_sms_gm" name="<?php echo esc_attr( ECRM_PREFIX . 'sms_generic_method' ); ?>">
								<option value="POST" <?php selected( (string) self::get( 'sms_generic_method', 'POST' ), 'POST' ); ?>>POST</option>
								<option value="GET" <?php selected( (string) self::get( 'sms_generic_method', 'POST' ), 'GET' ); ?>>GET</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_sms_gh">Headers</label></th>
						<td><textarea id="ecrm_sms_gh" name="<?php echo esc_attr( ECRM_PREFIX . 'sms_generic_headers' ); ?>" class="large-text" rows="3" placeholder="Authorization: Bearer xxxxx&#10;Content-Type: application/json"><?php echo esc_textarea( self::get( 'sms_generic_headers' ) ); ?></textarea><p class="description">Μία ανά γραμμή, μορφή <code>Key: Value</code>.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ecrm_sms_gb">Body (POST)</label></th>
						<td><textarea id="ecrm_sms_gb" name="<?php echo esc_attr( ECRM_PREFIX . 'sms_generic_body' ); ?>" class="large-text" rows="3" placeholder='{&quot;to&quot;:&quot;{to}&quot;,&quot;message&quot;:&quot;{text}&quot;,&quot;sender&quot;:&quot;{sender}&quot;}'><?php echo esc_textarea( self::get( 'sms_generic_body' ) ); ?></textarea></td>
					</tr>
				</table>

				<h3 style="margin:.4em 0;">Πότε &amp; τι στέλνουμε</h3>
				<p class="description" style="margin-bottom:8px;">Placeholders στα κείμενα: <code>{name}</code> <code>{code}</code> <code>{company}</code> <code>{status}</code> <code>{provider}</code> <code>{track}</code> (σύνδεσμος παρακολούθησης)</p>
				<table class="form-table" role="presentation">
					<?php foreach ( $statuses as $slug => $label ) : ?>
					<tr>
						<th scope="row" style="vertical-align:top;padding-top:14px;">
							<label><input type="checkbox" name="<?php echo esc_attr( ECRM_PREFIX . 'sms_on' ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $sms_on, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						</th>
						<td><textarea name="<?php echo esc_attr( ECRM_PREFIX . 'sms_tpl' ); ?>[<?php echo esc_attr( $slug ); ?>]" class="large-text" rows="2" placeholder="(κενό = χωρίς αποστολή σε αυτή την κατάσταση)"><?php echo esc_textarea( $tpls[ $slug ] ?? '' ); ?></textarea></td>
					</tr>
					<?php endforeach; ?>
				</table>
				<h2>Απαιτούμενα δικαιολογητικά ανά τύπο ενεργοποίησης</h2>
				<?php
				$ats   = ECRM_DB::activation_types();
				$kinds = class_exists( 'ECRM_Docs' ) ? ECRM_Docs::kinds() : [];
				?>
				<p class="description" style="margin:4px 0 10px;">Η προαγωγή σε «Δρομολόγηση/Ενεργή» μπλοκάρεται αν λείπει κάποιο τσεκαρισμένο δικαιολογητικό. Καμία επιλογή σε μια γραμμή = κανένα υποχρεωτικό.</p>
				<table class="widefat striped" style="max-width:100%;overflow:auto;display:block;">
					<thead><tr><th style="min-width:150px;">Τύπος</th>
					<?php foreach ( $kinds as $kslug => $klabel ) : ?>
						<th style="font-weight:600;font-size:11px;"><?php echo esc_html( $klabel ); ?></th>
					<?php endforeach; ?>
					</tr></thead>
					<tbody>
					<?php foreach ( $ats as $atslug => $atlabel ) :
						$req = class_exists( 'ECRM_Docs' ) ? ECRM_Docs::required_for( $atslug ) : []; ?>
						<tr>
							<th scope="row"><?php echo esc_html( $atlabel ); ?></th>
							<input type="hidden" name="<?php echo esc_attr( ECRM_PREFIX . 'required_docs_map' ); ?>[<?php echo esc_attr( $atslug ); ?>][]" value="">
							<?php foreach ( $kinds as $kslug => $klabel ) : ?>
								<td style="text-align:center;">
									<input type="checkbox" name="<?php echo esc_attr( ECRM_PREFIX . 'required_docs_map' ); ?>[<?php echo esc_attr( $atslug ); ?>][]" value="<?php echo esc_attr( $kslug ); ?>" <?php checked( in_array( $kslug, $req, true ) ); ?>>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( 'Αποθήκευση' ); ?>
			</form>

			<h3 style="margin:.4em 0;">Δοκιμαστική αποστολή</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ecrm_sms_testto">Αριθμός</label></th>
					<td>
						<input type="text" id="ecrm_sms_testto" class="regular-text" placeholder="69XXXXXXXX">
						<button type="button" class="button" id="ecrm_sms_testbtn">Αποστολή δοκιμής</button>
						<span id="ecrm_sms_testres" style="margin-left:10px;font-weight:600;"></span>
						<p class="description">Αποθήκευσε πρώτα τις ρυθμίσεις, μετά δοκίμασε.</p>
					</td>
				</tr>
			</table>
			<script>
			(function(){
				var btn = document.getElementById('ecrm_sms_testbtn');
				if(!btn) return;
				btn.addEventListener('click', function(){
					var to = document.getElementById('ecrm_sms_testto').value;
					var res = document.getElementById('ecrm_sms_testres');
					res.textContent = 'Αποστολή…'; res.style.color = '#666';
					var fd = new FormData();
					fd.append('action','ecrm_sms_test');
					fd.append('nonce','<?php echo esc_js( wp_create_nonce( 'ecrm_sms_test' ) ); ?>');
					fd.append('to', to);
					fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:fd})
						.then(function(r){return r.json();})
						.then(function(d){
							if(d && d.success){ res.textContent = '✓ ' + (d.data.message||'OK'); res.style.color='#1a7f37'; }
							else { res.textContent = '✗ ' + ((d.data&&d.data.error)||'Σφάλμα'); res.style.color='#b32d2e'; }
						})
						.catch(function(){ res.textContent='✗ Σφάλμα δικτύου'; res.style.color='#b32d2e'; });
				});
			})();
			</script>

			<hr>
			<h2>Χρήση</h2>
			<p>Πρόσθεσε τη φόρμα Νέας Σύμβασης σε οποιαδήποτε σελίδα με το shortcode:</p>
			<p><code>[energy_crm_app]</code> — όλο το CRM (dashboard, συμβάσεις, νέα σύμβαση).</p>
			<p><code>[energy_crm_new_contract]</code> — μόνο η φόρμα νέας σύμβασης.</p>
		</div>
		<?php
	}
}
