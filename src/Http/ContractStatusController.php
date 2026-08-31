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
use EnergyCRM\Persistence\DeletionLogRepository;
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
        private readonly DeletionLogRepository $deletionLog,
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

        /*
         * «Ειδική πύλη» admin (build queue #15, docs/CHANGELOG.md 127) --
         * ξεχωριστό route, όχι επέκταση του παραπάνω: δύο handlers στο ίδιο
         * DELETE /contracts/{id} δεν γίνεται, και το κανονικό destroy() δεν
         * πρέπει να αλλάξει καθόλου συμπεριφορά για partner/seller.
         * manage_options -- ίδιο πρότυπο admin-gate με FormCalibrator,
         * HealthPage, class-ecrm-app.php· καμία νέα ecrm_* capability.
         */
        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/force', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'forceDestroy'],
            'permission_callback' => Guards::needs('manage_options'),
            'args'                => [
                'id'     => ['type' => 'integer', 'required' => true],
                'reason' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
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

            // Παρόν δεν σημαίνει έγκυρο -- μια ληγμένη ταυτότητα περνούσε το
            // missing_labels() παραπάνω αφού το είδος υπάρχει, απλά έχει
            // λήξει η ημερομηνία τυπωμένη πάνω της (ECRM_Docs::expired_docs()).
            $expired = ECRM_Docs::expired_docs($id);

            if ($expired) {
                $labels = array_map(static fn (array $e): string => $e['label'], $expired);

                return new WP_REST_Response([
                    'ok'      => false,
                    'error'   => 'Έχει λήξει: ' . implode(', ', $labels),
                    'expired' => $expired,
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
            // 'code' μηχαναγνώσιμο δίπλα στο ελληνικό 'error': το frontend
            // δεν πρέπει να ταιριάζει ελληνικό κείμενο για να αποφασίσει αν
            // θα προσφέρει την «ειδική πύλη» admin.
            return new WP_REST_Response(['ok' => false, 'error' => $refusal, 'code' => 'was_signed'], 409);
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

    /**
     * «Ειδική πύλη» admin: διαγράφει ΚΑΙ μια σύμβαση που υπογράφηκε ποτέ,
     * μόνο για manage_options (permission_callback στο routes()), και μόνο
     * αφού καταγραφεί μόνιμα ποιος/πότε/γιατί στο deletion_log -- βλ.
     * DeletionLogRepository για το γιατί ΔΕΝ ζει αυτό στο events.
     */
    public function forceDestroy(WP_REST_Request $request): WP_REST_Response
    {
        $scope  = $this->scopes->forCurrentUser();
        $id     = (int) $request['id'];
        $reason = trim((string) $request['reason']);

        $current = $this->contracts->find($id, $scope);

        if ($current === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        if ($reason === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'Χρειάζεται αιτιολογία.'], 422);
        }

        // Η πύλη αυτή υπάρχει ΓΙΑ την περίπτωση που το DeletionGate αρνείται.
        // Αν δεν αρνείται (δεν υπογράφηκε ποτέ), δεν γίνεται σιωπηρή
        // συντόμευση για συνηθισμένη διαγραφή -- υπάρχει ήδη το κανονικό
        // DELETE /contracts/{id} γι' αυτό, με το δικό του scope/capability.
        if ($this->deletion->refusalOnDelete($id) === null) {
            return new WP_REST_Response(
                [
                    'ok'    => false,
                    'error' => 'Αυτή η σύμβαση δεν έχει υπογραφεί ποτέ -- χρησιμοποίησε την κανονική διαγραφή.',
                ],
                409
            );
        }

        $actor = wp_get_current_user();

        // Στιγμιότυπο ΠΡΙΝ φύγει οτιδήποτε -- βλ. DeletionLogRepository.
        $this->deletionLog->record(
            $id,
            (string) ($current['code'] ?? ''),
            (string) ($current['status'] ?? ''),
            $reason,
            $scope->actorId(),
            (string) $actor->display_name
        );

        // Ίδια τριβηματη σειρά με το destroy() -- δες το σχόλιο εκεί.
        $doomed = $this->files->recordsForContracts([$id]);

        if (! $this->contracts->delete($id, $scope)) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Η διαγραφή απέτυχε· τα έγγραφα δεν πειράχτηκαν.'],
                500
            );
        }

        $this->files->purgeForContracts([$id]);
        $this->files->forgetBytes($doomed);

        return new WP_REST_Response(['ok' => true], 200);
    }
}
