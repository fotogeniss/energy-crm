<?php
/**
 * Admin: manage providers & their programs.
 *
 * Server-rendered WP-admin forms posting to admin-post.php actions
 * (nonce + capability guarded). Submenu under the Energy CRM menu.
 *
 *   Providers: add / edit (name, slug, energy types, logo, active, order) / delete
 *   Programs:  per-provider add / edit (name, energy, category, price type, active) / delete
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Providers_Admin {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 20 );
		add_action( 'admin_post_ecrm_save_provider',   [ __CLASS__, 'save_provider' ] );
		add_action( 'admin_post_ecrm_delete_provider', [ __CLASS__, 'delete_provider' ] );
		add_action( 'admin_post_ecrm_save_program',    [ __CLASS__, 'save_program' ] );
		add_action( 'admin_post_ecrm_delete_program',  [ __CLASS__, 'delete_program' ] );
		add_action( 'admin_enqueue_scripts',           [ __CLASS__, 'assets' ] );
	}

	public static function menu(): void {
		add_submenu_page(
			'energy-crm',
			'Πάροχοι & Προγράμματα',
			'Πάροχοι & Προγράμματα',
			'manage_options',
			'energy-crm-providers',
			[ __CLASS__, 'render' ]
		);
	}

	public static function assets( string $hook ): void {
		if ( strpos( $hook, 'energy-crm-providers' ) === false ) {
			return;
		}
		wp_enqueue_media(); // for the logo picker
		wp_add_inline_script( 'jquery-core', self::media_js() );
	}

	private static function media_js(): string {
		return <<<JS
jQuery(function($){
	$(document).on('click', '.ecrm-pick-logo', function(e){
		e.preventDefault();
		var \$row = $(this).closest('.ecrm-logo-row');
		var frame = wp.media({ title: 'Επιλογή logo', multiple: false, library: { type: 'image' } });
		frame.on('select', function(){
			var att = frame.state().get('selection').first().toJSON();
			\$row.find('input.ecrm-logo-url').val(att.url);
			\$row.find('img.ecrm-logo-prev').attr('src', att.url).show();
		});
		frame.open();
	});
});
JS;
	}

	// ---------------------------------------------------------------------
	// Render
	// ---------------------------------------------------------------------
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$pt = ECRM_DB::table( 'providers' );

		$edit_id  = isset( $_GET['provider'] ) ? (int) $_GET['provider'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$editing  = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pt} WHERE id = %d", $edit_id ), ARRAY_A ) : null;

		echo '<div class="wrap"><h1>Πάροχοι &amp; Προγράμματα</h1>';
		self::notices();

		if ( $editing ) {
			self::render_provider_edit( $editing );
		} else {
			self::render_provider_list();
			self::render_provider_form( null );
		}
		echo '</div>';
	}

	private static function notices(): void {
		$m = isset( $_GET['ecrm_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ecrm_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$map = [
			'provider_saved'   => 'Ο πάροχος αποθηκεύτηκε.',
			'provider_deleted' => 'Ο πάροχος διαγράφηκε.',
			'program_saved'    => 'Το πρόγραμμα αποθηκεύτηκε.',
			'program_deleted'  => 'Το πρόγραμμα διαγράφηκε.',
		];
		if ( isset( $map[ $m ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $m ] ) . '</p></div>';
		}
	}

	private static function render_provider_list(): void {
		global $wpdb;
		$pt = ECRM_DB::table( 'providers' );
		$gt = ECRM_DB::table( 'programs' );
		$rows = $wpdb->get_results( "SELECT p.*, ( SELECT COUNT(*) FROM {$gt} g WHERE g.provider_id = p.id ) AS programs FROM {$pt} p ORDER BY p.sort_order, p.name", ARRAY_A );

		echo '<h2>Πάροχοι</h2><table class="widefat striped"><thead><tr>'
			. '<th>Logo</th><th>Όνομα</th><th>Slug</th><th>Είδη</th><th>Προγράμματα</th><th>Ενεργός</th><th>Σειρά</th><th></th>'
			. '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="8">Δεν υπάρχουν πάροχοι.</td></tr>';
		}
		foreach ( (array) $rows as $r ) {
			$edit = esc_url( add_query_arg( [ 'page' => 'energy-crm-providers', 'provider' => $r['id'] ], admin_url( 'admin.php' ) ) );
			echo '<tr>';
			echo '<td>' . ( $r['logo_url'] ? '<img src="' . esc_url( $r['logo_url'] ) . '" style="max-height:28px;max-width:80px;object-fit:contain">' : '—' ) . '</td>';
			echo '<td><strong>' . esc_html( $r['name'] ) . '</strong></td>';
			echo '<td><code>' . esc_html( $r['slug'] ) . '</code></td>';
			echo '<td>' . esc_html( $r['energy_types'] ) . '</td>';
			echo '<td>' . (int) $r['programs'] . '</td>';
			echo '<td>' . ( $r['active'] ? '✔' : '—' ) . '</td>';
			echo '<td>' . (int) $r['sort_order'] . '</td>';
			echo '<td><a class="button button-small" href="' . $edit . '">Επεξεργασία</a></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $edit went through esc_url() where it was built, a few lines up.
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_provider_form( ?array $p ): void {
		$is_edit = (bool) $p;
		$energy  = $p ? explode( ',', (string) $p['energy_types'] ) : [ 'power', 'gas' ];
		?>
		<h2 style="margin-top:28px;"><?php echo $is_edit ? 'Επεξεργασία παρόχου' : 'Νέος πάροχος'; ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ecrm_save_provider' ); ?>
			<input type="hidden" name="action" value="ecrm_save_provider">
			<?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><?php endif; ?>
			<table class="form-table" role="presentation">
				<tr><th><label>Όνομα</label></th><td><input type="text" name="name" class="regular-text" required value="<?php echo esc_attr( $p['name'] ?? '' ); ?>"></td></tr>
				<tr><th><label>Slug</label></th><td><input type="text" name="slug" class="regular-text" value="<?php echo esc_attr( $p['slug'] ?? '' ); ?>" placeholder="π.χ. dei"><p class="description">Αν μείνει κενό, παράγεται από το όνομα.</p></td></tr>
				<tr><th>Είδη ενέργειας</th><td>
					<label><input type="checkbox" name="energy_types[]" value="power" <?php checked( in_array( 'power', $energy, true ) ); ?>> Ηλεκτρισμός</label>&nbsp;&nbsp;
					<label><input type="checkbox" name="energy_types[]" value="gas" <?php checked( in_array( 'gas', $energy, true ) ); ?>> Φυσικό Αέριο</label>&nbsp;&nbsp;
					<label><input type="checkbox" name="energy_types[]" value="mobile" <?php checked( in_array( 'mobile', $energy, true ) ); ?>> Κινητή Τηλεφωνία</label>
				</td></tr>
				<tr><th>Logo</th><td class="ecrm-logo-row">
					<input type="url" name="logo_url" class="regular-text ecrm-logo-url" value="<?php echo esc_attr( $p['logo_url'] ?? '' ); ?>" placeholder="https://…">
					<button class="button ecrm-pick-logo">Επιλογή από βιβλιοθήκη</button>
					<br><img class="ecrm-logo-prev" src="<?php echo esc_attr( $p['logo_url'] ?? '' ); ?>" style="max-height:40px;margin-top:8px;<?php echo empty( $p['logo_url'] ) ? 'display:none' : ''; ?>">
				</td></tr>
				<tr><th>Ενεργός</th><td><label><input type="checkbox" name="active" value="1" <?php checked( $p ? (int) $p['active'] : 1, 1 ); ?>> Εμφανίζεται στη φόρμα</label></td></tr>
				<tr><th>Σειρά</th><td><input type="number" name="sort_order" value="<?php echo (int) ( $p['sort_order'] ?? 0 ); ?>" class="small-text"></td></tr>
			</table>
			<?php submit_button( $is_edit ? 'Αποθήκευση παρόχου' : 'Προσθήκη παρόχου' ); ?>
		</form>
		<?php
	}

	private static function render_provider_edit( array $p ): void {
		$back = esc_url( admin_url( 'admin.php?page=energy-crm-providers' ) );
		echo '<p><a href="' . $back . '">&larr; Πίσω στη λίστα</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $back went through esc_url() on the line above.
		self::render_provider_form( $p );

		// Delete provider
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Διαγραφή παρόχου και των προγραμμάτων του;\');" style="margin:8px 0 28px;">';
		wp_nonce_field( 'ecrm_delete_provider' );
		echo '<input type="hidden" name="action" value="ecrm_delete_provider"><input type="hidden" name="id" value="' . (int) $p['id'] . '">';
		echo '<button class="button button-link-delete">Διαγραφή παρόχου</button></form>';

		self::render_programs( (int) $p['id'] );
	}

	private static function render_programs( int $provider_id ): void {
		global $wpdb;
		$gt   = ECRM_DB::table( 'programs' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$gt} WHERE provider_id = %d ORDER BY sort_order, name", $provider_id ), ARRAY_A );

		$cats = [ 'home' => 'Οικιακό', 'business' => 'Επαγγελματικό', 'communal' => 'Κοινόχρηστο' ];
		$pts  = [ 'fixed' => 'Σταθερό', 'special' => 'Ειδικό', 'variable' => 'Κυμαινόμενο', 'dynamic' => 'Δυναμικό' ];

		echo '<hr><h2>Προγράμματα παρόχου</h2><table class="widefat striped"><thead><tr><th>Όνομα</th><th>Είδος</th><th>Κατηγορία</th><th>Τύπος</th><th>€/kWh</th><th>Πάγιο</th><th>Ενεργό</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">Δεν υπάρχουν προγράμματα ακόμα.</td></tr>';
		}
		foreach ( (array) $rows as $g ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $g['name'] ) . '</strong></td>';
			echo '<td>' . ( ECRM_DB::energy_label( (string) $g['energy_type'] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- energy_label() returns one of three literal labels and falls back to a literal; the stored value never reaches the page.
			echo '<td>' . esc_html( $cats[ $g['category'] ] ?? $g['category'] ) . '</td>';
			echo '<td>' . esc_html( $pts[ $g['price_type'] ] ?? $g['price_type'] ) . '</td>';
			echo '<td>' . ( $g['price_kwh'] !== null && $g['price_kwh'] !== '' ? esc_html( number_format( (float) $g['price_kwh'], 5 ) ) : '—' ) . '</td>';
			echo '<td>' . ( $g['fixed_charge'] !== null && $g['fixed_charge'] !== '' ? esc_html( number_format( (float) $g['fixed_charge'], 2 ) ) . '€' : '—' ) . '</td>';
			echo '<td>' . ( $g['active'] ? '✔' : '—' ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Διαγραφή προγράμματος;\');" style="display:inline">';
			wp_nonce_field( 'ecrm_delete_program' );
			echo '<input type="hidden" name="action" value="ecrm_delete_program"><input type="hidden" name="id" value="' . (int) $g['id'] . '"><input type="hidden" name="provider_id" value="' . (int) $provider_id . '">';
			echo '<button class="button button-small button-link-delete">Διαγραφή</button></form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Add program form
		?>
		<h3 style="margin-top:20px;">Νέο πρόγραμμα</h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ecrm_save_program' ); ?>
			<input type="hidden" name="action" value="ecrm_save_program">
			<input type="hidden" name="provider_id" value="<?php echo (int) $provider_id; ?>">
			<table class="form-table" role="presentation">
				<tr><th><label>Όνομα</label></th><td><input type="text" name="name" class="regular-text" required placeholder="π.χ. Σταθερό Οικιακό 12μ"></td></tr>
				<tr><th>Είδος</th><td><select name="energy_type"><option value="power">Ηλεκτρισμός</option><option value="gas">Φυσικό Αέριο</option><option value="mobile">Κινητή Τηλεφωνία</option></select></td></tr>
				<tr><th>Κατηγορία</th><td><select name="category"><?php foreach ( $cats as $v => $l ) { echo '<option value="' . esc_attr( $v ) . '">' . esc_html( $l ) . '</option>'; } ?></select></td></tr>
				<tr><th>Τύπος</th><td><select name="price_type"><?php foreach ( $pts as $v => $l ) { echo '<option value="' . esc_attr( $v ) . '">' . esc_html( $l ) . '</option>'; } ?></select></td></tr>
				<tr><th><label>Τιμή €/kWh</label></th><td><input type="number" step="0.00001" name="price_kwh" class="small-text" placeholder="π.χ. 0.149"> <span class="description">προμήθεια ενέργειας ανά kWh</span></td></tr>
				<tr><th><label>Πάγιο €/μήνα</label></th><td><input type="number" step="0.01" name="fixed_charge" class="small-text" placeholder="π.χ. 5.00"></td></tr>
				<tr><th>Σειρά</th><td><input type="number" name="sort_order" value="0" class="small-text"></td></tr>
			</table>
			<?php submit_button( 'Προσθήκη προγράμματος' ); ?>
		</form>
		<?php
	}

	// ---------------------------------------------------------------------
	// Handlers
	// ---------------------------------------------------------------------
	private static function guard( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Δεν επιτρέπεται.' );
		}
		check_admin_referer( $nonce );
	}

	private static function back( string $msg, int $provider = 0 ): void {
		$args = [ 'page' => 'energy-crm-providers', 'ecrm_msg' => $msg ];
		if ( $provider ) {
			$args['provider'] = $provider;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function save_provider(): void {
		self::guard( 'ecrm_save_provider' );
		global $wpdb;
		$pt   = ECRM_DB::table( 'providers' );
		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$slug = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		if ( $slug === '' ) {
			$slug = sanitize_title( $name );
		}
		$types = isset( $_POST['energy_types'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['energy_types'] ) ) : [];
		$types = array_values( array_intersect( $types, [ 'power', 'gas', 'mobile' ] ) );

		$data = [
			'name'         => $name,
			'slug'         => $slug,
			'energy_types' => implode( ',', $types ?: [ 'power' ] ),
			'logo_url'     => esc_url_raw( wp_unslash( $_POST['logo_url'] ?? '' ) ),
			'active'       => isset( $_POST['active'] ) ? 1 : 0,
			'sort_order'   => (int) ( $_POST['sort_order'] ?? 0 ),
		];

		if ( $id ) {
			$wpdb->update( $pt, $data, [ 'id' => $id ] );
		} else {
			$wpdb->insert( $pt, $data );
			$id = (int) $wpdb->insert_id;
		}
		delete_transient( 'ect_crm_page_id' ); // harmless cache nudge
		self::back( 'provider_saved', $id );
	}

	public static function delete_provider(): void {
		self::guard( 'ecrm_delete_provider' );
		global $wpdb;
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id ) {
			$wpdb->delete( ECRM_DB::table( 'programs' ), [ 'provider_id' => $id ] );
			$wpdb->delete( ECRM_DB::table( 'providers' ), [ 'id' => $id ] );
		}
		self::back( 'provider_deleted' );
	}

	public static function save_program(): void {
		self::guard( 'ecrm_save_program' );
		global $wpdb;
		$provider = (int) ( $_POST['provider_id'] ?? 0 );
		$id       = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$data = [
			'provider_id' => $provider,
			'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'energy_type' => in_array( ( $_POST['energy_type'] ?? '' ), [ 'power', 'gas', 'mobile' ], true ) ? $_POST['energy_type'] : 'power',
			'category'    => sanitize_text_field( wp_unslash( $_POST['category'] ?? 'home' ) ),
			'price_type'  => sanitize_text_field( wp_unslash( $_POST['price_type'] ?? 'fixed' ) ),
			'price_kwh'   => ( isset( $_POST['price_kwh'] ) && $_POST['price_kwh'] !== '' ) ? (float) $_POST['price_kwh'] : null,
			'fixed_charge'=> ( isset( $_POST['fixed_charge'] ) && $_POST['fixed_charge'] !== '' ) ? (float) $_POST['fixed_charge'] : null,
			'active'      => 1,
			'sort_order'  => (int) ( $_POST['sort_order'] ?? 0 ),
		];
		if ( $id ) {
			$wpdb->update( ECRM_DB::table( 'programs' ), $data, [ 'id' => $id ] );
		} else {
			$wpdb->insert( ECRM_DB::table( 'programs' ), $data );
		}
		self::back( 'program_saved', $provider );
	}

	public static function delete_program(): void {
		self::guard( 'ecrm_delete_program' );
		global $wpdb;
		$id       = (int) ( $_POST['id'] ?? 0 );
		$provider = (int) ( $_POST['provider_id'] ?? 0 );
		if ( $id ) {
			$wpdb->delete( ECRM_DB::table( 'programs' ), [ 'id' => $id ] );
		}
		self::back( 'program_deleted', $provider );
	}
}
