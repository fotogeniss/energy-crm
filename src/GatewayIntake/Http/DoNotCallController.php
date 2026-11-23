<?php

/**
 * POST /leads/{id}/do-not-call   «Ο πελάτης δεν θέλει άλλες κλήσεις»
 *
 * Ο πωλητής το σημειώνει όταν ο υποψήφιος το ζητήσει στο τηλέφωνο ή αλλού.
 * Δύο πράγματα, με αυτή τη σειρά:
 *
 *  1. Το γεγονός μπαίνει στην ουρά προς το Call Gateway (δεν χάνεται ποτέ,
 *     βλ. `GatewayEvent`). Το Gateway το βάζει στη λίστα «μη καλέσεις»,
 *     ανακαλεί τις συγκαταθέσεις και ακυρώνει κλήσεις που περιμένουν.
 *  2. Ο υποψήφιος γίνεται «Χαμένο» με λόγο «Δεν θέλει κλήσεις», εκτός αν είναι
 *     ήδη «Κερδισμένο» (εκεί υπάρχει σύμβαση και δεν το πειράζουμε).
 *
 * K10: πρώτα ελέγχεται ότι ο υποψήφιος είναι ορατός στον χρήστη (scope), μετά
 * γράφεται οτιδήποτε. Άγνωστος ή ξένος υποψήφιος = 404, τίποτα δεν αλλάζει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\GatewayIntake\Http;

use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\GatewayIntake\Domain\DoNotCallDecision;
use EnergyCRM\GatewayIntake\Domain\GatewayEvent;
use EnergyCRM\GatewayIntake\Infrastructure\GatewayEventOutbox;
use EnergyCRM\Http\Controller;
use EnergyCRM\Http\Guards;
use EnergyCRM\Http\Router;
use EnergyCRM\Persistence\LeadRepository;
use WP_REST_Request;
use WP_REST_Response;

final class DoNotCallController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly LeadRepository $leads,
        private readonly GatewayEventOutbox $outbox,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/leads/(?P<id>\d+)/do-not-call', [
            'methods'             => 'POST',
            'callback'            => [$this, 'mark'],
            'permission_callback' => Guards::needs(Capability::MANAGE_LEADS),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function mark(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();
        $id    = (int) ($request['id'] ?? 0);
        $lead  = $this->leads->find($id, $scope);

        if ($lead === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε.'], 404);
        }

        $queued = $this->outbox->enqueue(GatewayEvent::OPT_OUT, (string) ($lead['phone'] ?? ''));

        $changes = DoNotCallDecision::leadChanges((string) ($lead['stage'] ?? ''));
        if ($changes !== []) {
            $this->leads->update($id, $scope, $changes + ['updated_at' => current_time('mysql')]);
        }

        // `gateway` = false: ο αριθμός δεν είναι ελληνικός κινητός/σταθερός,
        // άρα το AI δεν θα τον καλούσε ούτως ή άλλως.
        return new WP_REST_Response(['ok' => true, 'gateway' => $queued], 200);
    }
}
