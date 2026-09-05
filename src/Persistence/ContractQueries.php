<?php

/**
 * The list queries behind the screens: search, counts, renewals, duplicates.
 *
 * Lifted out of ContractRepository, which had grown to 930 lines against a
 * limit of ~200. The cut runs between *finding many* and *changing one*: these
 * five produce rows for a screen and never write. That is a different job from
 * create/update/delete, and it is the job that grows every time a filter is
 * added to the UI.
 *
 * Everything here is scoped through ScopeClause except possibleDuplicates(),
 * which is unscoped on purpose — see its own note. That is the whole exception
 * list for this file, deliberately short.
 *
 * On the phpcs exemptions: table names are bound with %i and every value is a
 * bound parameter. What phpcs cannot verify is the `IN (%d,%d,…)` fragment,
 * whose length varies with team size. It is produced by UserScope::placeholders(),
 * which emits nothing but "%d" — no request data reaches it. The exemptions
 * name whole categories rather than sub-sniffs, because which sub-sniff fires
 * depends on whether the fragment arrives by interpolation or concatenation,
 * and naming it wrong leaves the statement silently unexempted. Each block
 * wraps exactly one statement.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;

final class ContractQueries
{
    private string $table;

    /** Customer columns arrive here through joins, so they need translating. */
    private CustomerFields $fields;

    public function __construct(?string $table = null, ?CustomerFields $fields = null)
    {
        $this->table  = $table ?? Tables::name(Tables::CONTRACTS);
        $this->fields = $fields ?? CustomerFields::default();
    }

    /**
     * The contracts list, with the joined names the UI shows.
     *
     * @return list<array<string, mixed>>
     */
    public function search(UserScope $scope, string $status = '', string $term = '', int $limit = 200): array
    {
        global $wpdb;

        [$clause, $scopeParams] = ScopeClause::forScope($scope, 'c');

        $params     = [
            $this->table,
            Tables::name(Tables::CUSTOMERS),
            Tables::name(Tables::PROVIDERS),
            Tables::name(Tables::PROGRAMS),
            ...$scopeParams,
        ];
        $conditions = ['1 = 1' . $clause];

        if ($status !== '') {
            $conditions[] = 'c.status = %s';
            $params[]     = $status;
        }

        if ($term !== '') {
            $like = '%' . $wpdb->esc_like($term) . '%';

            // See CustomerRepository::search() — the ΑΦΜ is matched both as a
            // column and as its hash, because it may be stored either way.
            $conditions[] = '( cu.first_name LIKE %s OR cu.last_name LIKE %s OR cu.company_name LIKE %s'
                . ' OR cu.afm LIKE %s OR c.supply_number LIKE %s OR c.code LIKE %s'
                . ' OR cu.' . CustomerFields::INDEX_COLUMN . ' = %s )';
            $params       = [...$params, $like, $like, $like, $like, $like, $like, $this->fields->index($term)];
        }

        $where = implode(' AND ', $conditions);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.status, c.energy_type, c.category, c.invoice_code,
                        c.supply_number, c.created_at, c.updated_at, c.partner_user_id,
                        c.signed_at, c.extra_json,
                        p.name AS provider_name, p.slug AS provider_slug,
                        p.logo_url AS provider_logo, g.name AS program_name,
                        cu.first_name, cu.last_name, cu.company_name, cu.afm, cu.phone
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 LEFT JOIN %i g  ON g.id  = c.program_id
                 WHERE {$where}
                 ORDER BY c.updated_at DESC
                 LIMIT " . max(1, $limit),
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $this->fields->fromStorageAll($rows);
    }

    /**
     * Συμβάσεις που έχουν τουλάχιστον ένα ανεβασμένο έγγραφο -- για την οθόνη
     * «Έγγραφα» (243), που δείχνει ΤΙ έχει ανέβει και τι έχει διαβαστεί, όχι
     * μόνο τι λείπει (αυτό το κάνει ήδη το ECRM_Notifications::missing_docs_for()).
     *
     * Κανένα φίλτρο κατάστασης σκόπιμα: ο ιδιοκτήτης ζήτησε ρητά έλεγχο "σε
     * ό,τι αίτηση υπάρχει" -- μια ολοκληρωμένη σύμβαση κρατά τα χαρτιά της
     * και αξίζει τον ίδιο έλεγχο με μια ξεχασμένη routed. Τα φίλτρα-chips της
     * ίδιας οθόνης καλύπτουν "μόνο ελλιπή"/"μόνο εκκρεμή AI" πάνω σε αυτή τη
     * λίστα -- εδώ φεύγει μόνο ό,τι δεν έχει ΚΑΝΕΝΑ αρχείο.
     *
     * @return list<array<string, mixed>>
     */
    public function withDocuments(UserScope $scope, int $limit = 200): array
    {
        global $wpdb;

        [$clause, $scopeParams] = ScopeClause::forScope($scope, 'c');

        $params = [
            $this->table,
            Tables::name(Tables::CUSTOMERS),
            ...$scopeParams,
            Tables::name(Tables::FILES),
        ];

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.status, c.activation_type, c.energy_type, c.updated_at,
                        cu.first_name, cu.last_name, cu.company_name
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 WHERE 1 = 1{$clause}
                   AND EXISTS (SELECT 1 FROM %i f WHERE f.contract_id = c.id)
                 ORDER BY c.updated_at DESC
                 LIMIT " . max(1, $limit),
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * The top bar's global search: a few best matches across code, supply
     * number, customer name, ΑΦΜ and mobile.
     *
     * @return list<array<string, mixed>>
     */
    public function quickSearch(UserScope $scope, string $term, int $limit = 15): array
    {
        global $wpdb;

        if ($term === '') {
            return [];
        }

        [$clause, $scopeParams] = ScopeClause::forScope($scope, 'c');
        $like                   = '%' . $wpdb->esc_like($term) . '%';

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.status, c.supply_number,
                        cu.first_name, cu.last_name, cu.company_name, cu.afm,
                        p.name AS provider_name
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 WHERE ( c.code LIKE %s OR c.supply_number LIKE %s
                         OR cu.first_name LIKE %s OR cu.last_name LIKE %s
                         OR cu.company_name LIKE %s OR cu.afm LIKE %s
                         OR cu.mobile LIKE %s
                         OR cu." . CustomerFields::INDEX_COLUMN . " = %s ){$clause}
                 ORDER BY c.updated_at DESC
                 LIMIT " . max(1, $limit),
                [
                    $this->table,
                    Tables::name(Tables::CUSTOMERS),
                    Tables::name(Tables::PROVIDERS),
                    $like, $like, $like, $like, $like, $like, $like,
                    $this->fields->index($term),
                    ...$scopeParams,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $this->fields->fromStorageAll($rows);
    }

    /**
     * How many contracts sit in each status, for the filter tabs.
     *
     * A status with no rows is absent from the result rather than present as
     * zero: it is a GROUP BY, and there is no row to group. Callers coalesce.
     *
     * @return array<string, int>
     */
    public function countsByStatus(UserScope $scope): array
    {
        global $wpdb;

        [$clause, $params] = ScopeClause::forScope($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS total FROM %i WHERE 1 = 1{$clause} GROUP BY status",
                [$this->table, ...$params]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Contracts whose term ends within the given window.
     *
     * Drafts and cancellations are excluded: neither is up for renewal.
     *
     * @return list<array<string, mixed>>
     */
    public function expiring(UserScope $scope, int $withinDays): array
    {
        global $wpdb;

        $customers = Tables::name(Tables::CUSTOMERS);
        $providers = Tables::name(Tables::PROVIDERS);

        [$clause, $params] = ScopeClause::forScope($scope, 'c');

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.status, c.end_date, c.term_months,
                        DATEDIFF(c.end_date, NOW()) AS days_left,
                        p.name AS provider_name, p.logo_url AS provider_logo,
                        cu.first_name, cu.last_name, cu.company_name, cu.phone
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 WHERE c.end_date IS NOT NULL
                   AND c.status NOT IN ('cancelled', 'draft')
                   AND DATEDIFF(c.end_date, NOW()) <= %d{$clause}
                 ORDER BY c.end_date ASC
                 LIMIT 300",
                [$this->table, $customers, $providers, $withinDays, ...$params]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * Contracts already on file for a ΑΦΜ or supply number — across the whole
     * company, on purpose.
     *
     * The one query here that ignores scope, and it has to. A second
     * application for a supply another partner already signed is exactly the
     * collision worth warning about, and scoping the search would hide it. The
     * caller masks what it returns: outside the actor's scope, only the fact of
     * a clash is disclosed, never the customer or the colleague.
     *
     * @return list<array<string, mixed>>
     */
    public function possibleDuplicates(string $afm, string $supply, int $excludeId = 0): array
    {
        global $wpdb;

        $match  = [];
        $params = [
            $this->table,
            Tables::name(Tables::CUSTOMERS),
            Tables::name(Tables::PROVIDERS),
        ];

        if (strlen($afm) >= 9) {
            // The hash rather than the column: randomised encryption means the
            // same ΑΦΜ never equals itself, and a duplicate check that quietly
            // stops matching reads as "no duplicate exists".
            $match[]  = 'cu.' . CustomerFields::INDEX_COLUMN . ' = %s';
            $params[] = $this->fields->index($afm);
        }

        if ($supply !== '') {
            $match[]  = 'c.supply_number = %s';
            $params[] = $supply;
        }

        if ($match === []) {
            return [];
        }

        $where = '( ' . implode(' OR ', $match) . ' )';

        if ($excludeId > 0) {
            $where   .= ' AND c.id <> %d';
            $params[] = $excludeId;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.status, c.supply_number, c.partner_user_id,
                        cu.first_name, cu.last_name, cu.company_name, cu.afm,
                        p.name AS provider_name
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 WHERE {$where}
                 ORDER BY c.updated_at DESC
                 LIMIT 8",
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $this->fields->fromStorageAll($rows);
    }
}
