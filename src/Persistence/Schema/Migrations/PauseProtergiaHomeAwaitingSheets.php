<?php

/**
 * (325) Απενεργοποιεί τα οικιακά ρεύματος Protergia που δεν έχουν ακόμα δικό
 * τους έντυπο: Value Bright 2.0 και τα 8 οικιακά Picasso εκτός του S 39,90.
 * Χωρίς αυτό τύπωναν την αίτηση του Picasso Small 39,90. Γιατί:
 * `ProtergiaCatalogue::AWAITING_SHEET`.
 *
 * Μόνο απενεργοποίηση: τίποτα δεν σβήνεται, οι αιτήσεις που ήδη έχουν γίνει
 * μένουν. Τρέχει μία φορά· όταν έρθει το έντυπο, ο διαχειριστής τα
 * ξαναενεργοποιεί από τη σελίδα Παρόχων.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class PauseProtergiaHomeAwaitingSheets implements Migration
{
    /** Γραμμένα εδώ: η μετάβαση κάνει πάντα ό,τι έκανε όταν πρωτοέτρεξε. */
    private const CODES = [
        'protergia_value_bright2',
        'protergia_pic_h_s2',
        'protergia_pic_h_s3',
        'protergia_pic_h_m1',
        'protergia_pic_h_m2',
        'protergia_pic_h_m3',
        'protergia_pic_h_l1',
        'protergia_pic_h_l2',
        'protergia_pic_h_l3',
    ];

    public function id(): string
    {
        return '0046_pause_protergia_home_awaiting_sheets';
    }

    public function description(): string
    {
        return 'Protergia: ανενεργά το Value Bright 2.0 και 8 οικιακά Picasso ώσπου να έρθει το έντυπό τους';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($providers) || ! $schema->hasTable($programs)) {
            return;
        }

        if (! $schema->hasColumn($programs, 'code')) {
            return;
        }

        $providerId = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $providers, 'protergia')
        );

        if ($providerId <= 0) {
            return;
        }

        foreach (self::CODES as $code) {
            $wpdb->update($programs, ['active' => 0], ['provider_id' => $providerId, 'code' => $code]);
        }
    }
}
