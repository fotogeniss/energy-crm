<?php

/**
 * (352c) «Χωρίς ομάδα»: GET /team/orphans, POST /team/{id}/place.
 *
 * Μόνο για τον διαχειριστή. Δείχνει όσους έχουν ρόλο CRM αλλά κανέναν
 * προϊστάμενο (φτιάχτηκαν από το WordPress) και τους βάζει στην ομάδα του ή
 * κάτω από έναν Συνεργάτη. Μετά φαίνονται στην «Ομάδα» όπως κάθε μέλος, και
 * τους δίνεις παρόχους από εκεί. Mockup `docs/UI-TEAM-ORPHANS.html`.
 *
 * Δεν αλλάζει αιτήσεις, προμήθειες ή παρόχους· μόνο το `ecrm_parent`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Team\Placement;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class TeamPlacementController implements Controller
{
    public function __construct(private readonly TeamRepository $team)
    {
    }

    public function routes(): void
    {
        $admin = static fn (): bool => is_user_logged_in() && current_user_can('manage_options');

        register_rest_route(Router::NAMESPACE, '/team/orphans', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => $admin,
        ]);

        register_rest_route(Router::NAMESPACE, '/team/(?P<id>\d+)/place', [
            'methods'             => 'POST',
            'callback'            => [$this, 'place'],
            'permission_callback' => $admin,
            'args'                => [
                'id'         => ['type' => 'integer', 'required' => true],
                // 0 = στην ομάδα αυτού που πατά.
                'manager_id' => ['type' => 'integer', 'default' => 0],
            ],
        ]);
    }

    public function index(): WP_REST_Response
    {
        $roles = Roles::matrix();

        $members = array_map(
            static function (WP_User $user) use ($roles): array {
                $role = self::crmRoleOf($user);

                return [
                    'id'         => (int) $user->ID,
                    'name'       => $user->display_name,
                    'email'      => $user->user_email,
                    'role'       => $role,
                    'role_label' => $roles[$role]['label'] ?? '—',
                ];
            },
            $this->team->orphans(array_keys($roles))
        );

        return new WP_REST_Response([
            'ok'       => true,
            'members'  => $members,
            'managers' => $this->partners(),
        ], 200);
    }

    public function place(WP_REST_Request $request): WP_REST_Response
    {
        $member = get_userdata((int) $request['id']);

        if (! $member instanceof WP_User || self::crmRoleOf($member) === '' || user_can($member, 'manage_options')) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε.'], 404);
        }

        $memberId = (int) $member->ID;

        if ($this->team->hasManager($memberId)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Ανήκει ήδη σε ομάδα.'], 409);
        }

        $actor   = get_current_user_id();
        $manager = (int) $request['manager_id'] > 0 ? (int) $request['manager_id'] : $actor;

        if ($manager !== $actor && ! in_array($manager, array_column($this->partners(), 'id'), true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Μπαίνει μόνο κάτω από Συνεργάτη.'], 422);
        }

        if (! Placement::allowed($memberId, $manager, $this->team->managersAbove($manager))) {
            $error = 'Δεν μπαίνει κάτω από κάποιον της ομάδας του.';

            return new WP_REST_Response(['ok' => false, 'error' => $error], 422);
        }

        $this->team->attach($memberId, $manager);

        return new WP_REST_Response(['ok' => true, 'manager_id' => $manager], 200);
    }

    /**
     * Ενεργοί Συνεργάτες, για το «Κάτω από…».
     *
     * @return list<array{id: int, name: string}>
     */
    private function partners(): array
    {
        $out = [];

        foreach (get_users(['role' => Roles::PARTNER, 'orderby' => 'display_name']) as $user) {
            if (! $this->team->isDisabled((int) $user->ID)) {
                $out[] = ['id' => (int) $user->ID, 'name' => (string) $user->display_name];
            }
        }

        return $out;
    }

    private static function crmRoleOf(WP_User $user): string
    {
        foreach ((array) $user->roles as $role) {
            if (isset(Roles::matrix()[$role])) {
                return (string) $role;
            }
        }

        return '';
    }
}
