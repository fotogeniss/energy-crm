<?php

/**
 * What a contract needs before it is allowed to leave the draft stage.
 *
 * Today that is one thing — the customer's ΑΦΜ — and the rule exists because of
 * what encryption left behind. Duplicate detection and search by full ΑΦΜ both
 * run on the `afm_hash` blind index and on nothing else, so a customer stored
 * without an ΑΦΜ is a customer who can never be flagged as a duplicate. On the
 * development database that is 38 of 41 rows, which is harmless there and would
 * not be in production.
 *
 * ## Why this is a class and not two `if`s
 *
 * A draft has three ways forward — `new`, `pending_signature`,
 * `awaiting_signature` — and two endpoints that can take them:
 * ContractSaveController, where the form's Οριστικοποίηση button lands, and
 * ContractStatusController, where the status screen does. Four of those six
 * combinations are easy to forget, and a rule enforced in five places out of
 * six is not a rule.
 *
 * That is not a hypothetical. On 2026-08-16, POST /contracts turned out to be
 * the one route of three that wrote `status` without consulting the transition
 * graph, and the fix for it (CHANGELOG (10)) was written the same day this
 * class was. Putting the ΑΦΜ check inline in the same controller would have
 * repeated the shape of the bug that had just been closed.
 *
 * ## Cancelling is always allowed
 *
 * A draft that cannot be completed is exactly the one an agent needs to be able
 * to abandon. Requiring an ΑΦΜ in order to throw something away would trap
 * incomplete work on the screen forever.
 *
 * ## Not retroactive, and it does not need to be
 *
 * The gate guards the exit from draft. A contract already past it does not make
 * that move again, so nothing existing is re-judged. What is affected, on
 * purpose, is a draft with no ΑΦΜ: it stays a draft until one is entered.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\CustomerRepository;

final class DraftExitGate
{
    /** Shown to the agent, and the same sentence from both endpoints. */
    public const MISSING_AFM = 'Χρειάζεται ΑΦΜ πελάτη για να προχωρήσει η αίτηση πέρα από το πρόχειρο.';

    public function __construct(private readonly CustomerRepository $customers)
    {
    }

    /**
     * Refuse a first save that would land past draft, or null to allow it.
     *
     * A contract created directly as `new` has finalised without ever having
     * been a draft, so it faces the same requirement. Creation is kept separate
     * from movement rather than modelled as "no previous status", because an
     * unreadable status in the database is also "no previous status" and the
     * two deserve different answers.
     */
    public function refusalOnCreate(
        ContractStatus $target,
        int $customerId,
        UserScope $scope,
        string $afm = '',
    ): ?string {
        return $this->guards($target) ? $this->refusalFor($customerId, $scope, $afm) : null;
    }

    /**
     * Refuse a transition out of draft, or null to allow it.
     *
     * Anything that does not start from draft is none of this gate's business:
     * the requirement was already answered on the way out, and re-asking it
     * would retroactively freeze contracts that are legitimately in flight.
     */
    public function refusalOnMove(
        ContractStatus $from,
        ContractStatus $target,
        int $customerId,
        UserScope $scope,
        string $afm = '',
    ): ?string {
        if ($from !== ContractStatus::Draft || ! $this->guards($target)) {
            return null;
        }

        return $this->refusalFor($customerId, $scope, $afm);
    }

    /** Every stage past draft except the bin. */
    private function guards(ContractStatus $target): bool
    {
        return $target !== ContractStatus::Draft && $target !== ContractStatus::Cancelled;
    }

    /**
     * The ΑΦΜ this save would end up with: the one being sent if there is one,
     * otherwise the one already stored.
     *
     * Both halves are needed. The form resends the whole customer on every
     * save, so the first covers the screen; but an edit that only moves the
     * status sends no customer fields at all, and refusing it because the
     * request happened not to repeat an ΑΦΜ the database already holds would be
     * a refusal about the payload rather than about the contract.
     *
     * The lookup is scoped, so a customer the actor cannot reach reads as
     * absent — which refuses the move. That is the safe direction.
     */
    private function refusalFor(int $customerId, UserScope $scope, string $afm): ?string
    {
        if (trim($afm) !== '') {
            return null;
        }

        if ($customerId > 0) {
            $customer = $this->customers->find($customerId, $scope);

            if (trim((string) ($customer['afm'] ?? '')) !== '') {
                return null;
            }
        }

        return self::MISSING_AFM;
    }
}
