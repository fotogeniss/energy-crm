<?php

/**
 * POST /extract — read a customer's documents and return the fields found.
 *
 * The uploads never touch disk: they are read from the temporary files PHP
 * already holds, sent to the model, and forgotten. Nothing is stored until the
 * agent saves the contract, which is what makes an abandoned extraction leave
 * no identity documents behind.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Extractor;
use ECRM_RateLimit;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Infrastructure\ExtractionGate;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ExtractionController implements Controller
{
    public function __construct(
        private readonly ExtractionGate $gate,
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly FileRepository $files,
        private readonly CustomerRepository $customers,
    ) {
    }

    /**
     * Πεδία της εξαγωγής που ζουν στη ΣΥΜΒΑΣΗ και όχι στον πελάτη.
     *
     * Ό,τι άλλο επιστρέφει ο εξαγωγέας αφορά το πρόσωπο. Ο διαχωρισμός είναι
     * ρητός εδώ ώστε να μη χρειάζεται να τον ξαναβρεί κανείς διαβάζοντας δύο
     * σχήματα πινάκων.
     *
     * @var list<string>
     */
    private const CONTRACT_FIELDS = ['supply_number', 'meter_number', 'invoice_code'];

    /**
     * Τα είδη εγγράφου που έχει νόημα να διαβάσει ο εξαγωγέας.
     *
     * Το prompt του είναι γραμμένο για ταυτότητα και λογαριασμό παρόχου. Ό,τι
     * άλλο κρέμεται από μια αίτηση -- συμπληρωμένο έντυπο, εξουσιοδότηση --
     * δεν προσθέτει πεδία, μόνο κόστος.
     *
     * @var list<string>
     */
    private const EXTRACTABLE_KINDS = ['id_card', 'provider_bill'];

    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf',
    ];

    /** An application needs an ID and a bill; ten is generous. */
    private const MAX_DOCUMENTS = 10;

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/extract', [
            'methods'             => 'POST',
            'callback'            => [$this, 'extract'],
            'permission_callback' => Guards::crmUser(),
        ]);
    }

    public function extract(WP_REST_Request $request): WP_REST_Response
    {
        // Each call costs money and takes seconds; a stuck client should not be
        // able to run up a bill.
        if (! ECRM_RateLimit::allow('extract', 60, 300)) {
            return ECRM_RateLimit::too_many();
        }

        $uploads = $request->get_file_params()['files'] ?? null;

        /*
         * Δεύτερη είσοδος: έγγραφα που είναι ΗΔΗ αποθηκευμένα.
         *
         * Ως τις 27/08 δεχόταν μόνο αρχεία που μόλις ανέβηκαν, και το σχόλιο
         * στην κορυφή του αρχείου εξηγεί γιατί ήταν σωστό: τα έγγραφα δεν
         * άγγιζαν ποτέ τον δίσκο. Ο «σύνδεσμός μου» άλλαξε την πραγματικότητα
         * -- εκεί ο ΠΕΛΑΤΗΣ στέλνει τα χαρτιά του και αποθηκεύονται πριν καν
         * υπάρξει αίτηση. Χωρίς αυτή τη διαδρομή, ο πωλητής έβλεπε άδεια πεδία
         * πάνω από γεμάτο φάκελο και θα έπρεπε να τα ξανανεβάσει με το χέρι.
         *
         * Η ασφάλεια δεν χαλαρώνει: το contract_id ελέγχεται με την εμβέλεια
         * του χρήστη, και οι διαδρομές περνούν από τον έλεγχο περιορισμού του
         * FileRepository πριν διαβαστεί ένα byte.
         */
        if (empty($uploads)) {
            $contractId = (int) $request->get_param('contract_id');

            if ($contractId <= 0) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Δεν ανέβηκαν αρχεία.'], 400);
            }

            $documents = $this->storedDocuments($contractId);

            if ($documents === []) {
                return new WP_REST_Response(
                    ['ok' => false, 'error' => 'Δεν βρέθηκαν έγγραφα πελάτη σε αυτή την αίτηση.'],
                    404
                );
            }
        } else {
            $kinds     = (array) $request->get_param('kinds');
            $documents = [];

            foreach (self::normalise($uploads) as $index => $upload) {
                if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    continue;
                }

                $mime = self::mimeOf($upload);

                if ($mime === null) {
                    continue;
                }

                $documents[] = [
                    'path' => $upload['tmp_name'],
                    'mime' => $mime,
                    'kind' => sanitize_text_field((string) ($kinds[$index] ?? 'other')),
                ];
            }

            if ($documents === []) {
                return new WP_REST_Response(
                    ['ok' => false, 'error' => 'Μη υποστηριζόμενα αρχεία (μόνο PDF/JPG/PNG).'],
                    400
                );
            }
        }

        // Everything above is cheap. Past this line a worker is held for as
        // long as the model takes, so the site's capacity is what decides
        // whether this request runs now or the browser tries again.
        if (! $this->gate->enter()) {
            $response = new WP_REST_Response([
                'ok'          => false,
                'queued'      => true,
                'retry_after' => $this->gate->retryAfter(),
                'error'       => 'Γίνονται ήδη αρκετές εξαγωγές. Θα ξαναδοκιμάσει αυτόματα.',
            ], 503);

            $response->header('Retry-After', (string) $this->gate->retryAfter());

            return $response;
        }

        try {
            $result = ECRM_Extractor::extract(array_slice($documents, 0, self::MAX_DOCUMENTS));
        } finally {
            // In a finally so a thrown extractor does not hold the slot until
            // the connection closes.
            $this->gate->leave();
        }

        if (($result['ok'] ?? false) && $request->get_param('apply')) {
            $result['applied'] = $this->applyToRecords(
                (int) $request->get_param('contract_id'),
                (array) ($result['data'] ?? [])
            );
        }

        return new WP_REST_Response($result, $result['ok'] ? 200 : 502);
    }

    /**
     * Γράφει ό,τι διάβασε το AI στον πελάτη και στη σύμβαση.
     *
     * Υπάρχει επειδή η εξαγωγή γέμιζε **πεδία φόρμας**: ζούσε στον browser
     * μέχρι να πατήσει κάποιος Αποθήκευση, οπότε η καρτέλα της αίτησης έμενε
     * άδεια. Για τον πωλητή που μόλις μετέτρεψε υποψήφιο, το να πρέπει να
     * ανοίξει τον οδηγό και να αποθηκεύσει για να δει στοιχεία που το σύστημα
     * ήδη γνωρίζει, ήταν ακριβώς ο χαμένος χρόνος που ο «σύνδεσμός μου»
     * υποτίθεται ότι εξοικονομεί.
     *
     * **Ποτέ δεν πατάει τιμή που υπάρχει.** Μόνο κενά γεμίζουν -- ίδια
     * σημασιολογία με το `keepExisting` της αυτόματης διαδρομής στη φόρμα.
     * Ό,τι έγραψε άνθρωπος νικάει ό,τι διάβασε μοντέλο, χωρίς εξαίρεση.
     *
     * Η εμβέλεια ελέγχεται σε κάθε γράψιμο ξεχωριστά, μέσα από τα ίδια
     * `update()` που χρησιμοποιεί όλη η εφαρμογή.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string> Τα πεδία που όντως γράφτηκαν.
     */
    private function applyToRecords(int $contractId, array $data): array
    {
        $scope    = $this->scopes->forCurrentUser();
        $contract = $this->contracts->find($contractId, $scope);

        if ($contract === null) {
            return [];
        }

        $customerId    = (int) ($contract['customer_id'] ?? 0);
        $customer      = $customerId > 0 ? $this->customers->find($customerId, $scope) : null;
        $customerPatch = [];
        $contractPatch = [];

        foreach ($data as $field => $value) {
            $value = is_string($value) ? trim($value) : $value;

            if ($value === null || $value === '' || $field === 'customer_type') {
                continue;
            }

            if (in_array($field, self::CONTRACT_FIELDS, true)) {
                if ((string) ($contract[$field] ?? '') === '') {
                    $contractPatch[$field] = $value;
                }

                continue;
            }

            if ($customer !== null && (string) ($customer[$field] ?? '') === '') {
                $customerPatch[$field] = $value;
            }
        }

        // Το ίδιο το JSON κρατιέται πάντα, ακόμα κι αν κανένα πεδίο δεν ήταν
        // κενό: είναι το ίχνος ελέγχου του τι διάβασε το μοντέλο και πότε.
        $contractPatch['extracted_json'] = (string) wp_json_encode($data);

        $applied = [];

        if ($customerPatch !== [] && $this->customers->update($customerId, $scope, $customerPatch)) {
            $applied = array_keys($customerPatch);
        }

        if ($this->contracts->update($contractId, $scope, $contractPatch)) {
            $applied = array_merge($applied, array_keys($contractPatch));
        }

        return array_values(array_diff($applied, ['extracted_json']));
    }

    /**
     * Έγγραφα ήδη αποθηκευμένα σε μια αίτηση που ο χρήστης έχει δικαίωμα να δει.
     *
     * Η εμβέλεια ελέγχεται ΕΔΩ και όχι στο UI: το contract_id έρχεται από τον
     * browser και δεν αποδεικνύει τίποτα. Αίτηση εκτός εμβέλειας απαντά όπως
     * και ανύπαρκτη -- δεν επιβεβαιώνεται καν ότι υπάρχει.
     *
     * @return list<array{path: string, mime: string, kind: string}>
     */
    private function storedDocuments(int $contractId): array
    {
        if ($this->contracts->find($contractId, $this->scopes->forCurrentUser()) === null) {
            return [];
        }

        return $this->files->extractableForContract(
            $contractId,
            self::EXTRACTABLE_KINDS,
            self::ALLOWED_MIMES
        );
    }

    /**
     * The declared type when we accept it, otherwise what the extension says.
     *
     * Browsers report inconsistent types for the same file, so a rejected
     * declaration is worth a second look before the document is discarded.
     *
     * @param array<string, mixed> $upload
     */
    private static function mimeOf(array $upload): ?string
    {
        $declared = (string) ($upload['type'] ?? '');

        if (in_array($declared, self::ALLOWED_MIMES, true)) {
            return $declared;
        }

        $guessed = wp_check_filetype((string) ($upload['name'] ?? ''))['type'] ?? '';

        return in_array($guessed, self::ALLOWED_MIMES, true) ? $guessed : null;
    }

    /**
     * @param array<string, mixed> $uploads
     *
     * @return list<array<string, mixed>>
     */
    private static function normalise(array $uploads): array
    {
        if (! is_array($uploads['name'] ?? null)) {
            return [$uploads];
        }

        $out = [];

        foreach (array_keys($uploads['name']) as $index) {
            $out[] = [
                'name'     => $uploads['name'][$index] ?? '',
                'type'     => $uploads['type'][$index] ?? '',
                'tmp_name' => $uploads['tmp_name'][$index] ?? '',
                'error'    => $uploads['error'][$index] ?? UPLOAD_ERR_OK,
            ];
        }

        return $out;
    }
}
