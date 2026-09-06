<?php

/**
 * GET /customers        the customer book, scoped to the actor
 * GET /customers/check  is this ΑΦΜ or supply already on file?
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Docs;
use ECRM_Files;
use ECRM_Validate;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\CustomerEventRepository;
use EnergyCRM\Persistence\CustomerNoteRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_REST_Response;

/*
 * No NotAuthenticated handling here: Guards::crmUser() has already established
 * a logged-in user before any callback runs, so forCurrentUser() cannot throw.
 * Catching it anyway would be dead code pretending to be caution.
 */
final class CustomersController implements Controller
{
    /**
     * Πεδία επεξεργάσιμα από το γενικό PATCH /customers/{id} (247, Στάδιο 3).
     *
     * ΟΧΙ 'contact_phone' (δικό του route, ξεχωριστό ιστορικό/GDPR κύκλος) ΟΥΤΕ
     * 'customer_type' (αλλάζει ποια από τα ονοματεπώνυμο/επωνυμία έχουν νόημα --
     * δομική αλλαγή, εκτός της μακέτας του Σταδίου 3). Υποσύνολο του
     * CustomerRepository::WRITABLE, όχι το ίδιο -- το WRITABLE είναι το τι
     * ΜΠΟΡΕΙ να γράψει η repository σε οποιονδήποτε καλούντα (ήδη χρειάζεται
     * το πλήρες σύνολο για το ContractSaveController/ExtractionController),
     * αυτό εδώ είναι το τι επιτρέπει να στείλει ΑΥΤΟ το route.
     *
     * @var list<string>
     */
    private const EDITABLE_FIELDS = [
        'first_name', 'last_name', 'father_name', 'company_name',
        'afm', 'doy', 'adt', 'birth_date',
        'street', 'street_no', 'postal_code', 'city', 'region',
        'phone', 'mobile', 'email',
    ];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly CustomerRepository $customers,
        private readonly ContractQueries $queries,
        private readonly FileRepository $files,
        private readonly CustomerNoteRepository $notes,
        private readonly CustomerEventRepository $events,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/customers', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'scope' => [
                    'type'    => 'string',
                    'default' => 'own',
                    'enum'    => ['own', 'team'],
                ],
                'q' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/customers/check', [
            'methods'             => 'GET',
            'callback'            => [$this, 'check'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'afm' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'supply' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Build queue 08, 25/08: το "Χρησιμοποίησε τα στοιχεία του" της φόρμας
        // χρειάζεται τα πλήρη στοιχεία ενός ήδη γνωστού πελάτη, όχι μόνο τα 6
        // πεδία του /customers (φτιαγμένο για τη λίστα). find() τα δίνει ήδη
        // όλα, scoped — αυτό το route απλώς τα εκθέτει.
        register_rest_route(Router::NAMESPACE, '/customers/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'show'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);

        // Build queue 09, 05/09: η καρτέλα πελάτη (247, Στάδιο 1). Ξεχωριστό
        // route από το /customers/{id} και όχι το ίδιο με περισσότερα πεδία --
        // εκείνο τροφοδοτεί το "χρησιμοποίησε τα στοιχεία του" της φόρμας και
        // θέλει να μένει φτηνό· αυτό εδώ κάνει τρία ερωτήματα (πελάτης,
        // συμβάσεις, έγγραφα) και δεν έχει λόγο να τρέχει σε κάθε αναζήτηση
        // διπλοεγγραφής.
        register_rest_route(Router::NAMESPACE, '/customers/(?P<id>\d+)/card', [
            'methods'             => 'GET',
            'callback'            => [$this, 'card'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);

        // 247, Στάδιο 2: σημειώσεις -- ελεύθερο κείμενο για τον πελάτη, εκτός
        // τυπωμένων εντύπων. Append-only σκόπιμα: δες CustomerNoteRepository.
        register_rest_route(Router::NAMESPACE, '/customers/(?P<id>\d+)/notes', [
            'methods'             => 'POST',
            'callback'            => [$this, 'addNote'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id'   => ['type' => 'integer', 'required' => true],
                'body' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);

        // 247, Στάδιο 2: το τηλ. επικοινωνίας μένει σε ΔΙΚΟ ΤΟΥ route, ξεχωριστό
        // από την πλήρη επεξεργασία του Σταδίου 3 -- εσωτερικής χρήσης, δεν
        // τυπώνεται πουθενά, άρα δεν χρειάζεται ιστορικό αλλαγών (customer_events)
        // ούτε έλεγχο διπλοεγγραφής.
        register_rest_route(Router::NAMESPACE, '/customers/(?P<id>\d+)/contact-phone', [
            'methods'             => 'PATCH',
            'callback'            => [$this, 'updateContactPhone'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id'            => ['type' => 'integer', 'required' => true],
                'contact_phone' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // 247, Στάδιο 3: πλήρης επεξεργασία στοιχείων -- Απόφαση ιδιοκτήτη
        // (05/09): όποιος βλέπει τον πελάτη μπορεί να τον επεξεργαστεί, ΚΑΘΕ
        // αλλαγή καταγράφεται στο customer_events (ορατή σε όλους στο
        // ιστορικό), και δεν εμποδίζεται τίποτα -- ούτε δεύτερο ΑΦΜ που
        // ανήκει ήδη σε άλλον πελάτη, ούτε αλλαγή σε πελάτη με ήδη
        // κατατεθειμένες συμβάσεις. Το κόστος εξ ολοκλήρου στην ΠΡΟΕΙΔΟΠΟΙΗΣΗ
        // πριν το save -- ο client καλεί ήδη το /customers/check και έχει ήδη
        // το πλήθος συμβάσεων από το /card, οπότε δεν χρειάζεται νέο endpoint
        // για κανένα από τα δύο.
        //
        // Κάθε πεδίο προαιρετικό -- ο client στέλνει ΜΟΝΟ όσα άλλαξε το ένα
        // από τα τρία μπλοκ (Ταυτότητα/Διεύθυνση/Επικοινωνία) που άνοιξε,
        // ίδιο σχήμα με το ContractSaveMapping::contractFrom(): ένα πεδίο που
        // λείπει από το αίτημα μένει ανέγγιχτο, δεν γίνεται σιωπηλά NULL.
        register_rest_route(Router::NAMESPACE, '/customers/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [$this, 'updateFull'],
            'permission_callback' => Guards::crmUser(),
            'args'                => array_merge(
                [
                    'id'                => ['type' => 'integer', 'required' => true],
                    // Δεύτερη κλήση, αφού ο συνεργάτης έχει ήδη πει «ναι» στο
                    // window.confirm() -- ίδιο σχήμα confirm_resend με το
                    // SignLinkController::create().
                    'confirm_duplicate' => ['type' => 'boolean', 'default' => false],
                ],
                array_fill_keys(
                    self::EDITABLE_FIELDS,
                    ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']
                )
            ),
        ]);

        // 247, Στάδιο 3: όλο το ιστορικό αλλαγών -- το card() στέλνει ήδη μόνο
        // την τελευταία γραμμή (last_event), αυτό το route ζητιέται μόνο όταν
        // ο συνεργάτης ανοίξει ρητά «όλο το ιστορικό».
        register_rest_route(Router::NAMESPACE, '/customers/(?P<id>\d+)/events', [
            'methods'             => 'GET',
            'callback'            => [$this, 'events'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $customer = $this->customers->find((int) $request['id'], $this->scopes->forCurrentUser());

        if ($customer === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Ο πελάτης δεν βρέθηκε.'], 404);
        }

        unset($customer['id']);

        return new WP_REST_Response(['ok' => true, 'customer' => $customer], 200);
    }

    /**
     * Ολα όσα δείχνει η καρτέλα πελάτη σε ένα ταξίδι: ο πελάτης, οι
     * συμβάσεις του (ίδια στήλη joined names με τη λίστα «Συμβάσεις»),
     * τα έγγραφα όλων μαζί, και τρία KPI υπολογισμένα εδώ -- όχι στο
     * JS -- ώστε το "τι είναι ενεργό" να περνά πάντα από το ίδιο
     * ContractStatus::isTerminal() που ξέρει ήδη ο υπόλοιπος κώδικας.
     *
     * Στάδιο 1 (247): μόνο ανάγνωση. Κανένα νέο πεδίο, καμία εγγραφή.
     */
    public function card(WP_REST_Request $request): WP_REST_Response
    {
        $scope      = $this->scopes->forCurrentUser();
        $customerId = (int) $request['id'];
        $customer   = $this->customers->find($customerId, $scope);

        if ($customer === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Ο πελάτης δεν βρέθηκε.'], 404);
        }

        $contracts   = $this->queries->forCustomer($scope, $customerId);
        $contractIds = array_map(static fn (array $c): int => (int) $c['id'], $contracts);

        // Ιδια μετατροπή με το ContractsReadController::show(): η στήλη path
        // είναι εσωτερικός λεπτομέρεια αποθήκευσης, όχι δουλειά του client.
        $documents = array_map(
            static function (array $file): array {
                $file['url']      = ECRM_Files::url((int) $file['id']);
                $file['is_image'] = str_starts_with((string) $file['mime'], 'image/');
                unset($file['path'], $file['attachment_id']);

                return $file;
            },
            $this->files->forContracts($contractIds)
        );

        $activeCount = 0;
        $nextExpiry  = null;
        $lastActive  = $customer['updated_at'] ?? null;

        foreach ($contracts as $c) {
            $status = ContractStatus::tryFromSlug((string) ($c['status'] ?? ''));

            if ($status !== null && ! $status->isTerminal()) {
                $activeCount++;
            }

            $endDate = (string) ($c['end_date'] ?? '');

            // Ιδιο κριτήριο με το ContractQueries::expiring(): draft/cancelled
            // δεν μετρούν σαν "λήξη που έρχεται" -- ένα πρόχειρο δεν έχει καν
            // ξεκινήσει ακόμα. days_left έρχεται έτοιμο από DATEDIFF() στο SQL,
            // όχι υπολογισμένο ξανά εδώ με ρολόι PHP -- ίδια αποφυγή σφάλματος
            // ζώνης ώρας με το (72)/(110).
            if (
                $endDate !== '' && $status !== null
                && $status !== ContractStatus::Draft && ! $status->isTerminal()
            ) {
                if ($nextExpiry === null || $endDate < (string) $nextExpiry['end_date']) {
                    $nextExpiry = [
                        'end_date'  => $endDate,
                        'code'      => $c['code'] ?? null,
                        'days_left' => isset($c['days_left']) ? (int) $c['days_left'] : null,
                    ];
                }
            }

            $updatedAt = (string) ($c['updated_at'] ?? '');

            if ($updatedAt !== '' && ($lastActive === null || $updatedAt > (string) $lastActive)) {
                $lastActive = $updatedAt;
            }
        }

        return new WP_REST_Response([
            'ok'        => true,
            'customer'  => $customer,
            'contracts' => $contracts,
            'documents' => $documents,
            'notes'     => $this->withAuthorNames($this->notes->forCustomer($customerId)),
            // 247, Στάδιο 3: η τελευταία γραμμή του ιστορικού αλλαγών, ίδια
            // θέση με το ".audit" της μακέτας -- όχι όλο το ιστορικό εδώ, το
            // openHistory() της οθόνης το ζητά ξεχωριστά μόνο όταν ανοίξει.
            'last_event' => $this->lastEventWithAuthor($customerId),
            'doc_kinds' => ECRM_Docs::kinds(),
            'statuses'  => ContractStatus::labels(),
            'kpi'       => [
                'contracts_count' => count($contracts),
                'active_count'    => $activeCount,
                'next_expiry'     => $nextExpiry,
                'last_active'     => $lastActive,
            ],
        ], 200);
    }

    /**
     * Προσθήκη σημείωσης στην καρτέλα ενός πελάτη (247, Στάδιο 2).
     *
     * Ελέγχει reachability με το ίδιο find() που ήδη χρησιμοποιεί το card() --
     * δεν γράφει σε πελάτη που ο συνεργάτης δεν βλέπει καν, ακόμα κι αν
     * μαντέψει σωστά το id.
     */
    public function addNote(WP_REST_Request $request): WP_REST_Response
    {
        $scope      = $this->scopes->forCurrentUser();
        $customerId = (int) $request['id'];

        if (! $this->customers->isReachable($customerId, $scope)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Ο πελάτης δεν βρέθηκε.'], 404);
        }

        $noteId = $this->notes->create($customerId, $scope->actorId(), (string) $request['body']);

        if ($noteId <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Κενή σημείωση.'], 400);
        }

        return new WP_REST_Response(
            ['ok' => true, 'notes' => $this->withAuthorNames($this->notes->forCustomer($customerId))],
            200
        );
    }

    /**
     * Το ΜΟΝΟ σήμερα εγγράψιμο πεδίο πελάτη -- βλ. σχόλιο στο routes().
     * Περνά ΜΟΝΟ αυτό το ένα κλειδί στο CustomerRepository::update(), ποτέ
     * ολόκληρο σώμα αιτήματος: το `filterWritable()` θα επέτρεπε κι άλλα αν
     * τα στέλνε ο client, και αυτό το route δεν είναι το Στάδιο 3.
     */
    public function updateContactPhone(WP_REST_Request $request): WP_REST_Response
    {
        $scope      = $this->scopes->forCurrentUser();
        $customerId = (int) $request['id'];
        $phone      = trim((string) $request['contact_phone']);

        $updated = $this->customers->update($customerId, $scope, ['contact_phone' => $phone !== '' ? $phone : null]);

        if (! $updated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Ο πελάτης δεν βρέθηκε.'], 404);
        }

        return new WP_REST_Response(['ok' => true, 'contact_phone' => $phone], 200);
    }

    /**
     * Πλήρης επεξεργασία στοιχείων πελάτη (247, Στάδιο 3).
     *
     * Χωρίς κανένα εμπόδιο -- ούτε δεύτερο ΑΦΜ ήδη σε χρήση, ούτε επίδραση σε
     * ήδη κατατεθειμένες συμβάσεις: απόφαση ιδιοκτήτη (05/09), και τα δύο
     * μόνο προειδοποιήσεις στην οθόνη πριν το save (βλ. σχόλιο στο routes()).
     * Ο μόνος πραγματικός έλεγχος εδώ είναι το ΑΦΜ (ψηφίο ελέγχου), ίδιος με
     * το ContractSaveController -- ένα άκυρο ΑΦΜ δεν έχει καμία νόμιμη χρήση,
     * σε αντίθεση με ένα ΑΦΜ που απλώς ανήκει ήδη σε κάποιον άλλον.
     *
     * Η καταγραφή στο customer_events γίνεται με βάση τη διαφορά ΠΡΙΝ/ΜΕΤΑ,
     * όχι με βάση το τι έστειλε ο client: ένα πεδίο που στάλθηκε αλλά δεν
     * άλλαξε ουσιαστικά (ίδια τιμή ξαναγραμμένη) δεν παράγει άχρηστη γραμμή
     * ιστορικού.
     */
    public function updateFull(WP_REST_Request $request): WP_REST_Response
    {
        $scope      = $this->scopes->forCurrentUser();
        $customerId = (int) $request['id'];
        $before     = $this->customers->find($customerId, $scope);

        if ($before === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Ο πελάτης δεν βρέθηκε.'], 404);
        }

        $data = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (! $request->has_param($field)) {
                continue;
            }

            $value = trim((string) $request[$field]);
            $data[$field] = 'afm' === $field ? ECRM_Validate::digits($value) : $value;
        }

        if (isset($data['afm']) && $data['afm'] !== '' && ! ECRM_Validate::afm($data['afm'])) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Μη έγκυρο ΑΦΜ (αποτυχία ελέγχου ψηφίου).',
                'field' => 'afm',
            ], 422);
        }

        // Προειδοποίηση, όχι εμπόδιο -- απόφαση ιδιοκτήτη (05/09): ο
        // συνεργάτης μπορεί να προχωρήσει ούτως ή άλλως, στέλνοντας ξανά το
        // ίδιο αίτημα με confirm_duplicate: true. duplicatesOf() ψάχνει με
        // βάση συμβάσεις, οπότε φιλτράρουμε τις γραμμές του ΙΔΙΟΥ πελάτη --
        // αλλιώς κάθε αλλαγή ΑΦΜ θα προειδοποιούσε για τον εαυτό του.
        if (
            isset($data['afm']) && $data['afm'] !== '' && $data['afm'] !== (string) ($before['afm'] ?? '')
            && ! (bool) $request['confirm_duplicate']
        ) {
            $matches = array_values(array_filter(
                $this->customers->duplicatesOf($scope, $data['afm'], ''),
                static fn (array $m): bool => (int) $m['id'] !== $customerId
            ));

            if ($matches !== []) {
                $name = $matches[0]['company_name']
                    ?: trim(($matches[0]['first_name'] ?? '') . ' ' . ($matches[0]['last_name'] ?? ''));

                return new WP_REST_Response([
                    'ok'            => false,
                    'needs_confirm' => true,
                    'reason'        => 'afm_duplicate',
                    'error'         => 'Το ΑΦΜ ανήκει ήδη σε: ' . ($name ?: 'άλλον πελάτη') . '.',
                ], 409);
            }
        }

        $changes = [];

        foreach ($data as $field => $value) {
            $old = (string) ($before[$field] ?? '');

            if ($old !== $value) {
                $changes[$field] = ['old' => $old, 'new' => $value];
            }
        }

        if ($changes === []) {
            return new WP_REST_Response(['ok' => true, 'customer' => $before, 'changed' => []], 200);
        }

        if (! $this->customers->update($customerId, $scope, $data)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Ο πελάτης δεν βρέθηκε.'], 404);
        }

        $this->events->record($customerId, $scope->actorId(), $changes);

        return new WP_REST_Response([
            'ok'       => true,
            'customer' => $this->customers->find($customerId, $scope),
            'changed'  => array_keys($changes),
        ], 200);
    }

    /**
     * Ιδιο πρότυπο με το ContractsReadController::withActorNames() -- ένα
     * μαζικό get_users() αντί για N επερωτήσεις, ένα όνομα ανά partner_user_id.
     *
     * @param list<array<string, mixed>> $notes
     *
     * @return list<array<string, mixed>>
     */
    private function withAuthorNames(array $notes): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (array $n): int => (int) ($n['partner_user_id'] ?? 0), $notes)
        )));

        $names = [];

        foreach ($ids === [] ? [] : get_users(['include' => $ids, 'fields' => ['ID', 'display_name']]) as $user) {
            $names[(int) $user->ID] = $user->display_name;
        }

        foreach ($notes as $index => $note) {
            $uid = (int) ($note['partner_user_id'] ?? 0);
            $notes[$index]['author'] = $uid > 0 ? ($names[$uid] ?? '—') : '—';
        }

        return $notes;
    }

    /**
     * Η μία γραμμή που δείχνει το ".audit" footer της κάρτας, με όνομα συντάκτη.
     *
     * @return array<string, mixed>|null
     */
    private function lastEventWithAuthor(int $customerId): ?array
    {
        $event = $this->events->latestForCustomer($customerId);

        return $event ? $this->withAuthorNames([$event])[0] : null;
    }

    /** GET /customers/{id}/events -- όλο το ιστορικό αλλαγών (247, Στάδιο 3). */
    public function events(WP_REST_Request $request): WP_REST_Response
    {
        $scope      = $this->scopes->forCurrentUser();
        $customerId = (int) $request['id'];

        if (! $this->customers->isReachable($customerId, $scope)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Ο πελάτης δεν βρέθηκε.'], 404);
        }

        return new WP_REST_Response(
            ['ok' => true, 'events' => $this->withAuthorNames($this->events->forCustomer($customerId))],
            200
        );
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();

        if ($request['scope'] !== 'team') {
            $scope = $scope->toSelfOnly();
        }

        $rows = array_map(
            static function (array $row): array {
                $name = $row['company_name']
                    ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

                return [
                    'id'        => (int) $row['id'],
                    'name'      => $name !== '' ? $name : '—',
                    'afm'       => $row['afm'],
                    'phone'     => $row['phone'],
                    'email'     => $row['email'],
                    'contracts' => (int) $row['contracts'],
                    'last_at'   => $row['last_at'],
                ];
            },
            $this->customers->search($scope, trim((string) $request['q']))
        );

        return new WP_REST_Response(
            ['ok' => true, 'rows' => $rows, 'count' => count($rows)],
            200
        );
    }

    public function check(WP_REST_Request $request): WP_REST_Response
    {
        // Ίδια κανονικοποίηση με το /contracts/duplicate. Με σκέτο trim() το
        // ΑΦΜ με κενά έψαχνε άλλο hash από αυτό που είχε αποθηκευτεί.
        $afm    = ECRM_Validate::digits((string) $request['afm']);
        $supply = trim((string) $request['supply']);

        if ($afm === '' && $supply === '') {
            return new WP_REST_Response(['ok' => true, 'matches' => []], 200);
        }

        return new WP_REST_Response([
            'ok'      => true,
            'matches' => $this->customers->duplicatesOf(
                $this->scopes->forCurrentUser(),
                $afm,
                $supply
            ),
        ], 200);
    }
}
