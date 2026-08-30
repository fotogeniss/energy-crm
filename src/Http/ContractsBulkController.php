<?php

/**
 * POST /contracts/bulk — one endpoint, four operations.
 *
 * Which capability applies depends on what the request asked for, so it cannot
 * be settled by the route: `permission_callback` establishes the floor and each
 * branch checks its own. Splitting these into four endpoints would be tidier,
 * but the UI sends one selection and one action, and changing that contract is
 * a separate job from moving the code.
 *
 * Whatever ids arrive, only those the actor may reach are acted on — a stale
 * selection loses the rows it should not have had, not the whole batch.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Docs;
use ECRM_Export;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Domain\Contract\DeletionGate;
use EnergyCRM\Infrastructure\DraftExitGate;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ContractsBulkController implements Controller
{
    /** Which capability each operation needs. */
    private const REQUIRES = [
        'status' => Capability::CHANGE_STATUS,
        'delete' => Capability::DELETE_CONTRACT,
        'assign' => Capability::ASSIGN_CONTRACT,
        'export' => Capability::EXPORT_DATA,
    ];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly FileRepository $files,
        private readonly ContractLifecycle $lifecycle,
        private readonly DeletionGate $deletion,
        private readonly DraftExitGate $draftExit,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/bulk', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'ids' => [
                    'type'     => 'array',
                    'required' => true,
                    'items'    => ['type' => 'integer'],
                ],
                'action' => [
                    'type'     => 'string',
                    'required' => true,
                    'enum'     => ['status', 'delete', 'assign', 'export'],
                ],
                // Πολύμορφο εξ ορισμού: slug κατάστασης για το 'status',
                // id χρήστη για το 'assign', αχρησιμοποίητο για τα άλλα δύο.
                //
                // Δηλωνόταν σκέτο string, και το ecrm-view-contracts.js στέλνει
                // `value: +v` — ΑΡΙΘΜΟ. Ο validator του WP απορρίπτει αριθμό σε
                // πεδίο string, οπότε η μαζική «Ανάθεση» γύριζε 400 και ΔΕΝ
                // δούλεψε ποτέ. Καμία εξαίρεση στο PHP log: το 400 φεύγει πριν
                // φτάσει στον handler.
                //
                // Διορθώνεται εδώ και όχι στη JS, επειδή η ίδια η handle() κάνει
                // `(int) $request['value']` για το assign: η γραμμή αυτή λέει
                // ότι το πεδίο κουβαλάει αριθμό, και ένα schema που τον
                // απαγορεύει διαφωνεί με τον κώδικα που το διαβάζει. Αλλαγή
                // μόνο στη JS θα άφηνε το API να απορρίπτει απολύτως λογικό
                // payload, και ο επόμενος client θα το ξαναέτρωγε.
                'value' => ['type' => ['string', 'integer'], 'default' => ''],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $action = (string) $request['action'];
        $scope  = $this->scopes->forCurrentUser();

        if (! current_user_can(self::REQUIRES[$action])) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Δεν έχεις δικαίωμα για αυτή την ενέργεια.'],
                403
            );
        }

        $rows = $this->contracts->reachableAmong(
            array_map('intval', (array) $request['ids']),
            $scope
        );

        if ($rows === []) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Καμία προσβάσιμη σύμβαση.'], 403);
        }

        return match ($action) {
            'status' => $this->changeStatus($rows, (string) $request['value'], $scope),
            'delete' => $this->delete($rows, $scope),
            'assign' => $this->assign($rows, (int) $request['value'], $scope),
            'export' => $this->export($rows, $scope),
            // Unreachable: the route's enum rejects anything else before we get
            // here. Spelled out anyway, because a future action added to the
            // schema and forgotten here should fail loudly, not fall through.
            default  => new WP_REST_Response(
                ['ok' => false, 'error' => 'Άγνωστη ενέργεια.'],
                400
            ),
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function changeStatus(array $rows, string $to, UserScope $scope): WP_REST_Response
    {
        $actorId = $scope->actorId();
        $target = ContractStatus::tryFromSlug($to);

        if ($target === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Μη έγκυρη κατάσταση.'], 400);
        }

        // Ίδια πύλη με το ContractStatusController::change(), 2026-08-24: η
        // μοναδική νόμιμη πηγή μιας υπογραφής είναι ο πελάτης, στη σελίδα
        // παρακολούθησης. Μια μαζική ενέργεια που δηλώνει δεκάδες συμβάσεις
        // «Υπογράφηκαν» με ένα κλικ θα ήταν ακριβώς αυτό που πρέπει να μην
        // υπάρχει — αρνείται ολόκληρη, δεν παραλείπει σιωπηλά ανά γραμμή.
        if ($target === ContractStatus::Signed) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Η κατάσταση «Υπογράφηκε» δεν μπαίνει χειροκίνητα, ούτε μαζικά — '
                    . 'μόνο μέσω πραγματικής υπογραφής πελάτη.',
            ], 409);
        }

        $gated     = in_array($target->value, ECRM_Docs::gate_statuses(), true);
        $updated   = 0;
        $skipped   = 0;
        $rejected  = [];
        $missingAfm = 0;

        foreach ($rows as $row) {
            $id   = (int) $row['id'];
            $from = (string) $row['status'];

            if ($from === $target->value) {
                continue;
            }

            if ($gated) {
                $missingDocs = ECRM_Docs::missing_labels(
                    $id,
                    (string) ($row['activation_type'] ?? ''),
                    (string) ($row['energy_type'] ?? '')
                );

                if ($missingDocs) {
                    $skipped++;
                    continue;
                }
            }

            $source = ContractStatus::tryFromSlug($from);

            // The pipeline may refuse the move; report that rather than count
            // it as done, which is what the old code did.
            if ($source !== null && ! $source->canMoveTo($target)) {
                $rejected[] = $source->label();
                continue;
            }

            // Το ίδιο πηγαίο ΑΦΜ που ζητά το ContractSaveController και το
            // ContractStatusController -- η μαζική ενέργεια περνούσε από
            // εδώ χωρίς ποτέ να ρωτήσει, οπότε ένα πρόχειρο χωρίς ΑΦΜ έβγαινε
            // από το draft με ένα κλικ σε μια μαζική επιλογή, ακριβώς αυτό
            // που το gate υπάρχει για να εμποδίζει στα άλλα δύο σημεία.
            $missingAfmReason = $source === null ? null : $this->draftExit->refusalOnMove(
                $source,
                $target,
                (int) ($row['customer_id'] ?? 0),
                $scope
            );

            if ($missingAfmReason !== null) {
                $missingAfm++;
                continue;
            }

            // Ποιος το έκανε, και όχι ποιανού είναι. Το πεδίο κουβαλούσε τον
            // κάτοχο επειδή η ειδοποίηση το χρησιμοποιούσε ως παραλήπτη· τώρα
            // η ειδοποίηση βρίσκει μόνη της τον κάτοχο από τη σύμβαση, οπότε
            // το ιστορικό μπορεί επιτέλους να γράψει την αλήθεια: τη μαζική
            // αλλαγή την έκανε ο διαχειριστής, όχι ο συνεργάτης.
            $moved = $this->lifecycle->moveTo($id, $target->value, [
                'user_id' => $actorId,
                'from'    => $from,
                'message' => 'Μαζική αλλαγή κατάστασης',
            ]);

            $moved ? $updated++ : $skipped++;
        }

        $response = ['ok' => true, 'updated' => $updated, 'skipped' => $skipped];

        if ($rejected !== []) {
            $response['rejected'] = count($rejected);
            $response['notice']   = sprintf(
                '%d σύμβαση/εις δεν άλλαξαν: δεν επιτρέπεται μετάβαση από «%s» σε «%s».',
                count($rejected),
                implode('», «', array_unique($rejected)),
                $target->label()
            );
        }

        if ($missingAfm > 0) {
            $response['missing_afm'] = $missingAfm;
            $response['notice']      = trim(($response['notice'] ?? '') . sprintf(
                ' %d σύμβαση/εις δεν άλλαξαν: χρειάζονται ΑΦΜ πελάτη για να βγουν από το πρόχειρο.',
                $missingAfm
            ));
        }

        return new WP_REST_Response($response, 200);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function delete(array $rows, UserScope $scope): WP_REST_Response
    {
        // build queue 15: μια υπογεγραμμένη σύμβαση δεν διαγράφεται ποτέ σε
        // καμία διαδρομή — bulk ή μεμονωμένη (δες ContractStatusController::
        // destroy() για το ίδιο). Ό,τι πέρασε το scope φιλτράρεται εδώ ξανά,
        // ένα-ένα· ίδιο μοτίβο με το `rejected` της changeStatus() παραπάνω.
        $ids     = [];
        $blocked = 0;

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            if ($this->deletion->refusalOnDelete($id) !== null) {
                $blocked++;
                continue;
            }

            $ids[] = $id;
        }

        if ($ids === []) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Καμία σύμβαση δεν διαγράφηκε: ' . DeletionGate::WAS_SIGNED,
            ], 409);
        }

        /*
         * Η σειρά είχε τα αρχεία πρώτα, και ο λόγος ήταν σωστός: το CASCADE
         * σβήνει τις γραμμές του `files` χωρίς να αγγίξει τον δίσκο, οπότε αν
         * έφευγε πρώτη η σύμβαση, τα bytes έμεναν χωρίς τίποτα να τα δείχνει.
         *
         * Το κόστος όμως ήταν ότι μια αποτυχημένη διαγραφή σύμβασης άφηνε τα
         * σαρωμένα δελτία ταυτότητας ΗΔΗ σβησμένα, οριστικά, για σύμβαση που
         * επέζησε. Το πρωτότυπο δεν υπήρξε ποτέ αλλού.
         *
         * Τρία βήματα αντί για δύο: στιγμιότυπο, διαγραφή, και τα bytes μετά.
         */
        $doomed  = $this->files->recordsForContracts($ids);
        $removed = $this->contracts->deleteMany($ids, $scope);

        if ($removed === 0) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Καμία σύμβαση δεν διαγράφηκε· τα έγγραφα δεν πειράχτηκαν.'],
                500
            );
        }

        // Οι γραμμές έφυγαν με το CASCADE — αν το foreign key υπάρχει. Αν δεν
        // εφαρμόστηκε ποτέ (το AddForeignKeys καταγράφει και προσπερνά), αυτό
        // τις καθαρίζει. Και στις δύο περιπτώσεις τα bytes φεύγουν παρακάτω.
        $this->files->purgeForContracts($ids);
        $this->files->forgetBytes($doomed);

        $response = ['ok' => true, 'updated' => $removed];

        if ($blocked > 0) {
            $response['rejected'] = $blocked;
            $response['notice']   = sprintf(
                '%d σύμβαση/εις δεν διαγράφηκαν: υπήρξαν υπογεγραμμένες.',
                $blocked
            );
        }

        return new WP_REST_Response($response, 200);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function assign(array $rows, int $newOwner, UserScope $scope): WP_REST_Response
    {
        if ($newOwner <= 0 || ! $scope->includes($newOwner)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Μη επιτρεπτή ανάθεση.'], 403);
        }

        $moved = 0;

        // One at a time through reassign(), which re-checks both the contract
        // and the new owner. Reassignment moves commission, so the extra query
        // per row is worth more than the batch statement it replaces.
        foreach ($rows as $row) {
            if ($this->contracts->reassign((int) $row['id'], $newOwner, $scope)) {
                $moved++;
            }
        }

        return new WP_REST_Response(['ok' => true, 'updated' => $moved], 200);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function export(array $rows, UserScope $scope): WP_REST_Response
    {
        if (! class_exists('ZipArchive')) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Λείπει η επέκταση ZipArchive.'],
                500
            );
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);

        // Τα $ids έχουν ήδη περάσει από reachableAmong(), οπότε το δεύτερο
        // φίλτρο είναι ζώνη και τιράντες. Με $scope->userIds() όμως έσφιγγε
        // λάθος: για διαχειριστή σημαίνει «μόνο εγώ», και η μαζική εξαγωγή
        // επέστρεφε άδειο αρχείο για συμβάσεις που μόλις είχε επιλέξει.
        $data = ECRM_Export::contracts_dataset('', '', $ids, $this->scopes->visibleUserIds($scope));

        return new WP_REST_Response([
            'ok'       => true,
            'b64'      => base64_encode(ECRM_Export::build_xlsx($data['headers'], $data['rows'])),
            'filename' => 'symvaseis-epilogi-' . gmdate('Ymd-Hi') . '.xlsx',
            'mime'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'count'    => count($data['rows']),
        ], 200);
    }
}
