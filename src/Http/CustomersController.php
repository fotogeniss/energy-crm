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
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly CustomerRepository $customers,
        private readonly ContractQueries $queries,
        private readonly FileRepository $files,
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
