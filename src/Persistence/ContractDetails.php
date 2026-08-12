<?php

/**
 * One contract, joined with what renders it — and the only copy of that join.
 *
 * ## Why this is a class of its own
 *
 * The join below selects the customer's encrypted columns, and the line that
 * closes it — `fromStorage()` on both the customer's columns and the extras bag
 * — is what turns stored ciphertext back into a ΑΦΜ. It had been hand-copied
 * four times across the codebase. Three of those copies forgot that line, and
 * the result was a provider receiving `ecrm1:…` where the tax number belongs:
 * the three leaks closed on 2026-08-10.
 *
 * Sharing the query was the fix. Giving it a class whose entire job is to be
 * the single copy is the part that keeps the fix: there is now nowhere else for
 * a fifth version to look like it belongs.
 *
 * ## Two of these take no UserScope
 *
 * `forDocument()` and `noticeSubject()` run on behalf of nobody — from cron, or
 * for an anonymous customer following a signing link. The policy that admits
 * them, and the test any future addition has to pass, is in ARCHITECTURE.md
 * under «Αναγνώσεις χωρίς actor». It is written once, there, rather than in the
 * header of every class that holds one.
 *
 * `findDetailed()` is the scoped entry point and is the one screens use.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;

final class ContractDetails
{
    private string $table;

    private CustomerFields $fields;

    private ContractFields $extras;

    public function __construct(
        ?string $table = null,
        ?CustomerFields $fields = null,
        ?ContractFields $extras = null,
    ) {
        $this->table  = $table ?? Tables::name(Tables::CONTRACTS);
        $this->fields = $fields ?? CustomerFields::default();
        $this->extras = $extras ?? ContractFields::default();
    }

    /**
     * A single contract joined with everything the detail view renders.
     *
     * @return array<string, mixed>|null
     */
    public function findDetailed(int $contractId, UserScope $scope): ?array
    {
        [$clause, $params] = ScopeClause::forScope($scope, 'c');

        return $this->detailed($contractId, $clause, $params);
    }

    /**
     * The contract as the document builder needs it: everything the provider's
     * form prints, decrypted, with no actor to scope it to.
     *
     * Identical to findDetailed() but for the missing ownership clause — the
     * same query, the same translation back out of storage. That is the point
     * of it existing rather than the raw SQL it replaced: the stored form and
     * the downloaded one are now filled from the same row, read the same way.
     *
     * Its two REST callers resolve the contract through findDetailed() first, so
     * the scope check happens where there is somebody to check.
     *
     * @return array<string, mixed>|null
     */
    public function forDocument(int $contractId): ?array
    {
        return $this->detailed($contractId, '', []);
    }

    /**
     * The five columns an in-app notice needs: who owns the contract, what it
     * is called, and what to call the customer.
     *
     * Deliberately not forDocument(): this runs on every signature and every
     * document upload, which is a hot path at 20-40 concurrent requests, and
     * that one joins providers and programs to print a form.
     *
     * It still goes through fromStorage(). None of these columns is in
     * CustomerFields::ENCRYPTED today, so the call is a no-op — which is the
     * reason to make it now rather than later. The day a name or a company name
     * is encrypted, every read that went through here keeps working and only
     * the ones that skipped it start printing `ecrm1:…` into the bell. That is
     * exactly how the three leaks closed on 2026-08-10 came about.
     *
     * All three of its callers are the customer — uploading through a tracking
     * link, or signing — so there is nobody to scope to. Scoping it to the
     * *recipient* would be backwards, since working out the recipient is what
     * the read is for.
     *
     * @return array<string, mixed>|null
     */
    public function noticeSubject(int $contractId): ?array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT c.code, c.partner_user_id,
                        cu.first_name, cu.last_name, cu.company_name
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 WHERE c.id = %d',
                [$this->table, Tables::name(Tables::CUSTOMERS), $contractId]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $row ? $this->fields->fromStorage($row) : null;
    }

    /**
     * The join, with the ownership clause left to the caller.
     *
     * Private on purpose: a caller that could pass its own clause could pass an
     * empty one, and the difference between the scoped and unscoped entry points
     * above is the only place that decision is allowed to be made.
     *
     * @param list<mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function detailed(int $contractId, string $clause, array $params): ?array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.*, p.name AS provider_name, g.name AS program_name, g.code AS program_code,
                        cu.first_name, cu.last_name, cu.father_name, cu.company_name,
                        cu.afm, cu.doy, cu.adt, cu.birth_date, cu.region, cu.city,
                        cu.street, cu.street_no, cu.postal_code, cu.phone, cu.mobile, cu.email
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 LEFT JOIN %i g  ON g.id  = c.program_id
                 WHERE c.id = %d{$clause}",
                [
                    $this->table,
                    Tables::name(Tables::CUSTOMERS),
                    Tables::name(Tables::PROVIDERS),
                    Tables::name(Tables::PROGRAMS),
                    $contractId,
                    ...$params,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $row ? $this->extras->fromStorage($this->fields->fromStorage($row)) : null;
    }
}
