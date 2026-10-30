<?php

/**
 * (332) Picasso: ο κανόνας τιμής μαθαίνει την εκκαθάριση με Πακέτο Αναφοράς.
 *
 * Η 0048 έγραψε για κάθε Picasso μόνο το δικό του πακέτο, και η σύγκριση έβγαζε
 * φθηνότερο το μικρότερο πακέτο. Η ΓΟΣ (Ειδικός Όρος 2) ξαναχρεώνει με το
 * Πακέτο Αναφοράς, άρα ο κανόνας χρειάζεται όλη την οικογένεια
 * (Domain\Pricing\PackageFit). Αλλάζει μόνο το `pricing` των Picasso που
 * είναι ακόμα πακέτο -- τιμή που άλλαξε ο διαχειριστής δεν υπάρχει εδώ, γιατί
 * η σελίδα Παρόχων δεν επεξεργάζεται πακέτα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Pricing\ProtergiaPrices2026;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class RepriceProtergiaPicasso implements Migration
{
    public function id(): string
    {
        return '0050_reprice_protergia_picasso';
    }

    public function description(): string
    {
        return 'Protergia Picasso: κανόνας τιμής με Πακέτο Αναφοράς (332)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($providers) || ! $schema->hasTable($programs)) {
            return;
        }

        foreach (['code', 'pricing'] as $column) {
            if (! $schema->hasColumn($programs, $column)) {
                return;
            }
        }

        $providerId = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $providers, 'protergia')
        );

        if ($providerId <= 0) {
            return;
        }

        foreach (ProtergiaPrices2026::all() as $code => $price) {
            if (($price['pricing']['kind'] ?? '') !== 'package') {
                continue;
            }

            $wpdb->query($wpdb->prepare(
                'UPDATE %i SET pricing = %s WHERE provider_id = %d AND code = %s AND pricing LIKE %s',
                $programs,
                (string) json_encode($price['pricing'], JSON_UNESCAPED_UNICODE),
                $providerId,
                $code,
                '%"kind":"package"%'
            ));
        }
    }
}
