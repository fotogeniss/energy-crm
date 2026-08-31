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

use ECRM_Notifications;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Partner\PerformanceTier;
use DateTimeImmutable;
use DateTimeZone;
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
        $actor = $this->scopes->forCurrentUser()->actorId();

        // ΩΡΑ SITE, ΟΧΙ UTC. Μέχρι τις 21/08/2026 εδώ ήταν `gmdate()`, ενώ το
        // `created_at` γράφεται με ώρα site: τις πρώτες ώρες κάθε πρωτομηνιάς
        // (2-3 για Ελλάδα) το gmdate επέστρεφε ακόμη τον ΠΡΟΗΓΟΥΜΕΝΟ μήνα, και
        // ο μετρητής «αυτόν τον μήνα» έδειχνε το παλιό παράθυρο. Το ίδιο και
        // για τη μέρα, γύρω από τα μεσάνυχτα. Ίδια οικογένεια με την παγίδα της
        // (72) — και ο λόγος που στην (75) ο μήνας βγαίνει με ακέραιους.
        $year  = (int) current_time('Y');
        $month = (int) current_time('n');

        $today = current_time('Y-m-d');

        // Το «χθες» βγαίνει με αριθμητική ΗΜΕΡΟΜΗΝΙΑΣ, με τη ζώνη καρφωμένη σε
        // UTC και στα δύο άκρα, ώστε να ακυρώνεται. Πρώτη γραφή ήταν
        // `gmdate(..., strtotime($todayStart . ' -1 day'))` — δηλαδή ανάλυση σε
        // ζώνη διακομιστή και μορφοποίηση σε UTC, το ίδιο ακριβώς ανακάτεμα που
        // διορθώνεται τρεις γραμμές πιο πάνω. Μία μέρα πριν από μια ημερομηνία
        // είναι ημερολογιακή πράξη· δεν χρειάζεται να ξέρει ώρα.
        $yesterday = (new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('UTC')))
            ->modify('-1 day')
            ->format('Y-m-d');

        $todayStart     = $today . ' 00:00:00';
        $yesterdayStart = $yesterday . ' 00:00:00';
        $monthStart     = sprintf('%04d-%02d-01 00:00:00', $year, $month);

        $cards = $this->dashboard->cards($actor, $todayStart, $monthStart, $yesterdayStart);

        return new WP_REST_Response([
            'user'        => wp_get_current_user()->display_name,
            'cards'       => $cards,
            'by_provider' => $this->dashboard->byProviderSince($actor, $monthStart),
            // $year, όχι gmdate('Y'): το ίδιο σφάλμα ζώνης, μία φορά τον χρόνο.
            // Τις πρώτες ώρες της πρωτοχρονιάς το γράφημα θα έδειχνε τον περσινό χρόνο.
            'monthly'     => array_values($this->dashboard->monthlyTotals($actor, $year)),
            'feed'        => $this->dashboard->recentActivity($actor),
            'level'       => PerformanceTier::forVolume($cards['month']),

            // Ο αριθμός «7 εκκρεμότητες» δεν οδηγεί πουθενά· η λίστα ναι.
            // Πέντε, οι πιο στάσιμες πρώτα — δες DashboardRepository::needsAttention().
            'attention'   => $this->dashboard->needsAttention($actor),

            // «Ελλείψεις & προθεσμίες», 31/08/2026 -- ό,τι χρειάζεται δικό
            // του βήμα αλλά ΔΕΝ είναι «σύμβαση σε εξέλιξη»: λείπον/ληγμένο
            // δικαιολογητικό, lead με περασμένο ραντεβού. Χωριστό από το
            // 'attention' από πάνω επίτηδες -- εκείνο μένει actionable-only
            // στη σημερινή του λογική (ΧΩΡΙΣ routed), αυτό εδώ είναι τρεις
            // διαφορετικές πηγές μαζεμένες, το καθένα με το δικό του kind.
            'attention_extra' => [
                'missing_docs'  => class_exists(ECRM_Notifications::class)
                    ? ECRM_Notifications::missing_docs_for([$actor])
                    : [],
                'expired_docs'  => class_exists(ECRM_Notifications::class)
                    ? ECRM_Notifications::expired_docs_for([$actor])
                    : [],
                'overdue_leads' => class_exists(ECRM_Notifications::class)
                    ? ECRM_Notifications::overdue_leads_for([$actor])
                    : [],
            ],

            // Τα τέσσερα πλακίδια, 25/08/2026 — δες DashboardRepository::tiles().
            'tiles'       => $this->dashboard->tiles($actor, $monthStart),
        ], 200);
    }
}
