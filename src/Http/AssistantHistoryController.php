<?php

/**
 * GET  /assistant/history        η αποθηκευμένη συνομιλία με τη Λίτσα
 * POST /assistant/history/clear  διαγραφή της
 *
 * Το ίδιο το POST /assistant (η κλήση προς το Claude) μένει στο legacy
 * ECRM_Assistant -- αυτός ο controller προσθέτει μόνο ό,τι λείπει (build
 * queue 14): μόνιμη, scoped στον χρήστη αποθήκευση, στη θέση του παλιού
 * localStorage. Το ECRM_Assistant::chat() γράφει σε αυτό το ίδιο repository
 * μετά από κάθε ανταλλαγή -- βλ. εκεί.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Persistence\AssistantHistoryRepository;
use WP_REST_Response;

final class AssistantHistoryController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly AssistantHistoryRepository $history,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/assistant/history', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::crmUser(),
        ]);

        register_rest_route(Router::NAMESPACE, '/assistant/history/clear', [
            'methods'             => 'POST',
            'callback'            => [$this, 'clear'],
            'permission_callback' => Guards::crmUser(),
        ]);
    }

    public function index(): WP_REST_Response
    {
        $actor = $this->scopes->forCurrentUser()->actorId();

        return new WP_REST_Response([
            'ok'       => true,
            'messages' => $this->history->recentFor($actor),
        ], 200);
    }

    public function clear(): WP_REST_Response
    {
        $this->history->clear($this->scopes->forCurrentUser()->actorId());

        return new WP_REST_Response(['ok' => true], 200);
    }
}
