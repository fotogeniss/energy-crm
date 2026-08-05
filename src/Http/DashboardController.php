<?php

/**
 * GET /dashboard — the landing screen: today, this month, and recent activity.
 *
 * Personal by design. Everything counts only the signed-in partner's own work,
 * which is why no UserScope appears: the team view is what analytics is for,
 * and mixing the two would make "my month" mean different things to different
 * roles.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Partner\PerformanceTier;
use EnergyCRM\Persistence\DashboardRepository;
use WP_REST_Response;

final class DashboardController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly DashboardRepository $dashboard,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/dashboard', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::crmUser(),
        ]);
    }

    public function index(): WP_REST_Response
    {
        $actor      = $this->scopes->forCurrentUser()->actorId();
        $todayStart = gmdate('Y-m-d 00:00:00');
        $monthStart = gmdate('Y-m-01 00:00:00');

        $cards = $this->dashboard->cards($actor, $todayStart, $monthStart);

        return new WP_REST_Response([
            'user'        => wp_get_current_user()->display_name,
            'cards'       => $cards,
            'by_provider' => $this->dashboard->byProviderSince($actor, $monthStart),
            'monthly'     => array_values($this->dashboard->monthlyTotals($actor, (int) gmdate('Y'))),
            'feed'        => $this->dashboard->recentActivity($actor),
            'level'       => PerformanceTier::forVolume($cards['month']),
        ], 200);
    }
}
