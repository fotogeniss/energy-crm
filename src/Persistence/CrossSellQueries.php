<?php

/**
 * (333) Οι πελάτες-ευκαιρίες για «+ Αέριο / + Ρεύμα».
 *
 * Κανόνας στο Domain\Customer\CrossSell. Εδώ μόνο το SQL: ενεργή σύμβαση της
 * ενέργειας που έχει, μέσα στο scope του χρήστη· καμία μη ακυρωμένη και μη
 * πρόχειρη σύμβαση της ενέργειας που λείπει· κανένα «Όχι τώρα» τις τελευταίες
 * 90 μέρες.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Domain\Customer\CrossSell;

final class CrossSellQueries
{
    private CustomerFields $fields;

    public function __construct(?CustomerFields $fields = null)
    {
        $this->fields = $fields ?? CustomerFields::default();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function candidates(
        UserScope $scope,
        string $want,
        string $city,
        string $category,
        string $today,
        int $limit = 300
    ): array {
        global $wpdb;

        $has = CrossSell::has($want);

        if ($has === '') {
            return [];
        }

        [$scopeSql, $scopeIds] = ScopeClause::forScope($scope, 'c');

        $conditions = '';
        $params     = [
            Tables::name(Tables::CONTRACTS),
            $want,
            ContractStatus::Draft->value,
            Tables::name(Tables::CUSTOMERS),
            Tables::name(Tables::CONTRACTS),
            $has,
            ContractStatus::Active->value,
            ...$scopeIds,
        ];

        if ($city !== '') {
            $conditions .= ' AND cu.city = %s';
            $params[]    = $city;
        }

        if ($category !== '') {
            $conditions .= ' AND c.category = %s';
            $params[]    = $category;
        }

        $params = [
            ...$params,
            Tables::name(Tables::CONTRACTS),
            $want,
            ContractStatus::Cancelled->value,
            ContractStatus::Draft->value,
            Tables::name(Tables::CUSTOMER_EVENTS),
            CrossSell::snoozeField($want),
            CrossSell::snoozedSince($today) . ' 00:00:00',
        ];

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cu.id, cu.first_name, cu.last_name, cu.company_name, cu.city, cu.phone, cu.mobile,
                        MAX(c.id) AS contract_id, MAX(c.category) AS category, MAX(c.provider_id) AS provider_id,
                        MAX(COALESCE(c.start_date, c.updated_at)) AS active_since,
                        (SELECT MAX(d.id) FROM %i d
                          WHERE d.customer_id = cu.id AND d.energy_type = %s AND d.status = %s) AS draft_id
                 FROM %i cu
                 JOIN %i c ON c.customer_id = cu.id AND c.energy_type = %s AND c.status = %s{$scopeSql}
                 WHERE 1 = 1{$conditions}
                   AND NOT EXISTS (SELECT 1 FROM %i g
                        WHERE g.customer_id = cu.id AND g.energy_type = %s AND g.status NOT IN (%s, %s))
                   AND NOT EXISTS (SELECT 1 FROM %i e
                        WHERE e.customer_id = cu.id AND e.field = %s AND e.created_at >= %s)
                 GROUP BY cu.id
                 ORDER BY active_since DESC
                 LIMIT " . max(1, $limit),
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $this->fields->fromStorageAll($rows);
    }

    /**
     * Πόλεις των πελατών του χρήστη, για το φίλτρο.
     *
     * @return list<string>
     */
    public function cities(UserScope $scope): array
    {
        global $wpdb;

        [$scopeSql, $scopeIds] = ScopeClause::forScope($scope, 'c');

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT cu.city FROM %i cu
                 JOIN %i c ON c.customer_id = cu.id
                 WHERE cu.city IS NOT NULL AND cu.city <> ''{$scopeSql}
                 ORDER BY cu.city",
                [Tables::name(Tables::CUSTOMERS), Tables::name(Tables::CONTRACTS), ...$scopeIds]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return array_values(array_map('strval', (array) $rows));
    }
}
