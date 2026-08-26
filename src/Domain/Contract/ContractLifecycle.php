<?php

/**
 * The one way a contract changes status.
 *
 * Every status change in the system goes through here, so the lifecycle behaves
 * identically wherever it is triggered: the status is written, the event log
 * gains an entry, the agent gets an in-app notification and the customer gets a
 * message. Five callers, one behaviour.
 *
 * Lifted out of ECRM_REST in roadmap step 10. It was never REST's business —
 * cron and the customer-facing tracking page both call it, and neither is a
 * REST request.
 *
 * ## Authorisation is not here
 *
 * `moveTo()` takes a bare contract id and trusts it. That is deliberate and it
 * is why its two database methods live in ContractTransitions, which takes no
 * UserScope: the callers resolve the contract through a scoped read first, and
 * the sweep runs from cron on behalf of nobody at all. The policy admitting
 * them is in ARCHITECTURE.md under «Αναγνώσεις χωρίς actor». What is enforced
 * here is the *pipeline* —
 * which move is legal — not who may make it.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

use ECRM_Messaging;
use ECRM_Notifications;
use EnergyCRM\Persistence\ContractTransitions;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\PayoutRepository;

final class ContractLifecycle
{
    /**
     * Fired after a status actually changed — not when the move was refused,
     * and not when the contract was already in the target status.
     *
     * Arguments: contract id, new status, previous status ('' when unknown).
     * AutoProcess listens for it; that is how signing schedules its own
     * follow-up without the lifecycle needing to know a scheduler exists.
     */
    public const STATUS_CHANGED = 'ecrm_contract_status_changed';

    public function __construct(
        private readonly ContractTransitions $transitions,
        private readonly EventRepository $events,
        private readonly CancellationGate $cancellation,
        private readonly PayoutRepository $payouts,
    ) {
    }

    /**
     * Move a contract to a new status.
     *
     * @param array{
     *     user_id?: int,
     *     from?: string|null,
     *     message?: string|null,
     *     extra?: array<string, mixed>,
     *     inapp?: bool,
     *     sms?: bool,
     *     force?: bool
     * } $options
     *
     * @return bool True when applied, or already there. False when the status
     *              does not exist or the pipeline forbids the move.
     */
    public function moveTo(int $contractId, string $to, array $options = []): bool
    {
        $target = ContractStatus::tryFromSlug($to);

        if ($target === null) {
            return false;
        }

        // A caller that knows the previous status says so; the signing routes
        // pass null because they genuinely do not.
        $from = array_key_exists('from', $options)
            ? (string) $options['from']
            : $this->transitions->statusOf($contractId);

        // Already there. True, because the caller asked for a state and the
        // contract is in it — but nothing is logged, since nothing happened.
        if ($from === $to && empty($options['force'])) {
            return true;
        }

        $current = ContractStatus::tryFromSlug($from);

        // Refuse what the pipeline does not allow: reviving a cancelled
        // contract, or rewinding a signed one past its own signature.
        if ($current !== null && ! $current->canMoveTo($target)) {
            return false;
        }

        // Ο γράφος ξέρει πού είναι η σύμβαση, όχι πού υπήρξε. Η ακύρωση μιας
        // σύμβασης που υπήρξε ενεργή κόβεται εδώ, στο σημείο απ' όπου περνούν
        // και οι τέσσερις διαδρομές — αλλιώς η μαζική ενέργεια και η εισαγωγή
        // Excel θα την επέτρεπαν ενώ οι δύο οθόνες όχι, που είναι ακριβώς ο
        // τρόπος με τον οποίο ένας κανόνας παύει να είναι κανόνας.
        if ($current !== null && $this->cancellation->refusalOnMove($current, $target, $contractId) !== null) {
            return false;
        }

        $this->transitions->applyTransition($contractId, $to, (array) ($options['extra'] ?? []));

        // Μια σύμβαση που μόλις ακυρώθηκε δεν πρέπει να συνεχίσει να μετράει
        // στο σύνολο μιας παρτίδας που δεν έχει πληρωθεί ακόμα -- ίδιο σημείο
        // με το CancellationGate παραπάνω, εύρημα ελέγχου #2 (26/08),
        // επιβεβαιωμένο ρητά από τον ιδιοκτήτη. Παρτίδα ήδη πληρωμένη δεν
        // αγγίζεται: αυτή τη διαδρομή την έχει ήδη κόψει η πύλη λίγες γραμμές
        // πιο πάνω, πριν φτάσουμε ποτέ σε αυτό το σημείο.
        if ($target === ContractStatus::Cancelled) {
            $this->payouts->releaseFromPendingBatch($contractId);
        }

        $this->events->record($contractId, (int) ($options['user_id'] ?? 0), 'status_change', [
            'from_status' => $from,
            'to_status'   => $to,
            'message'     => $options['message'] ?? null,
        ]);

        $this->announce($contractId, $to, $from, $options);

        return true;
    }

    /** The "contract created" entry, written once when a contract first exists. */
    public function logCreation(int $contractId, int $userId, string $status): void
    {
        $this->events->record($contractId, $userId, 'created', [
            'to_status' => $status,
            'message'   => 'Αποθήκευση αίτησης',
        ]);
    }

    /**
     * Everything that happens because the status changed.
     *
     * Both notifications keep their opt-out flags: a bulk action does not want
     * to send fifty texts, and the signing route sends its own richer message
     * instead of the generic one.
     *
     * @param array<string, mixed> $options
     */
    private function announce(int $contractId, string $to, string $from, array $options): void
    {
        if (($options['inapp'] ?? true) && class_exists(ECRM_Notifications::class)) {
            ECRM_Notifications::notify_status_change($contractId, $to);
        }

        if (($options['sms'] ?? true) && class_exists(ECRM_Messaging::class)) {
            ECRM_Messaging::on_status_change($contractId, $to);
        }

        /*
         * The hook name is prefixed: the constant is
         * 'ecrm_contract_status_changed', declared at the top of this class.
         * The sniff cannot read inside a constant, and spelling the literal out
         * here would put the same string in two places — which is exactly the
         * mistake the constant exists to prevent.
         */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
        do_action(self::STATUS_CHANGED, $contractId, $to, $from);
    }
}
