<?php

/**
 * POST   /contracts/{id}/status  move a contract along the pipeline
 * DELETE /contracts/{id}         remove it, documents included
 *
 * The transition itself belongs to ContractLifecycle, which owns the event log,
 * the in-app notification and the SMS. This controller decides whether the
 * caller may ask; the lifecycle decides what asking means.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Docs;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Contract\CancellationGate;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Domain\Contract\DeletionGate;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Infrastructure\DraftExitGate;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ContractStatusController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly FileRepository $files,
        private readonly ContractLifecycle $lifecycle,
        private readonly DraftExitGate $draftExit,
        private readonly CancellationGate $cancellation,
        private readonly DeletionGate $deletion,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/status', [
            'methods'             => 'POST',
            'callback'            => [$this, 'change'],
            'permission_callback' => Guards::needs(Capability::CHANGE_STATUS),
            'args'                => [
                'id'     => ['type' => 'integer', 'required' => true],
                'status' => [
                    'type'     => 'string',
                    'required' => true,
                    'enum'     => array_column(ContractStatus::cases(), 'value'),
                ],
                'message' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'destroy'],
            'permission_callback' => Guards::needs(Capability::DELETE_CONTRACT),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function change(WP_REST_Request $request): WP_REST_Response
    {
        $scope   = $this->scopes->forCurrentUser();
        $id      = (int) $request['id'];
        $target  = ContractStatus::from((string) $request['status']);
        $current = $this->contracts->find($id, $scope);

        if ($current === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $from = (string) $current['status'];

        if ($from === $target->value) {
            return new WP_REST_Response(['ok' => true, 'status' => $target->value], 200);
        }

        $source = ContractStatus::tryFromSlug($from);

        if ($source !== null && ! $source->canMoveTo($target)) {
            return new WP_REST_Response([
                'ok'      => false,
                'error'   => sprintf(
                    'Δεν επιτρέπεται μετάβαση από «%s» σε «%s».',
                    $source->label(),
                    $target->label()
                ),
                'allowed' => array_map(
                    static fn (ContractStatus $s): array => ['status' => $s->value, 'label' => $s->label()],
                    $source->allowedNext()
                ),
            ], 409);
        }

        // 2026-08-24: αυτό το endpoint δεχόταν "signed" σαν οποιαδήποτε άλλη
        // κατάσταση — ένας πράκτορας με δικαίωμα CHANGE_STATUS μπορούσε να
        // δηλώσει «Υπογράφηκε» με μια απλή αλλαγή dropdown, χωρίς ο πελάτης
        // να έχει υπογράψει ποτέ πραγματικά. Η μόνη νόμιμη πηγή μιας
        // υπογραφής είναι η δημόσια σελίδα παρακολούθησης (rest_sign() στο
        // class-ecrm-tracking.php), που γράφει signed_at/signed_ip. Αν αυτά
        // λείπουν, η μετάβαση σε Signed αρνείται εδώ — πριν καν φτάσει στο
        // ContractLifecycle — όσο επιτρεπτή κι αν είναι στον γράφο.
        // Η αντίστροφη περίπτωση (already-Signed → PendingSignature για
        // επανα-υπογραφή) δεν επηρεάζεται: αυτή περνάει από το
        // SignLinkController, δεν φτάνει ποτέ target=Signed εδώ.
        if ($target === ContractStatus::Signed && empty($current['signed_at'])) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Η κατάσταση «Υπογράφηκε» δεν μπαίνει χειροκίνητα — '
                    . 'μόνο μέσω πραγματικής υπογραφής πελάτη από τον σύνδεσμο παρακολούθησης.',
            ], 409);
        }

        // Η ακύρωση σύμβασης που υπήρξε ενεργή. Ο γράφος από πάνω δεν το
        // πιάνει: απαγορεύει μόνο το απευθείας Ενεργή → Ακυρώθηκε, ενώ η
        // διαδρομή Ενεργή → Εκκρεμότητα → Ακυρώθηκε περνούσε. 409 και όχι 422,
        // επειδή δεν λείπει κάτι που μπορεί να συμπληρωθεί: η μετάβαση δεν
        // υπάρχει για αυτή τη σύμβαση.
        $wasActive = $source === null
            ? null
            : $this->cancellation->refusalOnMove($source, $target, $id);

        if ($wasActive !== null) {
            return new WP_REST_Response(['ok' => false, 'error' => $wasActive], 409);
        }

        // The second door out of draft, and it has to ask what the save route
        // asks. A draft may not be sent for signature — or anywhere else except
        // the bin — without an ΑΦΜ, or the provider's form prints with the box
        // empty and goes to the customer that way.
        $missingAfm = $source === null ? null : $this->draftExit->refusalOnMove(
            $source,
            $target,
            (int) ($current['customer_id'] ?? 0),
            $scope
        );

        if ($missingAfm !== null) {
            return new WP_REST_Response(['ok' => false, 'error' => $missingAfm, 'field' => 'afm'], 422);
        }

        // Documents gate: some statuses may not be entered with paperwork missing.
        if (in_array($target->value, ECRM_Docs::gate_statuses(), true)) {
            $missing = ECRM_Docs::missing_labels(
                $id,
                (string) ($current['activation_type'] ?? ''),
                (string) ($current['energy_type'] ?? '')
            );

            if ($missing) {
                return new WP_REST_Response([
                    'ok'      => false,
                    'error'   => 'Λείπουν δικαιολογητικά: ' . implode(', ', $missing),
                    'missing' => $missing,
                ], 422);
            }
        }

        $this->lifecycle->moveTo($id, $target->value, [
            'user_id' => $scope->actorId(),
            'from'    => $from,
            'message' => (string) $request['message'] ?: null,
        ]);

        return new WP_REST_Response(['ok' => true, 'status' => $target->value], 200);
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();
        $id    = (int) $request['id'];

        if (! $this->contracts->exists($id, $scope)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        // build queue 15: μια υπογεγραμμένη σύμβαση δεν διαγράφεται ποτέ — θα
        // παρέσερνε και τον φάκελο υπογραφής μέσω του foreign key. Δες
        // DeletionGate.
        $refusal = $this->deletion->refusalOnDelete($id);

        if ($refusal !== null) {
            return new WP_REST_Response(['ok' => false, 'error' => $refusal], 409);
        }

        /*
         * Τρία βήματα, όχι δύο -- ίδια σειρά με τη μαζική διαγραφή.
         *
         * Η σειρά «bytes πρώτα» που υπήρχε εδώ έλυνε ένα πραγματικό ρίσκο: το
         * `files.contract_id` είναι `ON DELETE CASCADE`, οπότε αν έφευγε πρώτη
         * η σύμβαση, οι γραμμές εξαφανίζονταν χωρίς να αγγιχτεί ο δίσκος και
         * τα αρχεία έμεναν ορφανά (81 τέτοια μετρήθηκαν κάποτε -- δες
         * `ContractDeleteBytesTest`).
         *
         * Έλυνε όμως το ένα ρίσκο δημιουργώντας το αντίθετο: αν αποτύγχανε η
         * διαγραφή της σύμβασης, τα σαρωμένα δελτία ταυτότητας ήταν **ήδη
         * σβησμένα, οριστικά**, για σύμβαση που επέζησε. Το πρωτότυπο δεν
         * υπήρξε ποτέ αλλού.
         *
         * Η μαζική διαδρομή το διόρθωσε στις 18/08 (CHANGELOG (31), εύρημα 19)
         * με τρία βήματα -- **η μονή διαδρομή έμεινε πίσω** και κρατούσε το
         * παλιό σφάλμα δέκα μέρες. Στιγμιότυπο, διαγραφή, bytes μετά.
         */
        $doomed = $this->files->recordsForContracts([$id]);

        if (! $this->contracts->delete($id, $scope)) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Η διαγραφή απέτυχε· τα έγγραφα δεν πειράχτηκαν.'],
                500
            );
        }

        // Οι γραμμές έφυγαν με το CASCADE -- αν το foreign key υπάρχει. Αν δεν
        // εφαρμόστηκε ποτέ (το AddForeignKeys καταγράφει και προσπερνά), αυτό
        // τις καθαρίζει. Και στις δύο περιπτώσεις τα bytes φεύγουν παρακάτω.
        $this->files->purgeForContracts([$id]);
        $this->files->forgetBytes($doomed);

        return new WP_REST_Response(['ok' => true], 200);
    }
}
