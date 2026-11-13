<?php

/**
 * (373) Τρία ενεργά προγράμματα της Protergia χωρίς χρώμα (`price_type`)
 * φαίνονταν σε ΚΑΘΕ χρώμα της «Νέας αίτησης», όπως το Picasso πριν το 351.
 * Το χρώμα διαβάστηκε από τον «Τύπο τιμολόγησης» στη σελ. 2 του εντύπου τους:
 *
 *     Value Standard 2.0 (οικιακό)   «Κυμαινόμενη χωρίς όρια διακύμανσης»
 *     Student Plan (οικιακό)         «Κυμαινόμενη Χωρίς Ρήτρα Αναπροσαρμογής»
 *     Επαγγελματικό 2 Value Power 2.0 «Σταθερή» (κλιμακωτή χρέωση)
 *
 * Το Power+Gas δεν έχει δικό του έντυπο και μένει χωρίς χρώμα (απόφαση
 * ιδιοκτήτη 12/11/2026). Mockup docs/UI-PROTERGIA-COLOURS.html.
 *
 * Αγγίζει μόνο όσα έχουν ακόμα κενό χρώμα: ό,τι έδωσε ο διαχειριστής από τη
 * σελίδα παρόχων μένει. Οι κωδικοί είναι γραμμένοι εδώ και όχι διαβασμένοι
 * από τον κατάλογο, ώστε η μετάβαση να τρέχει πάντα όπως έτρεξε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class SetProtergiaColoursFromForms implements Migration
{
    /** @var array<string, string> κωδικός προγράμματος → price_type */
    public const COLOURS = [
        'protergia_value_standard2' => 'variable',
        'protergia_student_plan'    => 'variable',
        'protergia_epag2_power2'    => 'fixed',
    ];

    public function id(): string
    {
        return '0060_set_protergia_colours_from_forms';
    }

    public function description(): string
    {
        return 'Protergia: χρώμα από το έντυπο για Standard 2.0, Student Plan, Value Power 2.0 (373)';
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

        foreach (self::COLOURS as $code => $priceType) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE %i SET price_type = %s
                      WHERE provider_id = %d AND code = %s AND (price_type IS NULL OR price_type = '')",
                    $programs,
                    $priceType,
                    $providerId,
                    $code
                )
            );
        }
    }
}
