<?php

/**
 * (339) Αναφορές προβλημάτων -- docs/UI-TICKETS.html (εγκρίθηκε 30/10).
 *
 *   GET  /tickets                    λίστα + μετρητές, ό,τι επιτρέπει ο ρόλος
 *   POST /tickets                    νέα (κείμενο, «με σταματάει», πλαίσιο, εικόνα)
 *   GET  /tickets/{id}               μία, με τη συζήτηση
 *   POST /tickets/{id}/comments      απάντηση
 *   POST /tickets/{id}/status        κατάσταση -- μόνο διαχειριστής
 *   POST /tickets/{id}/reopen        «Δεν λύθηκε;» -- ο αναφέρων, 14 μέρες
 *   GET  /tickets/{id}/attachment    η εικόνα -- μόνο διαχειριστής
 *
 * Λεπτός: κανόνες στο Domain\Ticket, SQL στο Persistence\TicketRepository,
 * αρχεία και ειδοποιήσεις στο Infrastructure.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_RateLimit;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\NotAuthenticated;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Ticket\TicketContext;
use EnergyCRM\Domain\Ticket\TicketPolicy;
use EnergyCRM\Domain\Ticket\TicketStatus;
use EnergyCRM\Domain\Ticket\TicketText;
use EnergyCRM\Infrastructure\TicketAttachments;
use EnergyCRM\Infrastructure\TicketNotices;
use EnergyCRM\Persistence\TeamRepository;
use EnergyCRM\Persistence\TicketRepository;
use WP_REST_Request;
use WP_REST_Response;

final class TicketsController implements Controller
{
    private const MIN_BODY = 5;

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly TicketRepository $tickets,
        private readonly TicketAttachments $attachments,
        private readonly TicketNotices $notices,
        private readonly TeamRepository $team,
    ) {
    }

    public function routes(): void
    {
        $id = ['id' => ['type' => 'integer', 'required' => true]];

        register_rest_route(Router::NAMESPACE, '/tickets', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'index'],
                'permission_callback' => Guards::crmUser(),
                'args'                => [
                    'status' => [
                        'type'    => 'string',
                        'default' => 'open',
                        'enum'    => ['open', 'new', 'in_progress', 'resolved', 'not_issue'],
                    ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create'],
                'permission_callback' => Guards::crmUser(),
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/tickets/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'show'],
            'permission_callback' => Guards::crmUser(),
            'args'                => $id,
        ]);

        register_rest_route(Router::NAMESPACE, '/tickets/(?P<id>\d+)/comments', [
            'methods'             => 'POST',
            'callback'            => [$this, 'comment'],
            'permission_callback' => Guards::crmUser(),
            'args'                => $id + ['body' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route(Router::NAMESPACE, '/tickets/(?P<id>\d+)/status', [
            'methods'             => 'POST',
            'callback'            => [$this, 'status'],
            'permission_callback' => static fn (): bool => is_user_logged_in() && current_user_can('manage_options'),
            'args'                => $id + [
                'status' => [
                    'type'     => 'string',
                    'required' => true,
                    'enum'     => array_map(static fn (TicketStatus $s): string => $s->value, TicketStatus::cases()),
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/tickets/(?P<id>\d+)/reopen', [
            'methods'             => 'POST',
            'callback'            => [$this, 'reopen'],
            'permission_callback' => Guards::crmUser(),
            'args'                => $id,
        ]);

        register_rest_route(Router::NAMESPACE, '/tickets/(?P<id>\d+)/attachment', [
            'methods'             => 'GET',
            'callback'            => [$this, 'attachment'],
            'permission_callback' => static fn (): bool => is_user_logged_in() && current_user_can('manage_options'),
            'args'                => $id,
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $reporters = $this->reporters();

        if ($reporters === false) {
            return self::unauthenticated();
        }

        $filter   = (string) $request['status'];
        $statuses = $filter === 'open' ? TicketStatus::openValues() : [$filter];
        $rows     = $this->tickets->listFor($reporters, $statuses);
        $names    = $this->team->displayNames(array_map(static fn (array $r): int => (int) $r['user_id'], $rows));

        return new WP_REST_Response([
            'ok'         => true,
            'rows'       => array_map(fn (array $r): array => $this->summary($r, $names), $rows),
            'counts'     => $this->tickets->counts($reporters),
            'can_manage' => current_user_can('manage_options'),
            'team'       => $reporters === null || count($reporters) > 1,
        ], 200);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();

        if ($userId <= 0) {
            return self::unauthenticated();
        }

        if (! ECRM_RateLimit::allow('ticket', 10, 600)) {
            return ECRM_RateLimit::too_many();
        }

        $body = TicketText::clean((string) $request->get_param('body'));

        if (mb_strlen($body, 'UTF-8') < self::MIN_BODY) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Γράψε τι πήγε στραβά.'], 400);
        }

        $raw     = $request->get_param('context');
        $raw     = is_string($raw) ? json_decode($raw, true) : $raw;
        $context = TicketContext::clean(is_array($raw) ? $raw : []);
        $context['role'] = $this->roleLabel($userId);
        $urgent  = filter_var($request->get_param('urgent'), FILTER_VALIDATE_BOOLEAN);

        $upload = $request->get_file_params()['image'] ?? null;
        $stored = null;

        if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = $this->attachments->store($upload);

            if (is_string($stored)) {
                return new WP_REST_Response(['ok' => false, 'error' => $stored], 400);
            }
        }

        $id = $this->tickets->create($userId, $body, $urgent, $context);

        if ($stored !== null) {
            $this->tickets->setAttachment($id, $stored['path'], $stored['mime']);
        }

        $name = $this->team->displayNames([$userId])[$userId] ?? '';
        $this->notices->created($id, $userId, $name, $urgent, $body);

        return new WP_REST_Response(['ok' => true, 'id' => $id], 201);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $found = $this->visible((int) $request['id']);

        if ($found instanceof WP_REST_Response) {
            return $found;
        }

        $userId   = get_current_user_id();
        $comments = $this->tickets->comments((int) $found['id']);
        $names    = $this->team->displayNames(array_merge(
            [(int) $found['user_id']],
            array_map(static fn (array $c): int => (int) $c['user_id'], $comments)
        ));
        $isAdmin  = current_user_can('manage_options');
        $status   = TicketStatus::tryFrom((string) $found['status']) ?? TicketStatus::New;

        $ticket = $this->summary($found, $names) + [
            'can_status' => $isAdmin,
            'can_reopen' => TicketPolicy::canReopen(
                $status,
                (int) $found['user_id'] === $userId,
                is_numeric($found['days_closed']) ? (int) $found['days_closed'] : null
            ),
        ];

        // Η εικόνα μπορεί να δείχνει πελάτη: μόνο ο διαχειριστής ξέρει καν ότι υπάρχει.
        if (! $isAdmin) {
            $ticket['has_attachment'] = false;
        }

        return new WP_REST_Response([
            'ok'       => true,
            'ticket'   => $ticket,
            'comments' => array_map(
                static fn (array $c): array => [
                    'id'         => (int) $c['id'],
                    'kind'       => (string) $c['kind'],
                    'body'       => (string) $c['body'],
                    'author'     => $names[(int) $c['user_id']] ?? '—',
                    'mine'       => (int) $c['user_id'] === $userId,
                    'created_at' => (string) $c['created_at'],
                ],
                $comments
            ),
        ], 200);
    }

    public function comment(WP_REST_Request $request): WP_REST_Response
    {
        $found = $this->visible((int) $request['id']);

        if ($found instanceof WP_REST_Response) {
            return $found;
        }

        $body = TicketText::clean((string) $request['body']);

        if ($body === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'Γράψε την απάντηση.'], 400);
        }

        $userId = get_current_user_id();
        $this->tickets->addComment((int) $found['id'], $userId, $body);
        $this->notices->commented(
            (int) $found['id'],
            $userId,
            $this->team->displayNames([$userId])[$userId] ?? '',
            (int) $found['user_id'],
            $body
        );

        return new WP_REST_Response(['ok' => true], 201);
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        $found = $this->tickets->find((int) $request['id']);

        if ($found === null) {
            return self::missing();
        }

        $status = TicketStatus::from((string) $request['status']);

        if ($status->value === (string) $found['status']) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        $this->move($found, $status, get_current_user_id());
        $this->notices->statusChanged(
            (int) $found['id'],
            get_current_user_id(),
            (int) $found['user_id'],
            $status->label()
        );

        return new WP_REST_Response(['ok' => true], 200);
    }

    public function reopen(WP_REST_Request $request): WP_REST_Response
    {
        $found = $this->visible((int) $request['id']);

        if ($found instanceof WP_REST_Response) {
            return $found;
        }

        $userId  = get_current_user_id();
        $allowed = TicketPolicy::canReopen(
            TicketStatus::tryFrom((string) $found['status']) ?? TicketStatus::New,
            (int) $found['user_id'] === $userId,
            is_numeric($found['days_closed']) ? (int) $found['days_closed'] : null
        );

        if (! $allowed) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Ξανανοίγει μόνο ο αναφέρων, έως 14 μέρες μετά το «Λύθηκε».'],
                403
            );
        }

        $this->move($found, TicketStatus::New, $userId);
        $this->notices->reopened((int) $found['id'], $userId, $this->team->displayNames([$userId])[$userId] ?? '');

        return new WP_REST_Response(['ok' => true], 200);
    }

    public function attachment(WP_REST_Request $request): WP_REST_Response
    {
        $found = $this->tickets->find((int) $request['id']);
        $path  = $found !== null ? (string) ($found['attachment_path'] ?? '') : '';

        if ($found === null || ! $this->attachments->readable($path)) {
            return self::missing();
        }

        $size = filesize($path);

        if ($size === false) {
            return self::missing();
        }

        // Ίδιο μοτίβο με το ExtractionController::source(): readfile χωρίς να
        // φορτωθεί όλο το αρχείο στη μνήμη.
        nocache_headers();
        header('Content-Type: ' . (string) $found['attachment_mime']);
        header('Content-Length: ' . $size);
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /**
     * Κατάσταση + γραμμή στη συζήτηση. Στο κλείσιμο σβήνεται η εικόνα (GDPR).
     *
     * @param array<string, mixed> $ticket
     */
    private function move(array $ticket, TicketStatus $status, int $by): void
    {
        $id = (int) $ticket['id'];

        $this->tickets->setStatus($id, $status->value, $status->isClosed());
        $this->tickets->addComment($id, $by, $status->label(), 'status');

        if ($status->isClosed()) {
            $this->attachments->delete($this->tickets->clearAttachment($id));
        }
    }

    /**
     * @return array<string, mixed>|WP_REST_Response
     */
    private function visible(int $id): array|WP_REST_Response
    {
        $reporters = $this->reporters();

        if ($reporters === false) {
            return self::unauthenticated();
        }

        $found = $this->tickets->find($id);

        // 404 και όχι 403: η ύπαρξη μιας αναφοράς άλλου δεν λέγεται.
        if ($found === null || ! TicketPolicy::canSee($reporters, (int) $found['user_id'])) {
            return self::missing();
        }

        return $found;
    }

    /**
     * @return list<int>|null|false false όταν δεν υπάρχει σύνδεση.
     */
    private function reporters(): array|null|false
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return false;
        }

        // isAdministrator() = διαχειριστής ή back office (VIEW_ALL): εκεί το
        // userIds() είναι μόνο ο ίδιος, όχι ομάδα. Ο διαχειριστής βλέπει όλες
        // από το manage_options· το back office μόνο τις δικές του (Policy).
        $team = $scope->isAdministrator() ? [] : $scope->userIds();

        return TicketPolicy::reporters(
            $scope->actorId(),
            current_user_can('manage_options'),
            current_user_can(Capability::MANAGE_TEAM),
            $team
        );
    }

    /**
     * @param array<string, mixed>  $row
     * @param array<int, string>    $names
     * @return array<string, mixed>
     */
    private function summary(array $row, array $names): array
    {
        $status  = TicketStatus::tryFrom((string) $row['status']) ?? TicketStatus::New;
        $context = json_decode((string) ($row['context'] ?? ''), true);

        return [
            'id'             => (int) $row['id'],
            'status'         => $status->value,
            'status_label'   => $status->label(),
            'urgent'         => (bool) (int) $row['urgent'],
            'body'           => (string) $row['body'],
            'context'        => is_array($context) ? $context : [],
            'reporter'       => $names[(int) $row['user_id']] ?? '—',
            'mine'           => (int) $row['user_id'] === get_current_user_id(),
            'comments'       => (int) ($row['comments'] ?? 0),
            'has_attachment' => (bool) (int) ($row['has_attachment'] ?? 0),
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
        ];
    }

    private function roleLabel(int $userId): string
    {
        $user = get_userdata($userId);

        if ($user === false) {
            return '';
        }

        if (user_can($user, 'manage_options')) {
            return 'Διαχειριστής';
        }

        foreach ((array) $user->roles as $role) {
            if (isset(Roles::matrix()[$role])) {
                return (string) Roles::matrix()[$role]['label'];
            }
        }

        return '';
    }

    private static function unauthenticated(): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
    }

    private static function missing(): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => false, 'error' => 'Η αναφορά δεν βρέθηκε.'], 404);
    }
}
