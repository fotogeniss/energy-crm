<?php

/**
 * Οι καταστάσεις της 07/09 γίνονται οι καταστάσεις της 05/10/2026.
 *
 * Ο ιδιοκτήτης έδωσε νέες λίστες γραμμένες στο χέρι (ρεύμα και κινητή). Οι
 * περισσότερες παλιές καταστάσεις υπάρχουν ακόμα με το ίδιο slug. Αλλάζουν
 * μόνο οι τρεις τερματικές, που έγιναν μία: «Ακυρώθηκε».
 *
 * Η Διακοπή πάει κι αυτή στην `cancelled`. Η προμήθεια μιας αίτησης που είχε
 * γίνει Ενεργός δεν χάνεται, γιατί το «έφτασε ποτέ σε active» το κρατάει το
 * ιστορικό, όχι η τρέχουσα κατάσταση (`CancellationGate::keepsCommission()`).
 *
 * Τα δεδομένα σήμερα είναι όλα δοκιμαστικά (ιδιοκτήτης, 05/10/2026), αλλά
 * ίδιο σκεπτικό με το `MigrateStatusVocabulary`: καμία γραμμή δεν μένει σε
 * κατάσταση που ο κώδικας δεν αναγνωρίζει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class MigrateStatusesToPaperLists implements Migration
{
    /**
     * παλιό slug => νέο slug.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'terminated'            => 'cancelled',
        'cancelled_by_us'       => 'cancelled',
        'cancelled_by_customer' => 'cancelled',
    ];

    public function id(): string
    {
        return '0040_migrate_statuses_to_paper_lists';
    }

    public function description(): string
    {
        return 'Διακοπή και οι δύο ακυρώσεις γίνονται μία «Ακυρώθηκε» (λίστες καταστάσεων 05/10/2026).';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $contracts = Tables::name(Tables::CONTRACTS);
        $events    = Tables::name(Tables::EVENTS);

        foreach (self::MAP as $old => $new) {
            if ($schema->hasTable($contracts)) {
                $wpdb->query(
                    $wpdb->prepare('UPDATE %i SET status = %s WHERE status = %s', $contracts, $new, $old)
                );
            }

            if (! $schema->hasTable($events)) {
                continue;
            }

            $wpdb->query(
                $wpdb->prepare('UPDATE %i SET from_status = %s WHERE from_status = %s', $events, $new, $old)
            );

            $wpdb->query(
                $wpdb->prepare('UPDATE %i SET to_status = %s WHERE to_status = %s', $events, $new, $old)
            );
        }
    }
}
