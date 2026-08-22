<?php

/**
 * GET /contracts       the list, with per-status counts for the tabs
 * GET /contracts/{id}  one contract with its events, files and checklist
 *
 * Reads only. The writes live in ContractSaveController, ContractStatusController
 * and ContractsBulkController. The split was originally so that this controller
 * could land while the writes were still in ECRM_REST; it is worth keeping now
 * for its own sake, because a mistake on this side cannot corrupt anything.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_DB;
use ECRM_Docs;
use ECRM_Files;
use ECRM_Messaging;
use ECRM_Tracking;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\ContractDetails;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ContractsReadController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractQueries $queries,
        private readonly ContractDetails $details,
        private readonly EventRepository $events,
        private readonly FileRepository $files,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'scope'  => ['type' => 'string', 'default' => 'own', 'enum' => ['own', 'team']],
                'status' => [
                    'type'    => 'string',
                    'default' => '',
                    'enum'    => ['', ...array_keys(ECRM_DB::statuses())],
                ],
                'q' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'show'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();

        if ($request['scope'] !== 'team') {
            $scope = $scope->toSelfOnly();
        }

        $rows = $this->queries->search(
            $scope,
            (string) $request['status'],
            trim((string) $request['q'])
        );

        return new WP_REST_Response([
            'ok'       => true,
            'rows'     => $this->withOwnerNames($rows),
            'counts'   => $this->counts($scope),
            'statuses' => ECRM_DB::statuses(),
        ], 200);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $id  = (int) $request['id'];
        $row = $this->details->findDetailed($id, $this->scopes->forCurrentUser());

        if ($row === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $row['events'] = $this->events->forContract($id);
        $row['files']  = array_map(
            static function (array $file): array {
                $file['url']      = ECRM_Files::url((int) $file['id']);
                $file['is_image'] = str_starts_with((string) $file['mime'], 'image/');

                // Storage layout is ours, not the client's business.
                unset($file['path'], $file['attachment_id']);

                return $file;
            },
            $this->files->forContract($id)
        );

        $row['extra'] = empty($row['extra_json'])
            ? []
            : (array) json_decode((string) $row['extra_json'], true);

        $row['track_url']     = ECRM_Tracking::url($id);
        $row['doc_checklist'] = ECRM_Docs::checklist($id, (string) ($row['activation_type'] ?? ''));
        $row['doc_kinds']     = ECRM_Docs::kinds();
        $row['comms']         = self::comms($row);

        // What the status panel is allowed to offer, per the same graph the
        // server enforces (ContractStatus::allowedNext()) — the screen used to
        // render all twelve statuses as clickable regardless of the contract's
        // current one, so an agent saw legal and illegal moves side by side
        // and only found out which was which from a 409 toast. An unreadable
        // status falls back to every slug rather than none, so a row this
        // never happens to hits the old (permissive) behaviour, not a
        // frozen one.
        $currentStatus = ContractStatus::tryFromSlug((string) ($row['status'] ?? ''));

        return new WP_REST_Response([
            'ok'               => true,
            'contract'         => $row,
            'statuses'         => ECRM_DB::statuses(),
            'activation_types' => ECRM_DB::activation_types(),
            'allowed_next'     => $currentStatus === null
                ? array_keys(ECRM_DB::statuses())
                : array_map(static fn (ContractStatus $s): string => $s->value, $currentStatus->allowedNext()),
        ], 200);
    }

    /**
     * Ποια κανάλια μπορούν να δουλέψουν για ΑΥΤΟΝ τον πελάτη, και γιατί όχι.
     *
     * ## Γιατί το λέει ο server
     *
     * Το αν έχει ρυθμιστεί πάροχος μηνυμάτων είναι κατάσταση του server. Ο
     * browser δεν έχει τρόπο να τη μαντέψει, και ένας διάλογος που προσφέρει
     * Viber χωρίς πάροχο υπόσχεται κάτι που θα αποτύχει σιωπηλά — ακριβώς το
     * πράγμα που το B4 ήρθε να κλείσει.
     *
     * ## Τι ΔΕΝ φεύγει
     *
     * Κανένα διαπιστευτήριο, κανένα endpoint, κανένα token. Μόνο «μπορεί /
     * δεν μπορεί, και γιατί». Ο αριθμός και το email δεν επαναλαμβάνονται
     * εδώ: είναι ήδη στη γραμμή, και η οθόνη τα δείχνει από εκεί — δύο
     * αντίγραφα του ίδιου προσωπικού δεδομένου είναι δύο σημεία να διαρρεύσει.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, array{ok: bool, why?: string}>
     */
    private static function comms(array $row): array
    {
        $hasProvider = class_exists(ECRM_Messaging::class) && ECRM_Messaging::enabled();

        // Κινητό πρώτα, σταθερό μετά — ίδια σειρά με το contract_context() του
        // ECRM_Messaging, ώστε η οθόνη να μη λέει «μπορεί» για αριθμό που ο
        // αποστολέας θα απέρριπτε. Η normalize_phone() γυρίζει '' όταν δεν
        // στέλνεται.
        $number = ECRM_Messaging::normalize_phone(
            (string) ($row['mobile'] ?? '') ?: (string) ($row['phone'] ?? '')
        );

        $sms = ['ok' => $hasProvider && $number !== ''];

        if (! $hasProvider) {
            $sms['why'] = 'no_provider';
        } elseif ($number === '') {
            $sms['why'] = 'no_mobile';
        }

        $email = ['ok' => is_email((string) ($row['email'] ?? '')) !== false];

        if (! $email['ok']) {
            $email['why'] = 'no_email';
        }

        return [
            'sms'   => $sms,
            'email' => $email,
            // Ο σύνδεσμος δουλεύει πάντα: ΕΙΝΑΙ το tracking URL, δεν εξαρτάται
            // από τίποτα εξωτερικό. Μπαίνει ρητά ώστε ο διάλογος να διαβάζει
            // έναν πίνακα και όχι έναν πίνακα συν μια εξαίρεση.
            'link'  => ['ok' => true],
        ];
    }

    /**
     * Owner names for the whole page in one query — a lookup per row would
     * reintroduce the N+1 removed in step 3.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withOwnerNames(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (array $r): int => (int) ($r['partner_user_id'] ?? 0), $rows)
        )));

        $names = [];

        foreach ($ids === [] ? [] : get_users(['include' => $ids, 'fields' => ['ID', 'display_name']]) as $user) {
            $names[(int) $user->ID] = $user->display_name;
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['partner_name'] = $names[(int) ($row['partner_user_id'] ?? 0)] ?? '—';
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function counts(UserScope $scope): array
    {
        $counts = ['all' => 0];

        foreach (array_keys(ECRM_DB::statuses()) as $status) {
            $counts[$status] = 0;
        }

        foreach ($this->queries->countsByStatus($scope) as $status => $total) {
            $counts[$status] = $total;
            $counts['all']  += $total;
        }

        return $counts;
    }
}
