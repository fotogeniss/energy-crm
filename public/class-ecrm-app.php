<?php
/**
 * Front-end app shell: [energy_crm_app]
 * Renders the full CRM layout — navy sidebar nav, topbar, and the view
 * containers. Client-side routing + data fetching live in ecrm-app.js.
 *
 * Views: dashboard, new-contract (embeds the shared form partial),
 * contracts (list), network, team. The last two are scaffolded with empty
 * states, ready to wire in upcoming modules.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_App {

	public static function init(): void {
		add_shortcode( 'energy_crm_app', [ __CLASS__, 'render' ] );
	}

	public static function render(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="ecrm-gate">Συνδέσου για να μπεις στο CRM.</div>';
		}

		// Form assets (also defines window.ECRMForm) + shell assets.
		ECRM_Shortcodes::enqueue_form_assets();
		wp_enqueue_style( 'ecrm-app', ECRM_URL . 'public/assets/ecrm-app.css', [ 'ecrm-form' ], ECRM_VERSION );
		wp_enqueue_script( 'ecrm-app', ECRM_URL . 'public/assets/ecrm-app.js', [ 'ecrm-form' ], ECRM_VERSION, true );
		wp_enqueue_script( 'ecrm-litsa', ECRM_URL . 'public/assets/ecrm-litsa.js', [ 'ecrm-form' ], ECRM_VERSION, true );

		global $wpdb;
		$user   = wp_get_current_user();
		$accent = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'accent_color' ) : '';
		$style  = $accent ? ' style="--amber:' . esc_attr( $accent ) . ';--amber-bright:' . esc_attr( $accent ) . ';"' : '';

		// Topbar org + avatar initials.
		$company = class_exists( 'ECRM_Admin' ) ? (string) ECRM_Admin::get( 'company_name' ) : '';
		$role_label = 'ΣΥΝΕΡΓΑΤΗΣ';
		if ( in_array( 'ecrm_seller', (array) $user->roles, true ) ) { $role_label = 'ΠΩΛΗΤΗΣ'; }
		elseif ( in_array( 'ecrm_registrar', (array) $user->roles, true ) ) { $role_label = 'ΚΑΤΑΧΩΡΗΤΗΣ'; }
		elseif ( user_can( $user, 'manage_options' ) ) { $role_label = 'ADMIN'; }

		$parts = preg_split( '/\s+/', trim( (string) $user->display_name ) );
		$initials = '';
		foreach ( array_slice( $parts, 0, 2 ) as $w ) { $initials .= mb_substr( $w, 0, 1, 'UTF-8' ); }
		$initials = mb_strtoupper( $initials ?: mb_substr( $user->display_name, 0, 1, 'UTF-8' ), 'UTF-8' );

		// Pending count for the Εκκρεμότητες badge (own scope).
		$pending_ct = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . ECRM_DB::table( 'contracts' ) . " WHERE partner_user_id = %d AND status = 'pending'",
			$user->ID
		) );
		$tasks_ct = class_exists( 'ECRM_Tasks' ) ? ECRM_Tasks::due_count( (int) $user->ID ) : 0;

		// Leads with a callback due today or overdue, still open (own scope).
		$leads_due = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . ECRM_DB::table( 'leads' ) . "
			 WHERE partner_user_id = %d AND stage NOT IN ('won','lost')
			   AND callback_at IS NOT NULL AND callback_at <= %s",
			$user->ID, current_time( 'mysql' )
		) );

		$nav = [
			[ 'view' => 'dashboard',    'label' => 'Dashboard',      'icon' => 'dashboard' ],
			[ 'view' => 'new-contract', 'label' => 'Νέα Σύμβαση',    'icon' => 'new' ],
			[ 'view' => 'contracts',    'label' => 'Συμβάσεις',      'icon' => 'contracts' ],
			[ 'view' => 'pending', 'label' => 'Εκκρεμότητες', 'icon' => 'pending', 'badge' => $pending_ct ],
			[ 'view' => 'tasks', 'label' => 'Εργασίες', 'icon' => 'tasks', 'badge' => $tasks_ct ],
			[ 'view' => 'customers',    'label' => 'Πελάτες',        'icon' => 'customers' ],
			[ 'view' => 'renewals',     'label' => 'Λήξεις',         'icon' => 'renew' ],
			[ 'view' => 'network',      'label' => 'Το δίκτυό μου',  'icon' => 'network' ],
			[ 'view' => 'team',         'label' => 'Η ομάδα μου',    'icon' => 'team' ],
			[ 'view' => 'calc',         'label' => 'Προσφορά',       'icon' => 'calc' ],
			[ 'view' => 'kb',           'label' => 'Βάση Γνώσης',     'icon' => 'book' ],
		];

		// The menu mirrors the capability matrix rather than guessing from the
		// role, so a change in EnergyCRM\Access\Roles is reflected here for free.
		if ( current_user_can( \EnergyCRM\Access\Capability::MANAGE_LEADS ) ) {
			array_splice( $nav, 2, 0, [
				[ 'view' => 'leads', 'label' => 'Leads', 'icon' => 'funnel', 'badge' => $leads_due ],
			] );
		}
		if ( current_user_can( \EnergyCRM\Access\Capability::VIEW_COMMISSIONS ) ) {
			$nav[] = [ 'view' => 'commissions', 'label' => 'Προμήθειες', 'icon' => 'euro' ];
		}
		if ( current_user_can( \EnergyCRM\Access\Capability::MANAGE_TEAM ) ) {
			$nav[] = [ 'view' => 'teamlive', 'label' => 'Ομάδα Live', 'icon' => 'pulse' ];
		}
		if ( current_user_can( \EnergyCRM\Access\Capability::VIEW_ANALYTICS ) ) {
			$nav[] = [ 'view' => 'analytics', 'label' => 'Στατιστικά', 'icon' => 'analytics' ];
		}
		if ( current_user_can( \EnergyCRM\Access\Capability::IMPORT_DATA ) ) {
			$nav[] = [ 'view' => 'import', 'label' => 'Εισαγωγή Excel', 'icon' => 'import' ];
		}

		ob_start();
		?>
		<div class="ecrm ecrm-app" id="ecrm-app" lang="el" data-view="dashboard" data-collapsed="0"<?php echo $style; ?>>

			<div class="ecrm-backdrop" data-mobnav-close></div>

			<aside class="ecrm-sidebar">
				<div class="ecrm-brand">
					<span class="ecrm-brand__mark">E</span>
					<span class="ecrm-brand__txt">Energy <strong>CRM</strong></span>
					<button type="button" class="ecrm-collapse" data-collapse aria-label="Σύμπτυξη μενού"><?php echo self::icon( 'menu' ); // phpcs:ignore ?></button>
				</div>
				<nav class="ecrm-nav">
					<div class="ecrm-nav__label">Navigation</div>
					<?php foreach ( $nav as $item ) :
						$go = $item['go'] ?? $item['view'];
						$badge = $item['badge'] ?? 0;
						?>
						<button type="button" class="ecrm-nav__item<?php echo $go === 'dashboard' ? ' is-active' : ''; ?>" data-go="<?php echo esc_attr( $go ); ?>" title="<?php echo esc_attr( $item['label'] ); ?>">
							<span class="ecrm-nav__icon"><?php echo self::icon( $item['icon'] ); // phpcs:ignore ?><?php if ( $badge > 0 ) : ?><span class="ecrm-nav__badge"><?php echo (int) $badge; ?></span><?php endif; ?></span>
							<span class="ecrm-nav__txt"><?php echo esc_html( $item['label'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</nav>
				<div class="ecrm-sidebar__foot">
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a class="ecrm-nav__item" href="<?php echo esc_url( admin_url( 'admin.php?page=energy-crm' ) ); ?>" title="Ρυθμίσεις (WP Admin)">
						<span class="ecrm-nav__icon"><?php echo self::icon( 'gear' ); // phpcs:ignore ?></span>
						<span class="ecrm-nav__txt">Ρυθμίσεις</span>
					</a>
					<?php endif; ?>
					<a class="ecrm-nav__item" href="<?php echo esc_url( wp_logout_url() ); ?>" title="Έξοδος">
						<span class="ecrm-nav__icon"><?php echo self::icon( 'logout' ); // phpcs:ignore ?></span>
						<span class="ecrm-nav__txt">Έξοδος</span>
					</a>
				</div>
			</aside>

			<main class="ecrm-main">
				<header class="ecrm-topbar">
					<button type="button" class="ecrm-burger" data-mobnav-toggle aria-label="Μενού"><?php echo self::icon( 'menu' ); // phpcs:ignore ?></button>
					<span class="ecrm-topbar__brand">Energy <strong>CRM</strong></span>
					<div class="ecrm-topbar__hello">Καλώς ήρθες, <strong><?php echo esc_html( $user->display_name ); ?></strong></div>
					<div class="ecrm-gsearch">
						<input type="search" data-gsearch placeholder="Αναζήτηση σύμβασης ή πελάτη — ΑΦΜ, όνομα, ΗΚΑΣΠ, κωδικός…" autocomplete="off">
						<div class="ecrm-gsearch__results" data-gsearch-results hidden></div>
					</div>
					<div class="ecrm-topbar__right">
						<span class="ecrm-live"><span class="ecrm-live__dot"></span> Live</span>
						<div class="ecrm-bellwrap">
							<button type="button" class="ecrm-bell" data-bell aria-label="Ειδοποιήσεις">
								<?php echo self::icon( 'bell' ); // phpcs:ignore ?>
								<span class="ecrm-bell__badge" data-bell-badge hidden>0</span>
							</button>
							<div class="ecrm-bellpanel" data-bellpanel hidden></div>
						</div>
						<?php if ( $company ) : ?>
						<div class="ecrm-org"><strong><?php echo esc_html( $company ); ?></strong><span><?php echo esc_html( $role_label ); ?></span></div>
						<?php endif; ?>
						<span class="ecrm-avatar" title="<?php echo esc_attr( $user->display_name ); ?>"><?php echo esc_html( $initials ); ?></span>
					</div>
				</header>

				<div class="ecrm-content">
					<!-- Dashboard -->
					<section class="ecrm-view is-active" data-view="dashboard">
						<div class="ecrm-loading">Φόρτωση…</div>
					</section>

					<!-- New contract (shared form partial) -->
					<section class="ecrm-view" data-view="new-contract">
						<?php echo ECRM_Shortcodes::form_inner_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</section>

					<!-- Contracts list -->
					<section class="ecrm-view" data-view="contracts">
						<div class="ecrm-loading">Φόρτωση…</div>
					</section>

					<!-- Contract detail -->
					<section class="ecrm-view" data-view="contract-detail">
						<div class="ecrm-loading">Φόρτωση…</div>
					</section>

					<!-- Network -->
					<section class="ecrm-view" data-view="network"><div class="ecrm-loading">Φόρτωση…</div></section>

					<!-- Team -->
					<section class="ecrm-view" data-view="team"><div class="ecrm-loading">Φόρτωση…</div></section>

					<!-- Import provider Excel -->
					<section class="ecrm-view" data-view="import"></section>

					<!-- Εκκρεμότητες -->
					<section class="ecrm-view" data-view="pending"><div class="ecrm-loading">Φόρτωση…</div></section>

					<!-- Εργασίες -->
					<section class="ecrm-view" data-view="tasks"><div class="ecrm-loading">Φόρτωση…</div></section>

					<!-- Customers -->
					<section class="ecrm-view" data-view="customers"><div class="ecrm-loading">Φόρτωση…</div></section>

					<!-- Λήξεις -->
					<section class="ecrm-view" data-view="renewals"><div class="ecrm-loading">Φόρτωση…</div></section>

					<!-- Commissions -->
					<section class="ecrm-view" data-view="commissions"><div class="ecrm-loading">Φόρτωση…</div></section>

					<!-- Analytics (managers) -->
					<section class="ecrm-view" data-view="analytics"><div class="ecrm-loading">Φόρτωση…</div></section>
					<section class="ecrm-view" data-view="teamlive"><div class="ecrm-loading">Φόρτωση…</div></section>

					<!-- Προσφορά / Calculator -->
					<section class="ecrm-view" data-view="calc"><div class="ecrm-loading">Φόρτωση…</div></section>

					<!-- Βάση Γνώσης -->
					<section class="ecrm-view" data-view="kb"><div class="ecrm-loading">Φόρτωση…</div></section>

					<!-- Leads / χωνί -->
					<section class="ecrm-view" data-view="leads"><div class="ecrm-loading">Φόρτωση…</div></section>
				</div>
			</main>

			<!-- Λίτσα assistant -->
			<div class="ecrm-litsa" id="ecrm-litsa" data-open="0">
				<button type="button" class="ecrm-litsa__fab" data-litsa-toggle aria-label="Άνοιγμα βοηθού Λίτσα">
					<span class="ecrm-litsa__fab-ico"><?php echo self::icon( 'chat' ); // phpcs:ignore ?></span>
				</button>
				<div class="ecrm-litsa__panel" role="dialog" aria-label="Βοηθός Λίτσα">
					<div class="ecrm-litsa__head">
						<div class="ecrm-litsa__id"><span class="ecrm-litsa__avatar">Λ</span><div><strong>Λίτσα</strong><span class="ecrm-litsa__sub">βοηθός CRM</span></div></div>
						<button type="button" class="ecrm-litsa__x" data-litsa-toggle aria-label="Κλείσιμο">✕</button>
					</div>
					<div class="ecrm-litsa__body" data-litsa-body></div>
					<div class="ecrm-litsa__foot">
						<input type="text" class="ecrm-litsa__input" data-litsa-input placeholder="Ρώτησέ με κάτι…" autocomplete="off">
						<button type="button" class="ecrm-litsa__send" data-litsa-send aria-label="Αποστολή">➤</button>
					</div>
				</div>
			</div>

			<div class="ecrm-toast" id="ecrm-toast" aria-live="assertive"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Inline SVG icon set (stroke, currentColor). */
	public static function icon( string $name ): string {
		$p = [
			'dashboard' => '<path d="M3 13a9 9 0 0 1 18 0"/><path d="M12 13l4-3"/><circle cx="12" cy="13" r="1.4"/>',
			'new'       => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M12 12v5"/><path d="M9.5 14.5h5"/>',
			'contracts' => '<path d="M8 4H6a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/><rect x="8" y="2.5" width="8" height="4" rx="1.2"/><path d="M8.5 11h7"/><path d="M8.5 15h5"/>',
			'network'   => '<circle cx="6" cy="12" r="2.4"/><circle cx="18" cy="6" r="2.4"/><circle cx="18" cy="18" r="2.4"/><path d="M8.2 10.8 15.8 7.2"/><path d="M8.2 13.2 15.8 16.8"/>',
			'pending'   => '<path d="M12 8v5"/><circle cx="12" cy="16.5" r=".4"/><path d="M10.3 3.8 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 3.8a2 2 0 0 0-3.4 0z"/>',
			'renew'     => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/>',
			'customers' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8" cy="11" r="2"/><path d="M5 16.5a3.5 3.5 0 0 1 6 0"/><path d="M14 9h4"/><path d="M14 13h4"/>',
			'team'      => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 6.2a3 3 0 0 1 0 5.6"/><path d="M18.5 20a6 6 0 0 0-3-5.1"/>',
			'import'    => '<path d="M12 14V3"/><path d="M8 9l4 4 4-4"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
			'euro'      => '<circle cx="12" cy="12" r="9"/><path d="M15.5 8.6a4 4 0 1 0 0 6.8"/><path d="M7.5 10.8h6"/><path d="M7.5 13.2h6"/>',
			'analytics' => '<path d="M4 20V4"/><path d="M4 20h16"/><rect x="7" y="12" width="3" height="5"/><rect x="12" y="8" width="3" height="9"/><rect x="17" y="5" width="3" height="12"/>',
			'pulse'     => '<path d="M3 12h4l2 6 4-14 2 8h6"/>',
			'tasks'     => '<path d="M9 11l2 2 4-4"/><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 4V2.5"/><path d="M16 4V2.5"/>',
			'calc'      => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8"/><path d="M8 12h.01"/><path d="M12 12h.01"/><path d="M16 12h.01"/><path d="M8 16h.01"/><path d="M12 16h.01"/><path d="M16 16h.01"/>',
			'book'      => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
			'funnel'    => '<path d="M3 4h18l-7 8v6l-4 2v-8z"/>',
			'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
			'gear'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
			'menu'      => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
			'bell'      => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
			'chat'      => '<path d="M21 11.5a8.5 8.5 0 0 1-12.3 7.6L3 21l1.9-5.7A8.5 8.5 0 1 1 21 11.5z"/>',
		];
		$d = $p[ $name ] ?? '';
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
	}

}
