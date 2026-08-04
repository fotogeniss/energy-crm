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
use EnergyCRM\Persistence\NotificationRepository;
use WP_REST_Request;
use WP_REST_Response;

final class NotificationsController implements Controller
{
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
            ],
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

        $ids = $request['scope'] === 'team' ? $scope->userIds() : [$scope->actorId()];

        $data = ECRM_Notifications::followups_for($ids);
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
            ],
            $this->notifications->recentFor($scope->actorId())
        );

        $data['unread'] = $this->notifications->unreadCount($scope->actorId());

        return new WP_REST_Response($data, 200);
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

        return new WP_REST_Response(['ok' => true], 200);
    }
}
