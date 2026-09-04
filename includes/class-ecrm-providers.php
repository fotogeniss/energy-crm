<?php
/**
 * Seeds default providers and a few sample programs.
 * Idempotent: uses slug as the unique key, so re-running won't duplicate.
 *
 * @package EnergyCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ECRM_Providers {

	/**
	 * Default providers seen in the energy-reseller space.
	 * energy_types: comma list of power|gas.
	 */
	private static function defaults(): array {
		return [
			[ 'slug' => 'dei',       'name' => 'ΔΕΗ',          'energy_types' => 'power,gas',        'logo_url' => 'https://www.dei.gr/media/ik1eyjgg/deh-darkmode-80px.svg' ],
			[ 'slug' => 'protergia', 'name' => 'PROTERGIA',    'energy_types' => 'power,gas',        'logo_url' => 'https://www.protergia.gr/assets/images/icons/protergia_logo.png' ],
			[ 'slug' => 'heron',     'name' => 'ΗΡΩΝ',         'energy_types' => 'power,gas',        'logo_url' => 'https://heron.gr/media/myeprd3l/heron-logo-header-bg.svg' ],
			[ 'slug' => 'nrg',       'name' => 'NRG',          'energy_types' => 'power,gas',        'logo_url' => 'https://www.nrg.gr/themes/custom/nrg/media/logo2021-vertical.svg' ],
			[ 'slug' => 'enerwave',  'name' => 'Enerwave',     'energy_types' => 'power',            'logo_url' => 'https://www.enerwave.gr/uploads/sites/126315/siteimage-logonormal.png' ],
			[ 'slug' => 'volton',    'name' => 'VOLTON',       'energy_types' => 'power,gas',        'logo_url' => '' ],
			[ 'slug' => 'fysiko',    'name' => 'Φυσικό Αέριο Ελλάδος', 'energy_types' => 'gas',      'logo_url' => 'https://fysikoaerioellados.gr/sites/default/files/logo.svg' ],
			[ 'slug' => 'zenith',    'name' => 'Ζενίθ',        'energy_types' => 'gas,power',        'logo_url' => 'https://zenith.gr/media/x4bjo31r/logo-footer.svg?width=80' ],
			// Η Orizon είναι αποκλειστικά κινητή τηλεφωνία· και τα τρία της
			// έντυπα (ενεργοποίηση, φορητότητα, συνδυαστική) περιγράφουν γραμμή.
			[ 'slug' => 'orizon',    'name' => 'Orizon',       'energy_types' => 'mobile',           'logo_url' => 'https://orizon.gr/wp-content/uploads/2025/04/logo-orizon.svg' ],
		];
	}

	/** Backfill logos + add later providers on existing installs (idempotent). */
	public static function backfill(): void {
		global $wpdb;
		$providers = ECRM_DB::table( 'providers' );
		foreach ( self::defaults() as $row ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$providers} WHERE slug = %s", $row['slug'] ) );
			if ( $id ) {
				$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT logo_url FROM {$providers} WHERE id = %d", $id ) );
				if ( $current === '' && $row['logo_url'] ) {
					$wpdb->update( $providers, [ 'logo_url' => $row['logo_url'] ], [ 'id' => $id ] );
				}
			} else {
				$wpdb->insert( $providers, [
					'slug' => $row['slug'], 'name' => $row['name'],
					'energy_types' => $row['energy_types'], 'logo_url' => $row['logo_url'],
					'active' => 1, 'sort_order' => 50,
				] );
			}
		}
	}

	public static function seed(): void {
		global $wpdb;
		$providers = ECRM_DB::table( 'providers' );
		$programs  = ECRM_DB::table( 'programs' );
		$order     = 0;

		foreach ( self::defaults() as $row ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$providers} WHERE slug = %s", $row['slug'] )
			);

			if ( ! $exists ) {
				$wpdb->insert(
					$providers,
					[
						'slug'         => $row['slug'],
						'name'         => $row['name'],
						'energy_types' => $row['energy_types'],
						'logo_url'     => $row['logo_url'] ?? '',
						'active'       => 1,
						'sort_order'   => $order,
					],
					[ '%s', '%s', '%s', '%s', '%d', '%d' ]
				);
				$provider_id = (int) $wpdb->insert_id;

				// One starter program per energy the provider sells, so the form
				// is usable out of the box. Without a program the dropdown
				// reads "—" and the agent has nothing to pick.
				$starters = [];

				if ( $row['slug'] === 'protergia' ) {
					// Η Protergia δίνει τέσσερα οικιακά έντυπα, ένα ανά τιμολόγιο.
					// Ένα γενικό «Σταθερό Οικιακό» χωρίς code δεν αντιστοιχεί σε
					// κανένα από αυτά, άρα δεν θα μπορούσε ποτέ να τυπωθεί σωστά.
					foreach ( \EnergyCRM\Domain\Forms\ProtergiaHomePlans::all() as $code => $plan ) {
						$starters[] = [ $plan['label'], $code, 'power', $plan['priceType'], $plan['fixedCharge'], $plan['priceKwh'] ];
					}
				} elseif ( $row['slug'] === 'volton' ) {
					// Η Volton δίνει 23 προγράμματα, σε ρεύμα ΚΑΙ σε αέριο, και σε
					// τρεις κατηγορίες. Το γενικό «Σταθερό Οικιακό» δεν αντιστοιχεί
					// σε κανένα τιμολόγιό της, και το γενικό μονοπάτι δεν φτιάχνει
					// ποτέ γραμμή αερίου — άρα η μισή δουλειά της έμενε χωρίς
					// επιλογή στο dropdown. Έβδομο στοιχείο η κατηγορία: μόνο αυτός
					// ο κατάλογος έχει προγράμματα εκτός «home».
					foreach ( \EnergyCRM\Domain\Forms\VoltonPlans::all() as $code => $plan ) {
						$starters[] = [
							$plan['label'], $code, $plan['energyType'], $plan['priceType'],
							$plan['fixedCharge'], $plan['priceKwh'], $plan['category'],
						];
					}
				} elseif ( str_contains( $row['energy_types'], 'power' ) ) {
					$starters[] = [ 'Σταθερό Οικιακό', '', 'power' ];
				}
				if ( str_contains( $row['energy_types'], 'mobile' ) ) {
					// Τα τέσσερα πραγματικά πλάνα, με το code που διαβάζει το
					// ECRM_FormFill για να τυπώσει τη σωστή τιμή — όχι τα δύο
					// γενικά placeholder που καμία τιμή δεν μπορεί ποτέ να
					// συνδεθεί μαζί τους. Μία πηγή αλήθειας: αν η Orizon
					// αλλάξει πλάνα, αλλάζει το MobilePlans και ακολουθεί εδώ.
					foreach ( \EnergyCRM\Domain\Forms\MobilePlans::options() as $code => $label ) {
						$starters[] = [ $label, $code, 'mobile' ];
					}
				}

				foreach ( $starters as $i => $starter ) {
					[ $name, $code, $energy ] = $starter;

					$wpdb->insert(
						$programs,
						[
							'provider_id'  => $provider_id,
							'name'         => $name,
							'code'         => $code !== '' ? $code : null,
							'energy_type'  => $energy,
							'category'     => $starter[6] ?? 'home',
							'price_type'   => $starter[3] ?? 'fixed',
							'fixed_charge' => $starter[4] ?? null,
							'price_kwh'    => $starter[5] ?? null,
							'active'       => 1,
							'sort_order'   => $i,
						]
					);
				}
			}
			$order++;
		}
	}
}
