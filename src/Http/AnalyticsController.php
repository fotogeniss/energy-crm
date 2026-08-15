<?php

/**
 * GET /analytics — funnel, distributions, trend and the team leaderboard.
 *
 * Read-only throughout. The scope decides whether it describes one partner or a
 * whole downline, and the response says which it chose so the UI does not have
 * to guess when a request for the team view is narrowed.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Commissions;
use ECRM_DB;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Analytics\Funnel;
use EnergyCRM\Persistence\AnalyticsRepository;
use WP_REST_Request;
use WP_REST_Response;

final class AnalyticsController implements Controller
{
    private const LEADERBOARD_SIZE = 15;

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly AnalyticsRepository $analytics,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/analytics', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::needs(Capability::VIEW_ANALYTICS),
            'args'                => [
                'scope' => ['type' => 'string', 'default' => 'own', 'enum' => ['own', 'team']],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $scope   = $this->scopes->forCurrentUser();
        $canTeam = current_user_can(Capability::MANAGE_TEAM);
        $asTeam  = $request['scope'] === 'team' && $canTeam;

        if (! $asTeam) {
            $scope = $scope->toSelfOnly();
        }

        $funnel = Funnel::from($this->analytics->countsByStatus($scope));

        $byEnergy = array_map(
            static fn (array $row): array => [
                'label' => ECRM_DB::energy_label((string) $row['energy_type']),
                'count' => (int) $row['c'],
            ],
            $this->analytics->byEnergyType($scope)
        );

        return new WP_REST_Response([
            'ok'          => true,
            // The UI needs to know when it asked for the team and got itself.
            'scope'       => $asTeam ? 'team' : 'own',
            'can_team'    => $canTeam,
            'total'       => $funnel['total'],
            'won'         => $funnel['won'],
            'lost'        => $funnel['lost'],
            'conv_rate'   => $funnel['conv_rate'],
            'canc_rate'   => $funnel['canc_rate'],
            'avg_days'    => $this->analytics->averageDaysToActivation($scope),
            'funnel'      => $funnel['funnel'],
            'by_provider' => $this->labelled($this->analytics->topProviders($scope)),
            'by_energy'   => $byEnergy,
            'by_region'   => $this->labelled($this->analytics->topRegions($scope)),
            'monthly'     => array_values($this->analytics->monthlyTotals($scope, (int) gmdate('Y'))),
            'leaderboard' => $asTeam ? $this->leaderboard($scope) : [],
        ], 200);
    }

    /**
     * Οι δύο κατανομές που έρχονται ωμές από το SQL, στο σχήμα που διαβάζει η οθόνη.
     *
     * Οι topProviders() και topRegions() κάνουν `SELECT ... name, COUNT(*) c`,
     * δηλαδή δίνουν `c`. Η barList() του ecrm-view-analytics.js διαβάζει
     * `it.count`, οπότε μέχρι τις 2026-08-14 οι δύο πίνακες «Ανά πάροχο» και
     * «Ανά νομό» έβγαζαν `100 * undefined / 1` = NaN για το πλάτος της μπάρας
     * και τύπωναν τη λέξη «undefined» στη στήλη της τιμής. Καμία εξαίρεση,
     * κανένα κόκκινο στο console.
     *
     * Το `by_energy` από δίπλα έκανε ήδη ακριβώς αυτή τη μετάφραση — το
     * «λείπει μόνο εδώ ενώ δίπλα δουλεύει» του HANDOVER §6β, σε δύο γραμμές
     * της ίδιας απάντησης.
     *
     * Η μετάφραση γίνεται εδώ και όχι στο repository επειδή το ίδιο σχήμα
     * (`name`, `c`) το διαβάζει σωστά το dashboard από άλλη μέθοδο· αλλαγή
     * στο SQL θα έσπαγε εκείνη την οθόνη για να φτιάξει αυτήν.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array{label: string, count: int}>
     */
    private function labelled(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'label' => (string) $row['name'],
                'count' => (int) $row['c'],
            ],
            $rows
        );
    }

    /**
     * Partners ranked by commission earned.
     *
     * By amount rather than by count on purpose: ten small contracts and three
     * large ones are not the same achievement, and ranking by volume would push
     * people towards the wrong work.
     *
     * @return list<array{name: string, count: int, amount: float}>
     */
    private function leaderboard(UserScope $scope): array
    {
        $totals = [];

        foreach ($this->analytics->payableByPartner($scope, ECRM_DB::payable_statuses()) as $row) {
            $partner = (int) $row['partner_user_id'];

            $totals[$partner] ??= ['count' => 0, 'amount' => 0.0];
            $totals[$partner]['count']++;
            $totals[$partner]['amount'] += (float) ECRM_Commissions::amount_for($row);
        }

        uasort($totals, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        $board = [];

        foreach (array_slice($totals, 0, self::LEADERBOARD_SIZE, true) as $partner => $total) {
            $user = get_userdata($partner);

            $board[] = [
                'name'   => $user ? $user->display_name : '#' . $partner,
                'count'  => $total['count'],
                'amount' => round($total['amount'], 2),
            ];
        }

        return $board;
    }
}
