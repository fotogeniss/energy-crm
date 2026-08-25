<?php

/**
 * GET  /team       direct reports, with contract counts
 * POST /team       add a member (creates the WordPress account)
 * GET  /team/{id}  one member's card: KPIs, recent contracts, downline
 * POST /team/{id}  enable, disable, or detach a member
 * GET  /network    sub-partners, who run teams of their own
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\Capability;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use ECRM_Commissions;
use ECRM_DB;
use EnergyCRM\Domain\Commission\CommissionAmount;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\CommissionRepository;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\PartnerCardRepository;
use EnergyCRM\Persistence\TeamRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class TeamController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly TeamRepository $team,
        private readonly ContractRepository $contracts,
        private readonly PartnerCardRepository $card,
        private readonly CommissionRepository $commissions,
    ) {
    }

    public function routes(): void
    {
        $manager = Guards::needs(Capability::MANAGE_TEAM);

        register_rest_route(Router::NAMESPACE, '/team', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'index'],
                'permission_callback' => Guards::crmUser(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create'],
                'permission_callback' => $manager,
                'args'                => [
                    'name' => [
                        'type'              => 'string',
                        'required'          => true,
                        'minLength'         => 1,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'email' => [
                        'type'              => 'string',
                        'required'          => true,
                        'format'            => 'email',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                    'role' => [
                        'type'    => 'string',
                        'default' => Roles::SELLER,
                        'enum'    => [Roles::SELLER, Roles::PARTNER],
                    ],
                    'password' => ['type' => 'string', 'default' => ''],
                ],
            ],
        ]);

        // Δύο μέθοδοι στην ίδια διαδρομή, με ΔΙΑΦΟΡΕΤΙΚΟ φύλακα ο καθένας —
        // και αυτός είναι όλος ο λόγος που η δήλωση άλλαξε σχήμα. Το POST
        // αλλάζει την ομάδα και θέλει MANAGE_TEAM. Το GET μόνο διαβάζει, και
        // ένας πωλητής πρέπει να μπορεί να δει την καρτέλα του: αν φορούσε τον
        // ίδιο φύλακα, η οθόνη θα ήταν κλειστή σε όλους εκτός από τον μάνατζερ.
        // Ποιον ΣΥΓΚΕΚΡΙΜΕΝΑ επιτρέπεται να δει το κρίνει η show(), με το scope.
        register_rest_route(Router::NAMESPACE, '/team/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'show'],
                'permission_callback' => Guards::crmUser(),
                'args'                => [
                    'id' => ['type' => 'integer', 'required' => true],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'update'],
                'permission_callback' => $manager,
                'args'                => [
                    'id' => ['type' => 'integer', 'required' => true],
                    'op' => ['type' => 'string', 'required' => true, 'enum' => ['toggle', 'remove']],
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/network', [
            'methods'             => 'GET',
            'callback'            => [$this, 'network'],
            'permission_callback' => Guards::crmUser(),
        ]);
    }

    public function index(): WP_REST_Response
    {
        $actor   = $this->scopes->forCurrentUser()->actorId();
        $members = $this->team->directReportsOf($actor);
        $roles   = Roles::matrix();

        // Sub-partners run teams of their own and belong under /network.
        $staff = array_values(array_filter(
            $members,
            static fn (WP_User $u): bool => ! in_array(Roles::PARTNER, (array) $u->roles, true)
        ));

        $counts = $this->team->contractCounts(
            array_map(static fn (WP_User $u): int => (int) $u->ID, $staff)
        );

        $out = array_map(
            function (WP_User $user) use ($roles, $counts): array {
                $role = $this->crmRoleOf($user);

                return [
                    'id'         => (int) $user->ID,
                    'name'       => $user->display_name,
                    'email'      => $user->user_email,
                    'role'       => $role,
                    'role_label' => $roles[$role]['label'] ?? '—',
                    'active'     => ! $this->team->isDisabled((int) $user->ID),
                    'contracts'  => $counts[(int) $user->ID] ?? 0,
                ];
            },
            $staff
        );

        return new WP_REST_Response([
            'ok'         => true,
            'members'    => $out,
            'can_manage' => current_user_can(Capability::MANAGE_TEAM),
            'roles'      => array_map(static fn (array $r): string => $r['label'], $roles),
        ], 200);
    }

    /**
     * Η καρτέλα ενός μέλους: τέσσερις δείκτες, οι τελευταίες συμβάσεις, η ομάδα του.
     *
     * ## Ποιος επιτρέπεται να τη δει
     *
     * `$scope->includes($member)` — δηλαδή ο ίδιος ο άνθρωπος, όποιος τον έχει
     * στο υποδέντρο του, και ο διαχειριστής. ΟΧΙ `reportsDirectlyTo()`, που
     * χρησιμοποιεί η update(): εκείνη αλλάζει την ομάδα και είναι σωστό να
     * φτάνει ένα επίπεδο, ενώ εδώ διαβάζουμε. Το ίδιο το αρχείο το γράφει στην
     * update(): «visibility runs deeper than authority».
     *
     * Επιστρέφεται 404 και όχι 403 όταν ο άλλος είναι εκτός εμβέλειας. Ένα 403
     * θα επιβεβαίωνε ότι το μέλος υπάρχει — δηλαδή θα άφηνε να μετρηθεί το
     * μέγεθος της εταιρείας δοκιμάζοντας αριθμούς.
     *
     * ## Γιατί κάθε δείκτης κουβαλά τον παρονομαστή του
     *
     * Βλ. PartnerCardRepository: «78%» χωρίς το «21 από 27» δεν είναι μέτρηση.
     */
    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $scope  = $this->scopes->forCurrentUser();
        $member = (int) $request['id'];

        if (! $scope->includes($member)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε.'], 404);
        }

        $user = get_userdata($member);

        if (! $user) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε.'], 404);
        }

        // Οι ημερομηνίες βγαίνουν ΕΔΩ και περνούν στο repository, ώστε εκείνο
        // να μένει χωρίς ρολόι και να μπορεί να δοκιμαστεί. Ώρα site, όπως
        // γράφει και το created_at — βλ. TeamActivityRepository.
        //
        // ΜΕ ΑΡΙΘΜΗΤΙΚΗ, ΟΧΙ ΜΕ strtotime/gmdate. Το `current_time()` δίνει ώρα
        // site· το `gmdate()` μορφοποιεί σε UTC. Ανακατεμένα, ο «προηγούμενος
        // μήνας» μετατοπίζεται όσο η ζώνη του site — και την πρώτη ή την
        // τελευταία μέρα του μήνα αλλάζει μήνα ολόκληρο, σιωπηλά. Είναι η ίδια
        // παγίδα με το signed_at της (72), από την ανάποδη. Δύο ακέραιοι δεν
        // έχουν ζώνη ώρας.
        $year  = (int) current_time('Y');
        $month = (int) current_time('n');

        $prevYear  = 1 === $month ? $year - 1 : $year;
        $prevMonth = 1 === $month ? 12 : $month - 1;

        $monthStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $prevStart  = sprintf('%04d-%02d-01 00:00:00', $prevYear, $prevMonth);

        $counts  = $this->card->monthlyCounts($scope, $member, $monthStart, $prevStart);
        $success = $this->card->successCounts(
            $scope,
            $member,
            ECRM_DB::payable_statuses(),
            self::terminalStatuses()
        );
        $sign = $this->card->daysToSign($scope, $member);

        $roles = Roles::matrix();
        $role  = $this->crmRoleOf($user);

        return new WP_REST_Response([
            'ok'     => true,
            'member' => [
                'id'         => $member,
                'name'       => $user->display_name,
                'email'      => $user->user_email,
                'role'       => $role,
                'role_label' => $roles[$role]['label'] ?? '—',
                'active'     => ! $this->team->isDisabled($member),
                'is_self'    => $member === $scope->actorId(),
                'joined'     => $user->user_registered,
            ],
            'kpi' => [
                'month'      => $counts,
                'success'    => $success,
                'sign'       => $sign,
                'commission' => $this->commissionTotals($scope, $member),
            ],
            'recent'   => $this->recent($scope, $member),
            'downline' => $this->downline($member),
            'statuses' => ContractStatus::labels(),
        ], 200);
    }

    /**
     * Ό,τι έχει κερδίσει ο συνεργάτης, με το ίδιο ερώτημα και τον ίδιο
     * υπολογισμό που βλέπει και η οθόνη «Προμήθειες».
     *
     * Δεν αθροίζεται εδώ τίποτα με το χέρι: τα ποσά βγαίνουν από το
     * ECRM_Commissions, που κατέχει τους κανόνες, μέσω του CommissionAmount.
     * Αν κάποτε αλλάξει ο κανόνας, οι δύο οθόνες αλλάζουν μαζί ή καμία — και
     * αυτό είναι το ζητούμενο όταν μιλάμε για λεφτά.
     *
     * @return array{total: float, unpaid: float, count: int}
     */
    private function commissionTotals(UserScope $scope, int $member): array
    {
        $total  = 0.0;
        $unpaid = 0.0;
        $count  = 0;

        foreach ($this->commissions->payable($scope, ECRM_DB::payable_statuses(), 2000, $member) as $row) {
            $amount = CommissionAmount::of($row, [ECRM_Commissions::class, 'amount_for']);
            $total += $amount;
            $count++;

            if (($row['payout_status'] ?? '') !== 'paid') {
                $unpaid += $amount;
            }
        }

        return [
            'total'  => round($total, 2),
            'unpaid' => round($unpaid, 2),
            'count'  => $count,
        ];
    }

    /**
     * Οι τελευταίες συμβάσεις, με το όνομα πελάτη ήδη συντεθειμένο.
     *
     * @return list<array<string, mixed>>
     */
    private function recent(UserScope $scope, int $member): array
    {
        $out = [];

        foreach ($this->card->recentContracts($scope, $member) as $row) {
            $name = (string) ($row['company_name'] ?? '');

            if ($name === '') {
                $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
            }

            $out[] = [
                'id'       => (int) $row['id'],
                'code'     => (string) ($row['code'] ?? ''),
                'customer' => $name !== '' ? $name : '—',
                'provider' => (string) ($row['provider_name'] ?? '') ?: '—',
                'status'   => (string) $row['status'],
                'updated'  => (string) $row['updated_at'],
            ];
        }

        return $out;
    }

    /**
     * Η ομάδα του ίδιου του μέλους, ένα επίπεδο κάτω.
     *
     * @return list<array<string, mixed>>
     */
    private function downline(int $member): array
    {
        $children = $this->team->directReportsOf($member);
        $ids      = array_map(static fn (WP_User $u): int => (int) $u->ID, $children);
        $counts   = $this->team->contractCounts($ids);

        return array_map(
            static fn (WP_User $u): array => [
                'id'        => (int) $u->ID,
                'name'      => $u->display_name,
                'contracts' => $counts[(int) $u->ID] ?? 0,
            ],
            $children
        );
    }

    /**
     * Οι καταστάσεις που μετράνε ως «έκλεισε και δεν πληρώνει».
     *
     * Βγαίνουν από το ίδιο το ContractStatus::isTerminal() και δεν γράφονται
     * ως λίστα εδώ: μια δέκατη τρίτη κατάσταση αύριο πρέπει να μπει σε ΕΝΑ
     * σημείο, όχι σε δύο που θα αποκλίνουν σιωπηλά.
     *
     * @return list<string>
     */
    private static function terminalStatuses(): array
    {
        $out = [];

        foreach (ContractStatus::cases() as $status) {
            if ($status->isTerminal()) {
                $out[] = $status->value;
            }
        }

        return $out;
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $email = (string) $request['email'];

        if (email_exists($email)) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Υπάρχει ήδη χρήστης με αυτό το email.'],
                409
            );
        }

        $password = (string) $request['password'];

        // A password the manager typed and forgot is worse than one we generate
        // and show once, so anything too short is replaced outright.
        if (strlen($password) < 6) {
            $password = wp_generate_password(12, true);
        }

        $username = sanitize_user(
            current(explode('@', $email)) . '_' . wp_rand(100, 999),
            true
        );

        $userId = wp_insert_user([
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => (string) $request['name'],
            'role'         => (string) $request['role'],
        ]);

        if ($userId instanceof WP_Error) {
            return new WP_REST_Response(['ok' => false, 'error' => $userId->get_error_message()], 400);
        }

        $this->team->attach($userId, $this->scopes->forCurrentUser()->actorId());

        return new WP_REST_Response([
            'ok'       => true,
            'id'       => $userId,
            'username' => $username,
            // Shown once, so the manager can hand it over.
            'password' => $password,
        ], 200);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $actor  = $this->scopes->forCurrentUser()->actorId();
        $member = (int) $request['id'];

        // Direct reports only: a manager administers their own team, not the
        // whole downline. Visibility runs deeper than authority here.
        if (! $this->team->reportsDirectlyTo($member, $actor)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν ανήκει στην ομάδα σου.'], 403);
        }

        if ($request['op'] === 'remove') {
            // Πρώτα φεύγει η δουλειά του, μετά ο ίδιος. Ανάποδη σειρά και το
            // `detach()` θα τον είχε ήδη βγάλει από το scope, οπότε η μεταφορά
            // δεν θα έβρισκε τίποτα να μετακινήσει.
            //
            // Και τα δύο πάνε στον από πάνω του, που είναι αυτός που κάνει την
            // ενέργεια: οι συμβάσεις γιατί είναι πελάτες της εταιρείας και όχι
            // δικοί του, τα παιδιά του γιατί αλλιώς βγαίνει ολόκληρο το
            // υποδέντρο από το δέντρο μαζί του.
            $scope     = $this->scopes->forCurrentUser();
            $contracts = $this->contracts->handOver($member, $actor, $scope);
            $members   = $this->team->reparentChildren($member, $actor);

            $this->team->detach($member);

            return new WP_REST_Response([
                'ok'        => true,
                'removed'   => true,
                'contracts' => $contracts,
                'members'   => $members,
            ], 200);
        }

        $wasDisabled = $this->team->isDisabled($member);
        $this->team->setDisabled($member, ! $wasDisabled);

        return new WP_REST_Response(['ok' => true, 'active' => $wasDisabled], 200);
    }

    public function network(): WP_REST_Response
    {
        $actor    = $this->scopes->forCurrentUser()->actorId();
        $partners = array_values(array_filter(
            $this->team->directReportsOf($actor),
            static fn (WP_User $u): bool => in_array(Roles::PARTNER, (array) $u->roles, true)
        ));

        $counts = $this->team->contractCounts(
            array_map(static fn (WP_User $u): int => (int) $u->ID, $partners)
        );

        return new WP_REST_Response([
            'ok'       => true,
            'partners' => array_map(
                fn (WP_User $user): array => [
                    'id'        => (int) $user->ID,
                    'name'      => $user->display_name,
                    'email'     => $user->user_email,
                    'contracts' => $counts[(int) $user->ID] ?? 0,
                    'team_size' => $this->team->directReportCount((int) $user->ID),
                ],
                $partners
            ),
        ], 200);
    }

    /** The first CRM role on a user, ignoring WordPress's own. */
    private function crmRoleOf(WP_User $user): string
    {
        foreach ((array) $user->roles as $role) {
            if (isset(Roles::matrix()[$role])) {
                return (string) $role;
            }
        }

        return '';
    }
}
