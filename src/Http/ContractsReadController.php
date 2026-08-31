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
use EnergyCRM\Persistence\StatusDwellRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ContractsReadController implements Controller
{
    /** Καταστάσεις που δεν περιμένουν τίποτα — δεν «κάθονται», τελείωσαν. */
    private const SETTLED_STATUSES = ['active', 'cancelled', 'terminated'];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractQueries $queries,
        private readonly ContractDetails $details,
        private readonly EventRepository $events,
        private readonly FileRepository $files,
        private readonly StatusDwellRepository $dwellRepository,
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

        $row['events'] = $this->withActorNames($this->events->forContract($id));
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
        $row['doc_checklist'] = ECRM_Docs::checklist(
            $id,
            (string) ($row['activation_type'] ?? ''),
            (string) ($row['energy_type'] ?? '')
        );
        $row['doc_kinds']     = ECRM_Docs::kinds();
        $row['doc_expirable'] = ECRM_Docs::expirable_kinds();
        $row['doc_expired']   = ECRM_Docs::expired_docs($id);
        $row['comms']         = self::comms($row);

        // Το λέει ο SERVER, όχι ο browser. Ο διάλογος θα μπορούσε να το βγάλει
        // μόνος του από την ηλικία του γεγονότος — αλλά θα σύγκρινε ώρα βάσης
        // με ώρα συσκευής, και τότε η οθόνη θα έλεγε «έληξε» ενώ ο server θα
        // δεχόταν ακόμη υπογραφή. Μία αλήθεια, ένα σημείο.
        $row['sign_expired']  = ECRM_Tracking::sign_expired($id);

        // Ο αριθμός ταξιδεύει, δεν αντιγράφεται. Πρώτη γραφή τον είχε γραμμένο
        // «48» μέσα σε ελληνική πρόταση στο ecrm-view-detail.js — δηλαδή ακριβώς
        // η διασπορά που απαγορεύει το testTheWindowIsOneNumberInOnePlace, από
        // τον ίδιο που έγραψε το test. Ένα σημείο: ECRM_Tracking.
        $row['sign_window_hours'] = ECRM_Tracking::SIGN_WINDOW_HOURS;
        $row['stuck']         = $this->stuck($row);

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
     * «Γιατί κάθεται;» — και `null` όταν δεν κάθεται.
     *
     * ## Ο κανόνας είναι η σιωπή
     *
     * Επιστρέφει κάτι **μόνο** όταν η σύμβαση είναι πραγματικά εκτός του
     * συνηθισμένου. Μια κάρτα στο rail μιλάει χωρίς να ρωτηθεί, σε κάθε αίτηση
     * που ανοίγει ο συνεργάτης· αν στις οκτώ στις δέκα δεν έχει κάτι ουσιώδες,
     * μαθαίνει να μην κοιτάει εκείνο το σημείο — και τότε χάνεται και τις δύο
     * φορές που είχε δίκιο. Δεν υπάρχει «όλα καλά», δεν υπάρχει κενό κουτί:
     * υπάρχει κάρτα ή δεν υπάρχει.
     *
     * ## Κανένα γλωσσικό μοντέλο
     *
     * Το kit ζητούσε «κάρτα ΑΙ βοήθειας». Η ερώτηση απαντιέται ολόκληρη με
     * αριθμητική πάνω σε γεγονότα που ήδη γράφονται — και το μέτρημα είναι
     * ακαριαίο, δωρεάν, ντετερμινιστικό και δεν μπορεί να εφεύρει νούμερο για
     * τα λεφτά κάποιου. Η κρίση, όταν τη θέλει ο συνεργάτης, είναι δουλειά της
     * Λίτσας, που υπάρχει ήδη και ρωτιέται. Δες docs/UI-STUCK-CARD.html.
     *
     * @param array<string, mixed> $row
     *
     * @return array{days: int, typical: int, sample: int}|null
     */
    private function stuck(array $row): ?array
    {
        $status = (string) ($row['status'] ?? '');
        $id     = (int) ($row['id'] ?? 0);

        // Οι τερματικές δεν «κάθονται» — τελείωσαν. Το να πει η κάρτα ότι μια
        // ενεργή σύμβαση «κάθεται 400 μέρες» θα ήταν αληθές και ανόητο.
        if (in_array($status, self::SETTLED_STATUSES, true)) {
            return null;
        }

        $dwell = $this->dwellRepository;
        $days  = $dwell->daysInStatus($id, $status);

        if (null === $days) {
            return null;
        }

        // Ανά πάροχο όταν υπάρχει δείγμα, αλλιώς συνολικά: οι πάροχοι δεν έχουν
        // τους ίδιους ρυθμούς, και το να συγκρίνεις μια Protergia με τον μέσο
        // όρο όλων είναι σύγκριση με κάτι που δεν υπάρχει.
        $providerId = (int) ($row['provider_id'] ?? 0);
        $typical    = $dwell->typicalDays($status, $providerId ?: null)
            ?? $dwell->typicalDays($status, null);

        if (null === $typical) {
            return null;
        }

        // Διπλάσιο από το συνηθισμένο. Απλό να εξηγηθεί σε άνθρωπο, και δεν
        // χτυπάει για μία μέρα καθυστέρηση. Το +1 κρατά την κάρτα σιωπηλή όταν
        // ο συνήθης χρόνος είναι μηδέν μέρες — αλλιώς κάθε αυθημερόν κατάσταση
        // θα ήταν «εκτός του συνηθισμένου» από το πρώτο εικοσιτετράωρο.
        if ($days < max(2 * $typical['days'], $typical['days'] + 1)) {
            return null;
        }

        return [
            'days'    => $days,
            'typical' => $typical['days'],
            'sample'  => $typical['sample'],
        ];
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
     * The event timeline's "who" — build queue 05, 25/08. Same shape as
     * withOwnerNames() above, resolved separately because these ids come
     * from `events.user_id`, not `contracts.partner_user_id`: 0 means the
     * system did it (e.g. an automated status move), not "unknown".
     *
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private function withActorNames(array $events): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (array $e): int => (int) ($e['user_id'] ?? 0), $events)
        )));

        $names = [];

        foreach ($ids === [] ? [] : get_users(['include' => $ids, 'fields' => ['ID', 'display_name']]) as $user) {
            $names[(int) $user->ID] = $user->display_name;
        }

        foreach ($events as $index => $event) {
            $uid = (int) ($event['user_id'] ?? 0);
            $events[$index]['actor'] = $uid > 0 ? ($names[$uid] ?? '—') : 'Σύστημα';
        }

        return $events;
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
