<?php

/**
 * The SQL fragment that restricts rows to what an actor may see.
 *
 * ## Why this is a class and not a method on each repository
 *
 * It was a private method on ContractRepository, used by ten of its queries.
 * Splitting that class up left two options: copy the method into each new
 * piece, or give it a home of its own. Copying is how the same repository
 * ended up with four hand-written versions of one join, three of which forgot
 * the line that decrypts the ΑΦΜ — see ContractRepository::detailed(). A
 * duplicated authorization clause is the same mistake wearing better clothes:
 * a second place to get it subtly wrong, and no test that notices because each
 * copy passes on its own.
 *
 * So there is one copy, here, and anything that scopes a query against
 * partner_user_id goes through it.
 *
 * It lives in Persistence rather than Access because it emits SQL, and Access
 * is not allowed to know that SQL exists.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;

final class ScopeClause
{
    private function __construct()
    {
    }

    /**
     * The fragment and its bound values, ready to append to a WHERE clause.
     *
     * Administrators get an empty fragment — the hierarchy decides commission,
     * not the right to look. Everyone else gets an IN list that UserScope
     * guarantees is non-empty, so the clause can never degrade into one that
     * matches every row.
     *
     * The fragment opens with ' AND ', which means every caller has to have
     * said something first. `WHERE 1 = 1` is what most of them say, and that is
     * why: it makes the append unconditional instead of a branch each query
     * gets to write differently.
     *
     * @return array{0: string, 1: list<int>}
     */
    public static function forScope(UserScope $scope, string $alias = ''): array
    {
        if ($scope->isAdministrator()) {
            return ['', []];
        }

        $column = ($alias === '' ? '' : $alias . '.') . 'partner_user_id';

        return [
            ' AND ' . $column . ' IN (' . $scope->placeholders() . ')',
            $scope->userIds(),
        ];
    }
}
