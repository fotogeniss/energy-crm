<?php

/**
 * GET  /notifications        the bell: follow-ups plus stored events
 * POST /notifications/read   mark one, or all, as read
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Notifications;
use EnergyCRM\Access\NotAuthenticated;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Analytics\Month;
use EnergyCRM\Infrastructure\MonthlyStoreReport;
use EnergyCRM\Infrastructure\StalePriceNotice;
use EnergyCRM\Infrastructure\TicketNotices;
use EnergyCRM\Persistence\NotificationRepository;
use WP_REST_Request;
use WP_REST_Response;

final class NotificationsController implements Controller
{
    /** (349) Ο τύπος της «Νέα αίτηση από το site» (SiteIntakeController). */
    private const SITE_LEAD = 'site_lead';

    private NotificationRepository $notifications;

    public function __construct(
        private readonly ScopeResolver $scopes,
        ?NotificationRepository $notifications = null,
    ) {
        $this->notifications = $notifications ?? new NotificationRepository();
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/notifications', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'scope' => [
                    'type'    => 'string',
                    'default' => 'own',
                    'enum'    => ['own', 'team'],
                ],
                // (349) Το καμπανάκι: χωρίς όσες εκκρεμότητες «καθάρισε».
                'bell'  => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        // (349) «Εκκαθάριση» στο καμπανάκι.
        register_rest_route(Router::NAMESPACE, '/notifications/clear', [
            'methods'             => 'POST',
            'callback'            => [$this, 'clear'],
            'permission_callback' => Guards::crmUser(),
        ]);

        register_rest_route(Router::NAMESPACE, '/notifications/read', [
            'methods'             => 'POST',
            'callback'            => [$this, 'markRead'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id' => [
                    'type'        => 'integer',
                    'required'    => false,
                    'minimum'     => 1,
                    'description' => 'Χωρίς id, σημειώνονται όλες ως διαβασμένες.',
                ],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
        }

        // visibleUserIds() και όχι $scope->userIds(): για διαχειριστή το δεύτερο
        // δίνει μόνο τον εαυτό του, οπότε το «ομάδα» επέστρεφε τις ειδοποιήσεις
        // των δικών του συμβάσεων — συνήθως καμία.
        $ids = $request['scope'] === 'team'
            ? $this->scopes->visibleUserIds($scope)
            : [$scope->actorId()];

        $data = ECRM_Notifications::followups_for($ids, $scope->actorId());
        $data['ok']        = true;
        $data['threshold'] = ECRM_Notifications::threshold_days();

        $data['notifs'] = array_map(
            static fn (array $row): array => [
                'id'          => (int) $row['id'],
                'contract_id' => (int) $row['contract_id'],
                'type'        => $row['type'],
                'title'       => $row['title'],
                'body'        => $row['body'],
                'read'        => ! empty($row['read_at']),
                'created_at'  => $row['created_at'],
                // (336) Ειδοποίηση χωρίς σύμβαση που οδηγεί σε σελίδα διαχείρισης.
                'link'        => self::link((string) $row['type']),
                // (349) Ούτε σύμβαση ούτε σύνδεσμος: η αίτηση διαγράφηκε
                // (το contract_id γίνεται NULL), οπότε δεν ανοίγει τίποτα.
                'gone'        => (int) $row['contract_id'] === 0 && self::link((string) $row['type']) === '',
            ],
            $this->notifications->recentFor($scope->actorId())
        );

        if ($request['bell']) {
            $data = $this->withoutCleared($data, $scope->actorId());
        }

        $data['unread'] = $this->notifications->unreadCount($scope->actorId());

        return new WP_REST_Response($data, 200);
    }

    /**
     * Ειδοποίηση χωρίς σύμβαση που οδηγεί αλλού: (336) σελίδα τιμών,
     * (339) η αναφορά προβλήματος μέσα στο CRM (`#tickets/{id}`).
     */
    private static function link(string $type): string
    {
        if ($type === StalePriceNotice::TYPE) {
            return admin_url('admin.php?page=energy-crm-providers');
        }

        // (345/349) Η μηνιαία αναφορά: Αρχική → Κατάστημα → εκείνος ο μήνας.
        if (str_starts_with($type, MonthlyStoreReport::TYPE_PREFIX)) {
            $month = Month::parse(substr($type, strlen(MonthlyStoreReport::TYPE_PREFIX)));

            return $month !== null ? '#store/' . $month : '#dashboard';
        }

        // (349) Νέα αίτηση από το site: οι Υποψήφιοι.
        if ($type === self::SITE_LEAD) {
            return '#leads';
        }

        $ticket = TicketNotices::ticketOf($type);

        return $ticket > 0 ? '#tickets/' . $ticket : '';
    }

    public function markRead(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
        }

        $id = $request['id'] === null ? null : (int) $request['id'];
        $this->notifications->markRead($scope->actorId(), $id);

        // Το ίδιο κλικ (άνοιγμα καμπανακιού, βλ. ecrm-app.js) σημαίνει «τα
        // είδα όλα ΤΩΡΑ» -- και τις αποθηκευμένες ειδοποιήσεις (πάνω) ΚΑΙ
        // τις εκκρεμότητες (κάτω). Μόνο στη μαζική περίπτωση (χωρίς id):
        // ένα μελλοντικό mark-read ΜΙΑΣ συγκεκριμένης αποθηκευμένης
        // ειδοποίησης δεν πρέπει να φωτογραφίζει ξανά ολόκληρη τη λίστα
        // εκκρεμοτήτων.
        if ($id === null) {
            ECRM_Notifications::dismiss_stale_for($scope->actorId());
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * (349) POST /notifications/clear -- σβήνει τις ειδοποιήσεις του χρήστη
     * και κρύβει από το καμπανάκι τις εκκρεμότητες που υπάρχουν τώρα. Αυτές
     * μένουν στην οθόνη «Εκκρεμότητες» και ξαναβγαίνουν στο καμπανάκι μόλις
     * αλλάξει κάτι πάνω τους (νέο updated_at).
     */
    public function clear(): WP_REST_Response
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
        }

        $actor = $scope->actorId();
        $this->notifications->clearFor($actor);

        $map = [];

        foreach (ECRM_Notifications::followups_for([$actor])['rows'] as $row) {
            $map[(string) $row['id']] = (string) $row['updated_at'];
        }

        $this->notifications->saveClearedFollowups($actor, $map);
        ECRM_Notifications::dismiss_stale_for($actor);

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function withoutCleared(array $data, int $actor): array
    {
        $cleared = $this->notifications->clearedFollowups($actor);

        if ($cleared === []) {
            return $data;
        }

        $data['rows'] = array_values(array_filter(
            (array) ($data['rows'] ?? []),
            static fn (array $row): bool => ($cleared[(string) $row['id']] ?? null) !== (string) $row['updated_at']
        ));
        $data['count'] = count($data['rows']);
        $data['stale'] = count(array_filter($data['rows'], static fn (array $row): bool => ! empty($row['stale'])));

        return $data;
    }
}
