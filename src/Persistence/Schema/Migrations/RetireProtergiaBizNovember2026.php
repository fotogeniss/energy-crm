<?php

/**
 * (374) Protergia 13/11/2026: «Καταργούνται τα επαγγελματικά προγράμματα
 * ηλεκτρικής ενέργειας Value Seasonal 2026 (Ε1) και Value Sure 2.0 (Ε3)».
 *
 * Απενεργοποιούνται, δεν σβήνονται: φεύγουν από τη «Νέα αίτηση», αλλά οι
 * αιτήσεις που έγιναν μ' αυτά τα κρατάνε και τυπώνουν το φύλλο τους όπως
 * πριν. Ίδιο σχήμα με το Γ21 Sure 12 3.0 (319) και τα παλιά της 0058.
 *
 * Οι κωδικοί είναι γραμμένοι εδώ και όχι διαβασμένοι από το
 * `ProtergiaBizCatalogue::RETIRED`, ώστε η μετάβαση να τρέχει πάντα όπως έτρεξε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class RetireProtergiaBizNovember2026 implements Migration
{
    public const CODES = [
        'protergia_epag_seasonal',
        'protergia_epag3_sure12m_2',
    ];

    public function id(): string
    {
        return '0061_retire_protergia_biz_november_2026';
    }

    public function description(): string
    {
        return 'Protergia: τέλος τα επαγγελματικά Value Seasonal 2026 (Ε1) και Value Sure 2.0 (Ε3) (374)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($providers) || ! $schema->hasTable($programs)) {
            return;
        }

        if (! $schema->hasColumn($programs, 'code') || ! $schema->hasColumn($programs, 'active')) {
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
