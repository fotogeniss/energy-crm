<?php

/**
 * Δίνει στη Volton τα πραγματικά της προγράμματα, και αποσύρει το γενικό
 * πρόγραμμα που στεκόταν στη θέση τους.
 *
 * Η Volton έφτανε εδώ με ένα μόνο πρόγραμμα, το codeless «Σταθερό Οικιακό» που
 * μοιράζει η `ECRM_Providers::seed()` σε κάθε πάροχο ρεύματος χωρίς δικό του
 * κατάλογο — και με **κανένα** πρόγραμμα αερίου, παρότι πουλά και αέριο, γιατί
 * εκείνο το μονοπάτι κοιτάζει μόνο `power`. Ο πωλητής άνοιγε ένα dropdown με
 * μία επιλογή που δεν αντιστοιχεί σε τιμολόγιο, ή με καμία.
 *
 * Ίδιοι κανόνες με το `SeedProtergiaHomePlans`, για τους ίδιους λόγους:
 *
 * - Αποσύρεται **μόνο** δικό μας seed data, και μόνο ανέγγιχτο: ένα starter που
 *   απέκτησε `code` ή που κάποιος μετονόμασε έπαψε να είναι δικό μας τη στιγμή
 *   που το άγγιξε χειριστής.
 * - Απόσυρση σημαίνει `active = 0`, ποτέ DELETE: συμβάσεις που πουλήθηκαν πάνω
 *   του δείχνουν στο id του και πρέπει να συνεχίσουν να τυπώνουν αυτό που
 *   υπογράφηκε.
 * - Η επανεκτέλεση είναι ασφαλής: το βήμα εισαγωγής προσπερνά κάθε ζεύγος
 *   provider/code που υπάρχει ήδη.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\VoltonPlans;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class SeedVoltonPlans implements Migration
{
    /** Το placeholder όνομα που έστελνε η ECRM_Providers::seed() για ρεύμα. */
    private const RETIRED_STARTER = 'Σταθερό Οικιακό';

    public function id(): string
    {
        return '0028_seed_volton_plans';
    }

    public function description(): string
    {
        return 'Τα 23 προγράμματα Volton (οικιακά, επαγγελματικά, κοινόχρηστο)'
            . ' με code, στη θέση του γενικού προγράμματος';
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
            // Το AddProgramCodeColumn (0012) τρέχει νωρίτερα στο MigrationList,
            // αλλά μια εκτέλεση πάνω σε μισοενημερωμένο σχήμα δεν πρέπει να
            // εισάγει γραμμές που κανείς δεν θα μπορεί μετά να βρει από code.
            return;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $providerId = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM %i WHERE slug = %s", [$providers, 'volton'])
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        if ($providerId <= 0) {
            return;
        }

        $this->addPlans($programs, $providerId);
        $this->retireStarter($programs, $providerId);
    }

    private function addPlans(string $programs, int $providerId): void
    {
        global $wpdb;

        $sort = 0;

        foreach (VoltonPlans::all() as $code => $plan) {
            $sort++;

            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE provider_id = %d AND code = %s',
                    [$programs, $providerId, $code]
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

            if ($exists > 0) {
                continue;
            }

            $wpdb->insert($programs, [
                'provider_id'  => $providerId,
                'name'         => $plan['label'],
                'code'         => $code,
                'energy_type'  => $plan['energyType'],
                'category'     => $plan['category'],
                'price_type'   => $plan['priceType'],
                'fixed_charge' => $plan['fixedCharge'],
                'price_kwh'    => $plan['priceKwh'],
                'active'       => 1,
                'sort_order'   => $sort,
            ]);
        }
    }

    private function retireStarter(string $programs, int $providerId): void
    {
        global $wpdb;

        $wpdb->update(
            $programs,
            ['active' => 0],
            [
                'provider_id' => $providerId,
                'name'        => self::RETIRED_STARTER,
                'energy_type' => 'power',
                'code'        => null,
            ]
        );
    }
}
