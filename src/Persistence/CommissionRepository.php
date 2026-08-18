<?php

/**
 * The contract rows commission is calculated from.
 *
 * The amounts themselves come from ECRM_Commissions, which owns the rules. This
 * class only fetches the rows those rules are applied to.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;

final class CommissionRepository
{
    /**
     * Contracts that have earned commission, with their payout state.
     *
     * @param list<string> $payableStatuses
     *
     * @return list<array<string, mixed>>
     */
    public function payable(UserScope $scope, array $payableStatuses, int $limit = 2000): array
    {
        global $wpdb;

        if ($payableStatuses === []) {
            return [];
        }

        [$clause, $scopeParams] = $this->scopeClause($scope, 'c');
        $statusPlaceholders     = implode(',', array_fill(0, count($payableStatuses), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.status, c.provider_id, c.program_id,
                        c.energy_type, c.category, c.updated_at, c.payout_id,
                        c.payout_amount,
                        po.status AS payout_status,
                        p.name AS provider_name,
                        cu.first_name, cu.last_name, cu.company_name
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 LEFT JOIN %i po ON po.id = c.payout_id
                 WHERE c.status IN ({$statusPlaceholders}){$clause}
                 ORDER BY c.updated_at DESC
                 LIMIT " . max(1, $limit),
                [
                    Tables::name(Tables::CONTRACTS),
                    Tables::name(Tables::CUSTOMERS),
                    Tables::name(Tables::PROVIDERS),
                    Tables::name(Tables::PAYOUTS),
                    ...$payableStatuses,
                    ...$scopeParams,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * Πόσες συμβάσεις έχουν κερδίσει προμήθεια συνολικά.
     *
     * Η payable() σταματά στο LIMIT. Χωρίς αυτό το νούμερο, το σύνολο ευρώ που
     * βλέπει ο συνεργάτης είναι σιωπηλά λάθος όταν τις ξεπεράσει — και λεφτά
     * που βγαίνουν λάθος χωρίς να το πει κανείς είναι το χειρότερο είδος.
     *
     * @param list<string> $payableStatuses
     */
    public function countPayable(UserScope $scope, array $payableStatuses): int
    {
        global $wpdb;

        if ($payableStatuses === []) {
            return 0;
        }

        [$clause, $scopeParams] = $this->scopeClause($scope);
        $statusPlaceholders     = implode(',', array_fill(0, count($payableStatuses), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE status IN ({$statusPlaceholders}){$clause}",
                [Tables::name(Tables::CONTRACTS), ...$payableStatuses, ...$scopeParams]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return (int) $total;
    }

    /**
     * Contracts still in flight, for the "expected" figure. Only the fields the
     * commission rules read — this is an estimate, not a statement.
     *
     * @param list<string> $statuses
     *
     * @return list<array<string, mixed>>
     */
    public function inProgress(UserScope $scope, array $statuses, int $limit = 2000): array
    {
        global $wpdb;

        if ($statuses === []) {
            return [];
        }

        [$clause, $scopeParams] = $this->scopeClause($scope);
        $statusPlaceholders     = implode(',', array_fill(0, count($statuses), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT provider_id, program_id, energy_type, category
                 FROM %i WHERE status IN ({$statusPlaceholders}){$clause}
                 LIMIT " . max(1, $limit),
                [Tables::name(Tables::CONTRACTS), ...$statuses, ...$scopeParams]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * Το όνομα μένει επειδή το καλεί ήδη το υπόλοιπο αρχείο· το σώμα έφυγε στο
     * ScopeClause, όπου ζει η μία και μόνη έκδοση. Ήταν λέξη προς λέξη το ίδιο —
     * σωστό σήμερα, και δεύτερο μέρος να γίνει λάθος αύριο.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function scopeClause(UserScope $scope, string $alias = ''): array
    {
        return ScopeClause::forScope($scope, $alias);
    }
}
