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
     * Το `$memberId` στένεψε το ίδιο ερώτημα σε έναν άνθρωπο, για την καρτέλα
     * συνεργάτη. Μπήκε ως ΠΑΡΑΜΕΤΡΟΣ και όχι ως δεύτερη μέθοδος επίτηδες: το
     * αρχείο αυτό έχει ήδη γραμμένο στην κορυφή του γιατί μια δεύτερη γραφή
     * του ίδιου join είναι «το ίδιο λάθος με καλύτερα ρούχα». Λεφτά που
     * βγαίνουν από δύο ερωτήματα βγαίνουν κάποτε διαφορετικά.
     *
     * Η ρήτρα του scope ΜΕΝΕΙ όταν δοθεί μέλος — δεν την αντικαθιστά. Το ένα
     * λέει «ποιον ζήτησες», το άλλο «ποιον επιτρέπεται να δεις», και ένα
     * ξεχασμένο `if` σε μελλοντικό caller δεν πρέπει να μπορεί να γίνει
     * διαρροή.
     *
     * @param list<string> $payableStatuses
     *
     * @return list<array<string, mixed>>
     */
    public function payable(
        UserScope $scope,
        array $payableStatuses,
        int $limit = 2000,
        ?int $memberId = null
    ): array {
        global $wpdb;

        if ($payableStatuses === []) {
            return [];
        }

        [$clause, $scopeParams] = $this->scopeClause($scope, 'c');
        $statusPlaceholders     = implode(',', array_fill(0, count($payableStatuses), '%s'));
        $memberClause           = null === $memberId ? '' : ' AND c.partner_user_id = %d';
        $memberParams           = null === $memberId ? [] : [$memberId];

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
                 WHERE c.status IN ({$statusPlaceholders}){$memberClause}{$clause}
                 ORDER BY c.updated_at DESC
                 LIMIT " . max(1, $limit),
                [
                    Tables::name(Tables::CONTRACTS),
                    Tables::name(Tables::CUSTOMERS),
                    Tables::name(Tables::PROVIDERS),
                    Tables::name(Tables::PAYOUTS),
                    ...$payableStatuses,
                    ...$memberParams,
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
