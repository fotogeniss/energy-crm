<?php

/**
 * GET  /team       direct reports, with contract counts
 * POST /team       add a member (creates the WordPress account)
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
use EnergyCRM\Persistence\ContractRepository;
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
                        'enum'    => [Roles::SELLER, Roles::REGISTRAR, Roles::PARTNER],
                    ],
                    'password' => ['type' => 'string', 'default' => ''],
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/team/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update'],
            'permission_callback' => $manager,
            'args'                => [
                'id' => ['type' => 'integer', 'required' => true],
                'op' => ['type' => 'string', 'required' => true, 'enum' => ['toggle', 'remove']],
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
