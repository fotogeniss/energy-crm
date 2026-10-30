<?php

/**
 * (338) GET /stuck — «Κολλημένες αιτήσεις».
 *
 * Ο κανόνας της κάρτας «Γιατί κάθεται» (Domain\Contract\StuckRule) σε όλες τις
 * ανοιχτές αιτήσεις του scope: ο πωλητής τις δικές του, ο υπεύθυνος της ομάδας
 * του, το back office και ο διαχειριστής όλη την εταιρεία. Το SQL στο
 * Persistence\StatusDwellRepository. Mockup: docs/UI-STUCK-LIST.html.
 *
 * Το `group` λέει στην οθόνη πώς να τις χωρίσει: ανά πάροχο όποιος βλέπει
 * όλη την εταιρεία (αυτός κυνηγά τους παρόχους), ανά πωλητή ο υπεύθυνος.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Notifications;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Domain\Contract\StuckRule;
use EnergyCRM\Persistence\StatusDwellRepository;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Response;

final class StuckController implements Controller
{
    /** Όταν το digest δεν είναι φορτωμένο: το προεπιλεγμένο 2 × 5 μέρες του. */
    private const DEFAULT_LIMIT = 10;

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly StatusDwellRepository $dwell,
        private readonly TeamRepository $team,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/stuck', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::crmUser(),
        ]);
    }

    public function index(): WP_REST_Response
    {
        $rows   = $this->find($this->scopes->forCurrentUser());
        $names  = $this->team->displayNames(array_map(
            static fn (array $r): int => (int) $r['partner_user_id'],
            $rows
        ));
        $labels = ContractStatus::labels();
        $out    = [];

        foreach ($rows as $r) {
            $customer = (string) ($r['company_name'] ?? '')
                ?: trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));

            $out[] = [
                'id'           => (int) $r['id'],
                'code'         => (string) ($r['code'] ?? ''),
                'customer'     => $customer !== '' ? $customer : '—',
                'status'       => (string) $r['status'],
                'status_label' => $labels[(string) $r['status']] ?? (string) $r['status'],
                'provider'     => (string) ($r['provider_name'] ?? '') ?: '—',
                'program'      => (string) ($r['program_name'] ?? ''),
                'owner_name'   => $names[(int) $r['partner_user_id']] ?? '—',
                'days'         => $r['stuck']['days'],
                'typical'      => $r['stuck']['typical'],
                'compared'     => $r['stuck']['compared'],
                'limit'        => $r['stuck']['limit'],
            ];
        }

        $group = 'none';

        if (current_user_can(Capability::VIEW_ALL)) {
            $group = 'provider';
        } elseif (current_user_can(Capability::MANAGE_TEAM)) {
            $group = 'owner';
        }

        return new WP_REST_Response(['ok' => true, 'group' => $group, 'rows' => $out], 200);
    }

    /**
     * Οι κολλημένες του scope, πιο παλιά πρώτα. Και για το σήμα του μενού.
     *
     * @return list<array<string, mixed>>
     */
    public function find(UserScope $scope): array
    {
        $limit = class_exists(ECRM_Notifications::class)
            ? ECRM_Notifications::escalation_days()
            : self::DEFAULT_LIMIT;

        return StuckRule::filter(
            $this->dwell->openWithDwell($scope, ContractStatus::intermediateValues()),
            fn (string $status, ?int $provider): ?array => $this->dwell->typicalDays($status, $provider),
            $limit
        );
    }
}
