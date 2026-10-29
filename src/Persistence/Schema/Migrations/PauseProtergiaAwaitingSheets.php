<?php

/**
 * Απενεργοποιεί τα προγράμματα Protergia που δεν έχουν ακόμα δικό τους
 * έντυπο (Value Gas Sure 2.0, Power+Gas). Γιατί: `ProtergiaCatalogue::AWAITING_SHEET`
 * και, για το Power+Gas που τελικά δεν είναι πρόγραμμα, `NOT_A_PROGRAM`.
 *
 * Μόνο απενεργοποίηση: τίποτα δεν σβήνεται, οι αιτήσεις που ήδη έχουν
 * γίνει με αυτά μένουν και τυπώνονται όπως πριν. Τρέχει μία φορά· όταν
 * έρθει το έντυπο, ο διαχειριστής τα ξαναενεργοποιεί από τη σελίδα
 * Παρόχων και η μετάβαση αυτή δεν τα ξανακλείνει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class PauseProtergiaAwaitingSheets implements Migration
{
    public function id(): string
    {
        return '0045_pause_protergia_awaiting_sheets';
    }

    public function description(): string
    {
        return 'Protergia: ανενεργά τα Value Gas Sure 2.0 και Power+Gas ώσπου να έρθει το έντυπό τους';
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

        // Λίστα γραμμένη εδώ, όχι από το ProtergiaCatalogue: η μετάβαση
        // κάνει ό,τι έκανε όταν πρωτοέτρεξε, ακόμα κι αν η λίστα εκεί αλλάξει.
        foreach (['protergia_gas_sure2', 'protergia_power_gas_fa', 'protergia_power_gas_he'] as $code) {
            $wpdb->update($programs, ['active' => 0], ['provider_id' => $providerId, 'code' => $code]);
        }
    }
}
