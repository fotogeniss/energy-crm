<?php

/**
 * (370) Οι κάρτες παρόχων της «Νέας αίτησης» (νέα εμφάνιση) παίρνουν το
 * χρώμα του ίδιου του παρόχου. Το χρώμα ταξιδεύει στον κατάλογο που ζητά η
 * φόρμα, οι γνωστοί πάροχοι το βρίσκουν προγεμισμένο, και ό,τι διάλεξε ο
 * διαχειριστής δεν το σκεπάζει η επόμενη αναβάθμιση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Providers;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class ProviderBrandColorTest extends IntegrationTestCase
{
    public function testTheCatalogueCarriesEachProvidersColour(): void
    {
        global $wpdb;

        $slug = 'ecrm-bc-' . wp_generate_password(6, false);
        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug'        => $slug,
            'name'        => 'Πάροχος ' . $slug,
            'active'      => 1,
            'sort_order'  => 0,
            'brand_color' => '#123abc',
        ]);
        $providerId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $providerId, 'Το provider fixture δεν μπήκε.');

        $row = null;
        foreach (Services::providers()->active() as $candidate) {
            if ((int) $candidate['id'] === $providerId) {
                $row = $candidate;
            }
        }

        self::assertNotNull($row, 'Ο ενεργός πάροχος λείπει από τον κατάλογο.');
        self::assertSame('#123abc', $row['brand_color'] ?? null);
    }

    public function testBackfillFillsAMissingColourButKeepsTheAdminsChoice(): void
    {
        global $wpdb;

        $table = Tables::name(Tables::PROVIDERS);
        ECRM_Providers::backfill();
        $before = $this->colourOf('zenith');

        $wpdb->update($table, ['brand_color' => ''], ['slug' => 'zenith']);
        ECRM_Providers::backfill();
        self::assertSame('#c4002f', $this->colourOf('zenith'));

        $wpdb->update($table, ['brand_color' => '#00aa00'], ['slug' => 'zenith']);
        ECRM_Providers::backfill();
        self::assertSame('#00aa00', $this->colourOf('zenith'));

        $wpdb->update($table, ['brand_color' => $before], ['slug' => 'zenith']);
    }

    private function colourOf(string $slug): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare('SELECT brand_color FROM %i WHERE slug = %s', Tables::name(Tables::PROVIDERS), $slug)
        );
    }
}
