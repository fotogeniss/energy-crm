<?php

/**
 * GET  /team/{id}/providers  ποιους παρόχους βλέπει ένα μέλος (και αν μπορείς να το αλλάξεις)
 * POST /team/{id}/providers  όρισε τους παρόχους ενός μέλους
 * GET  /team/providers       οι πάροχοί σου, και πόσοι από τους άμεσους σου έχουν τον καθένα
 * POST /team/providers       ένας πάροχος σε όλους τους άμεσους σου / από όλους
 *
 * ## Ποιος αλλάζει τι (αποφάσεις ιδιοκτήτη 21/09)
 *
 * - Ο admin: οποιονδήποτε, από όλους τους ενεργούς παρόχους.
 * - Ενας manager: μόνο τους ΑΜΕΣΟΥΣ του υφισταμένους -- ίδιο σύνορο με το
 *   ενεργοποίηση/αφαίρεση του `TeamController::update()` -- και μόνο από όσους
 *   παρόχους βλέπει ο ίδιος. Ενας υπο-manager αποφασίζει για τους δικούς του.
 * - Το «σε όλους» πιάνει μόνο τους άμεσους. Η ΑΦΑΙΡΕΣΗ όμως κατεβαίνει παντού:
 *   ό,τι φεύγει από κάποιον σβήνεται και από όλους από κάτω του, ώστε να μην
 *   ξαναεμφανιστεί μόνο του αν ξαναδοθεί πιο ψηλά (δες `GrantChange`).
 *
 * Το ΤΙ βλέπει κάποιος το αποφασίζει ΜΟΝΟ το `Access\ProviderVisibility` --
 * εδώ απλώς γράφονται λίστες και ζωγραφίζονται απαντήσεις.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Providers\Http;

use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ProviderVisibility;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Http\Controller;
use EnergyCRM\Http\Guards;
use EnergyCRM\Http\Router;
use EnergyCRM\Persistence\ProviderRepository;
use EnergyCRM\Persistence\TeamRepository;
use EnergyCRM\Providers\Domain\GrantChange;
use EnergyCRM\Providers\Domain\ProviderAccess;
use EnergyCRM\Providers\Persistence\ProviderGrantRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ProviderAccessController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ProviderVisibility $visibility,
        private readonly ProviderGrantRepository $grants,
        private readonly ProviderRepository $providers,
        private readonly TeamRepository $team,
    ) {
    }

    public function routes(): void
    {
        $manager = Guards::needs(Capability::MANAGE_TEAM);

        register_rest_route(Router::NAMESPACE, '/team/(?P<id>\d+)/providers', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'show'],
                // Και ο ίδιος ο πωλητής βλέπει τους παρόχους του στην καρτέλα
                // του. ΠΟΙΟΝ επιτρέπεται να δει το κρίνει η show().
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
                    'id'           => ['type' => 'integer', 'required' => true],
                    'provider_ids' => [
                        'type'     => 'array',
                        'required' => true,
                        'items'    => ['type' => 'integer'],
                    ],
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/team/providers', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'summary'],
                'permission_callback' => $manager,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'bulk'],
                'permission_callback' => $manager,
                'args'                => [
                    'provider_id' => ['type' => 'integer', 'required' => true],
                    'op'          => ['type' => 'string', 'required' => true, 'enum' => ['grant', 'revoke']],
                ],
            ],
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $scope  = $this->scopes->forCurrentUser();
        $member = (int) $request['id'];

        if (! $this->canView($scope, $member)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε.'], 404);
        }

        if ($this->isAdministrator($member)) {
            return new WP_REST_Response([
                'ok'           => true,
                'editable'     => false,
                'unrestricted' => true,
                'providers'    => $this->named($this->providers->activeIds()),
                'granted'      => $this->providers->activeIds(),
            ], 200);
        }

        $sees     = $this->visibility->forUser($member);
        $editable = $this->canEdit($scope, $member);

        // Οποιος αλλάζει βλέπει τη δική ΤΟΥ λίστα να διαλέξει από εκεί· όποιος
        // απλώς διαβάζει βλέπει μόνο τι έχει το μέλος.
        $list = $editable
            ? $this->visibility->editableBy($scope)
            : $sees->filter($this->providers->activeIds());

        return new WP_REST_Response([
            'ok'           => true,
            'editable'     => $editable,
            'unrestricted' => false,
            'providers'    => $this->named($list),
            'granted'      => $sees->filter($list),
        ], 200);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $scope  = $this->scopes->forCurrentUser();
        $member = (int) $request['id'];

        if (! $this->canEdit($scope, $member)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν ανήκει στην ομάδα σου.'], 403);
        }

        $editable  = $this->visibility->editableBy($scope);
        $requested = array_map('intval', (array) $request['provider_ids']);

        if (! ProviderAccess::only($editable)->covers($requested)) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Δεν μπορείς να δώσεις πάροχο που δεν έχεις ο ίδιος.'],
                422
            );
        }

        $change = GrantChange::replaceWithin($this->grants->grantsOf($member), $editable, $requested);

        $this->grants->set($member, $change->grants);
        $below = $this->stripBelow($member, $change->removed);

        return new WP_REST_Response([
            'ok'      => true,
            'granted' => $this->visibility->forUser($member)->filter($editable),
            'removed' => $change->removed,
            // Σε πόσους από κάτω του έφυγε κάτι -- για το μήνυμα της οθόνης.
            'below'   => $below,
        ], 200);
    }

    public function summary(): WP_REST_Response
    {
        $scope    = $this->scopes->forCurrentUser();
        $editable = $this->visibility->editableBy($scope);
        $members  = $this->directReports($scope->actorId());

        $sees = [];

        foreach ($members as $memberId) {
            $sees[] = $this->visibility->forUser($memberId);
        }

        $rows = [];

        foreach ($this->named($editable) as $provider) {
            $has = 0;

            foreach ($sees as $access) {
                if ($access->allows($provider['id'])) {
                    $has++;
                }
            }

            $rows[] = $provider + ['has' => $has];
        }

        return new WP_REST_Response([
            'ok'        => true,
            'members'   => count($members),
            'providers' => $rows,
        ], 200);
    }

    public function bulk(WP_REST_Request $request): WP_REST_Response
    {
        $scope      = $this->scopes->forCurrentUser();
        $providerId = (int) $request['provider_id'];
        $grant      = $request['op'] === 'grant';

        if (! in_array($providerId, $this->visibility->editableBy($scope), true)) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Δεν μπορείς να δώσεις πάροχο που δεν έχεις ο ίδιος.'],
                422
            );
        }

        $changed = 0;
        $below   = 0;

        foreach ($this->directReports($scope->actorId()) as $memberId) {
            $current = $this->grants->grantsOf($memberId);
            $change  = GrantChange::toggle($current, $providerId, $grant);

            if ($change->grants !== $current) {
                $this->grants->set($memberId, $change->grants);
                $changed++;
            }

            $below += $this->stripBelow($memberId, $change->removed);
        }

        return new WP_REST_Response(['ok' => true, 'changed' => $changed, 'below' => $below], 200);
    }

    /**
     * Βγάζει τους παρόχους από όλους κάτω από το μέλος.
     *
     * Το «κάτω από» το λέει το scope του ίδιου του μέλους, όχι δικός μας
     * υπολογισμός δέντρου -- μία πολιτική ορατότητας, στο `ScopeResolver`. Ενας
     * πωλητής χωρίς ομάδα έχει scope μόνο τον εαυτό του, οπότε δεν γίνεται
     * τίποτα.
     *
     * @param list<int> $providerIds
     */
    private function stripBelow(int $member, array $providerIds): int
    {
        if ($providerIds === []) {
            return 0;
        }

        $memberScope = $this->scopes->forUser($member);

        // Ενα μέλος-admin δεν έχει «από κάτω» με τη σημασία της ομάδας -- και
        // δεν φτάνουμε ποτέ εδώ γι' αυτό (canEdit() το αποκλείει). Ο έλεγχος
        // μένει ρητός: το userIds() ενός admin είναι μόνο ο ίδιος.
        if ($memberScope->isAdministrator()) {
            return 0;
        }

        $below = array_values(array_diff($memberScope->userIds(), [$member]));

        return $this->grants->strip($below, $providerIds);
    }

    /** Αλλάζει τη λίστα ο admin, ή ο ΑΜΕΣΟΣ manager του μέλους. Ποτέ ένας admin-μέλος, ποτέ ο εαυτός του. */
    private function canEdit(UserScope $scope, int $member): bool
    {
        if ($member <= 0 || $member === $scope->actorId() || $this->isAdministrator($member)) {
            return false;
        }

        if ($scope->isAdministrator()) {
            return get_userdata($member) !== false;
        }

        return $this->team->reportsDirectlyTo($member, $scope->actorId());
    }

    /** Βλέπει τη λίστα όποιος βλέπει και τον άνθρωπο: ο ίδιος, η γραμμή από πάνω του, ο admin. */
    private function canView(UserScope $scope, int $member): bool
    {
        if ($member <= 0 || get_userdata($member) === false) {
            return false;
        }

        return $scope->includes($member);
    }

    /**
     * @return list<int>
     */
    private function directReports(int $managerId): array
    {
        $ids = [];

        foreach ($this->team->directReportsOf($managerId) as $user) {
            $id = (int) $user->ID;

            if (! $this->isAdministrator($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Τα id με τα ονόματά τους, με τη σειρά του καταλόγου.
     *
     * @param list<int> $ids
     *
     * @return list<array{id: int, name: string}>
     */
    private function named(array $ids): array
    {
        $out = [];

        foreach ($this->providers->active() as $row) {
            $id = (int) $row['id'];

            if (in_array($id, $ids, true)) {
                $out[] = ['id' => $id, 'name' => (string) $row['name']];
            }
        }

        return $out;
    }

    private function isAdministrator(int $userId): bool
    {
        return user_can($userId, 'manage_options');
    }
}
