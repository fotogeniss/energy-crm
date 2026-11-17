<?php
/**
 * PWA shell: manifest.json + service worker, σερβιρισμένα από τη ρίζα του site.
 *
 * ## Γιατί από τη ρίζα και όχι σαν στατικά αρχεία στο plugin
 *
 * Το scope ενός service worker είναι εξ ορισμού ο φάκελος του δικού του URL.
 * Αν σερβίρονταν από `.../wp-content/plugins/energy-crm/public/assets/`, θα
 * κάλυπτε ΜΟΝΟ εκείνον τον φάκελο -- ποτέ την πραγματική σελίδα όπου ζει το
 * `[energy_crm_app]` shortcode, που ο ιδιοκτήτης του site διαλέγει ελεύθερα
 * και το plugin δεν την ξέρει εκ των προτέρων. Η ρίζα ("/") είναι το μόνο
 * scope που την καλύπτει σίγουρα, όποια κι αν είναι.
 *
 * Ίδιο μοτίβο με το ECRM_Tracking και το ECRM_Intake: `template_redirect` +
 * query arg πάνω στο `home_url('/')`, όχι rewrite rule. Δουλεύει ανεξάρτητα
 * από τη δομή permalinks του site και δεν χρειάζεται flush_rewrite_rules()
 * στο activation.
 *
 * ## Τι ΔΕΝ κάνει αυτή η κλάση
 *
 * Δεν σερβίρει ΚΑΝΕΝΑ δεδομένο πελάτη, δεν αγγίζει REST, δεν κάνει cache
 * τίποτα η ίδια -- απλώς παραδίδει δύο στατικά αρχεία (manifest, service
 * worker) στη σωστή διαδρομή ώστε ο browser να τα δεχτεί. Η πραγματική
 * λογική caching ζει στο `public/assets/ecrm-sw.js`.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Pwa {

	const SW_QUERY       = 'ecrm_sw';
	const MANIFEST_QUERY = 'ecrm_manifest';

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_serve' ], 0 );
		add_filter( 'get_site_icon_url', [ __CLASS__, 'site_icon' ], 10, 2 );
	}

	/**
	 * (410) Το σύμβολο του CRM (κεραυνός, docs/UI-LOGO.html) ως εικονίδιο του
	 * site: καρτέλα browser, σελίδα σύνδεσης, wp-admin, «Προσθήκη στην αρχική
	 * οθόνη» του iPhone. Το WordPress τυπώνει μόνο του τα <link> σε wp_head,
	 * login_head και admin_head όταν υπάρχει εικονίδιο (`wp_site_icon()`), και
	 * ρωτά ακριβώς αυτό το φίλτρο. Αν ο διαχειριστής έχει βάλει δικό του
	 * εικονίδιο (Εμφάνιση › Προσαρμογή), μένει εκείνο.
	 *
	 * Ως 32 px: το SVG με γεμάτο κεραυνό (η λεπτή γραμμή δεν διαβάζεται τόσο
	 * μικρή). Από 180 px και πάνω: τα PNG του manifest.
	 *
	 * @param mixed $url  Ό,τι έδωσε το WordPress (κενό = κανένα εικονίδιο).
	 * @param mixed $size Το μέγεθος σε px.
	 */
	public static function site_icon( $url, $size ): string {
		if ( is_string( $url ) && '' !== $url ) {
			return $url;
		}

		$size = (int) $size;
		$file = 'ecrm-mark.svg';

		if ( $size > 192 ) {
			$file = 'icon-512.png';
		} elseif ( $size > 180 ) {
			$file = 'icon-192.png';
		} elseif ( $size > 64 ) {
			$file = 'apple-touch-icon-180.png';
		}

		return self::icon_url( $file );
	}

	/**
	 * (411) Το URL ενός εικονιδίου με `?v=` την ώρα του αρχείου, όπως κάθε
	 * άλλο asset (`ECRM_Shortcodes::asset_version()`). Το Android κρατά το
	 * εικονίδιο της εγκατεστημένης εφαρμογής και το ξαναζητά όταν δει νέο
	 * URL στο manifest· χωρίς αυτό, μετά το 410 έμενε το παλιό «E».
	 */
	public static function icon_url( string $file ): string {
		return ECRM_URL . 'public/assets/icons/' . $file . '?v=' . ECRM_Shortcodes::asset_version( 'icons/' . $file );
	}

	public static function maybe_serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- δημόσια στατικά αρχεία, όχι φόρμα.
		if ( isset( $_GET[ self::SW_QUERY ] ) ) {
			self::serve_sw();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- δημόσια στατικά αρχεία, όχι φόρμα.
		if ( isset( $_GET[ self::MANIFEST_QUERY ] ) ) {
			self::serve_manifest();
		}
	}

	public static function sw_url(): string {
		return add_query_arg( self::SW_QUERY, '1', home_url( '/' ) );
	}

	public static function manifest_url(): string {
		return add_query_arg( self::MANIFEST_QUERY, '1', home_url( '/' ) );
	}

	private static function serve_sw(): void {
		$path = ECRM_DIR . 'public/assets/ecrm-sw.js';

		header( 'Content-Type: application/javascript; charset=utf-8' );
		// Επεξηγηματικό, όχι απαραίτητο εδώ: το URL είναι ήδη η ρίζα ("/"),
		// άρα το scope είναι ήδη το ευρύτερο δυνατό χωρίς αυτό το header.
		// Μένει ρητό ώστε μια μελλοντική αλλαγή διαδρομής να μη σπάσει σιωπηλά.
		header( 'Service-Worker-Allowed: /' );
		// Ένα service worker script πρέπει να ξαναελέγχεται συχνά -- ο ίδιος
		// ο browser το ξαναφέρνει το πολύ κάθε 24 ώρες ούτως ή άλλως, αλλά
		// no-cache αποτρέπει έναν ενδιάμεσο proxy/CDN να το κρατήσει παλιό.
		header( 'Cache-Control: no-cache' );

		echo file_exists( $path ) ? file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.EscapeOutput.OutputNotEscaped -- δικό μας στατικό JS αρχείο, όχι είσοδος χρήστη.
		exit;
	}

	private static function serve_manifest(): void {
		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : '';
		$name    = $company !== '' ? $company . ' — Energy CRM' : 'Energy CRM';

		$manifest = [
			'name'             => $name,
			'short_name'       => 'Energy CRM',
			'start_url'        => self::start_url(),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			// (410) Το φόντο του εικονιδίου: η οθόνη εκκίνησης ταιριάζει με το σύμβολο.
			'background_color' => '#0d0e0c',
			// (411) Η μπάρα του κινητού στο χρώμα του συμβόλου. Ήταν το λάιμ του
			// accent (#c2f04a), που δεν ταιριάζει με το σκούρο εικονίδιο.
			'theme_color'      => '#0d0e0c',
			'lang'             => 'el',
			// (410) Ο κεραυνός σε μαύρο πλακίδιο. Το maskable έχει το σύμβολο στο 78%,
			// μέσα στην ασφαλή ζώνη που κόβει το Android.
			'icons'            => [
				[
					'src'   => esc_url_raw( self::icon_url( 'icon-192.png' ) ),
					'sizes' => '192x192',
					'type'  => 'image/png',
					'purpose' => 'any',
				],
				[
					'src'   => esc_url_raw( self::icon_url( 'icon-512.png' ) ),
					'sizes' => '512x512',
					'type'  => 'image/png',
					'purpose' => 'any',
				],
				[
					'src'   => esc_url_raw( self::icon_url( 'icon-maskable-512.png' ) ),
					'sizes' => '512x512',
					'type'  => 'image/png',
					'purpose' => 'maskable',
				],
			],
		];

		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: no-cache' );

		echo wp_json_encode( $manifest ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, όχι HTML.
		exit;
	}

	/**
	 * Το URL της σελίδας όπου ζει πραγματικά το shortcode, ΤΩΡΑ -- όχι η
	 * ρίζα του site. Ο χρήστης που ανοίγει το εικονίδιο θέλει να μπει
	 * κατευθείαν στο CRM, όχι στην αρχική του site.
	 *
	 * `is_singular()` πρώτα: αυτό είναι η κοινή περίπτωση (το shortcode σε
	 * μια κανονική σελίδα WP). Αν δεν είναι singular -- π.χ. μπήκε μέσα σε
	 * widget ή σε custom template χωρίς queried object -- πέφτει στο
	 * τρέχον URL μέσω `$wp->request`, και τελικά στη ρίζα αν τίποτα άλλο
	 * δεν στέκει. Ποτέ κενό: ένα άδειο start_url θα έκανε το manifest να
	 * απορριφθεί σιωπηλά από κάποιους browsers.
	 */
	private static function start_url(): string {
		if ( is_singular() ) {
			$url = get_permalink();
			if ( $url ) {
				return $url;
			}
		}

		global $wp;
		if ( isset( $wp ) && is_object( $wp ) && isset( $wp->request ) ) {
			return home_url( add_query_arg( [], $wp->request ) );
		}

		return home_url( '/' );
	}
}
