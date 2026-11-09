<?php

/**
 * (351) Τα Picasso της Protergia φαίνονται μόνο στο «Σταθερό» (μπλε).
 *
 * Αίτημα ιδιοκτήτη 09/11/2026: «το Picasso θέλω να φαίνεται μόνο στα μπλε
 * τιμολόγια». Ως τώρα όλα τα Picasso είχαν κενό `price_type` (0041, 0042:
 * «δεν ξέρουμε το χρώμα»), και η φόρμα δείχνει ένα πρόγραμμα χωρίς χρώμα σε
 * ΚΑΘΕ χρώμα. Εδώ παίρνουν `fixed`.
 *
 * Αγγίζει μόνο όσα έχουν ακόμα κενό χρώμα: αν ο διαχειριστής έχει ήδη δώσει
 * χρώμα από τη σελίδα παρόχων, αυτό μένει. Οι παλιές μεταβάσεις δεν
 * ξαναγράφονται· τρέχουν πάντα όπως έτρεξαν, και αυτή τρέχει μετά από αυτές.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\ProtergiaPicassoPlans;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class MarkProtergiaPicassoFixed implements Migration
{
    /** Τα Picasso 2.0 της 306, εκτός από τα S/M/L του `ProtergiaPicassoPlans`. */
    private const PICASSO_2 = [
        'protergia_picasso2',
        'protergia_picasso2_student',
    ];

    public function id(): string
    {
        return '0057_mark_protergia_picasso_fixed';
    }

    public function description(): string
    {
        return 'Protergia: όλα τα Picasso γίνονται «Σταθερό» (μπλε), να μη φαίνονται σε κάθε χρώμα (351)';
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_merge(self::PICASSO_2, array_keys(ProtergiaPicassoPlans::all()));
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($providers) || ! $schema->hasTable($programs)) {
            return;
        }

        if (! $schema->hasColumn($programs, 'code') || ! $schema->hasColumn($programs, 'price_type')) {
            return;
        }

        $providerId = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $providers, 'protergia')
        );

        if ($providerId <= 0) {
            return;
        }

        foreach (self::codes() as $code) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE %i SET price_type = 'fixed'
                      WHERE provider_id = %d AND code = %s AND (price_type IS NULL OR price_type = '')",
                    $programs,
                    $providerId,
                    $code
                )
            );
        }
    }
}
