<?php

/**
 * (331) Στήλη programs.exit_fees και η ρήτρα αποχώρησης των Protergia.
 *
 * Το «Τι λέω στον πελάτη» λέει πόσο κοστίζει η πρόωρη αποχώρηση -- το πιο
 * συνηθισμένο παράπονο μετά την υπογραφή. JSON: 12 ποσά, ένα ανά μήνα
 * αποχώρησης. Μόνο όπου είναι ακόμα κενή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Pricing\ProtergiaPrices2026;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddProgramExitFees implements Migration
{
    public function id(): string
    {
        return '0049_add_program_exit_fees';
    }

    public function description(): string
    {
        return 'Στήλη programs.exit_fees και ρήτρα αποχώρησης Protergia από τα έντυπα (331)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($programs)) {
            return;
        }

        if (! $schema->hasColumn($programs, 'exit_fees')) {
            // Identifiers are constants; DDL cannot be parameterised.
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$programs}` ADD COLUMN `exit_fees` TEXT NULL");
        }

        if (! $schema->hasTable($providers) || ! $schema->hasColumn($programs, 'code')) {
            return;
        }

        $providerId = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $providers, 'protergia')
        );

        if ($providerId <= 0) {
            return;
        }

        foreach (ProtergiaPrices2026::exitFees() as $code => $fees) {
            $wpdb->update(
                $programs,
                ['exit_fees' => (string) json_encode($fees)],
                ['provider_id' => $providerId, 'code' => $code, 'exit_fees' => null]
            );
        }
    }
}
