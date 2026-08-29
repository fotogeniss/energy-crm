<?php

/**
 * GET /contracts/duplicate — is this ΑΦΜ or supply already under contract?
 *
 * Deliberately company-wide. A supply another partner signed last week is the
 * collision most worth catching, and a scoped search would hide it until the
 * provider rejected the application.
 *
 * What crosses the scope boundary is only the *fact* of a clash. Outside the
 * actor's scope the customer's name, the colleague's name and even the contract
 * id are withheld — "άλλος συνεργάτης δικτύου" is the whole disclosure. AUDIT
 * 29/08: code, status, status_label and provider used to leak unconditionally
 * too, undoing this paragraph in practice -- present() now withholds them the
 * same way. Rate-limited like /lookup/afm (VatLookupController): unauthenticated
 * scope, company-wide reach, worth the same budget against a hammering script.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_DB;
use ECRM_RateLimit;
use ECRM_Validate;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractQueries;
use WP_REST_Request;
use WP_REST_Response;

final class DuplicateCheckController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractQueries $queries,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/duplicate', [
            'methods'             => 'GET',
            'callback'            => [$this, 'check'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'afm'     => ['type' => 'string', 'default' => ''],
                'supply'  => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'exclude' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
            ],
        ]);
    }

    public function check(WP_REST_Request $request): WP_REST_Response
    {
        // Company-wide by design (see class docblock) -- exactly the kind of
        // route a script can hammer to enumerate colleagues' contracts one
        // guess at a time. Same budget as /lookup/afm.
        if (! ECRM_RateLimit::allow('duplicate', 30, 300)) {
            return ECRM_RateLimit::too_many();
        }

        $afm    = ECRM_Validate::digits((string) $request['afm']);
        $supply = trim((string) $request['supply']);

        if (strlen($afm) < 9 && $supply === '') {
            return new WP_REST_Response(['ok' => true, 'matches' => []], 200);
        }

        $scope   = $this->scopes->forCurrentUser();
        $labels  = ECRM_DB::statuses();
        $matches = array_map(
            fn (array $row): array => $this->present($row, $scope, $afm, $labels),
            $this->queries->possibleDuplicates($afm, $supply, (int) $request['exclude'])
        );

        return new WP_REST_Response(['ok' => true, 'matches' => $matches], 200);
    }

    /**
     * @param array<string, mixed>  $row
     * @param array<string, string> $labels
     *
     * @return array<string, mixed>
     */
    private function present(array $row, UserScope $scope, string $afm, array $labels): array
    {
        $owner   = (int) $row['partner_user_id'];
        $visible = $scope->includes($owner);
        $isMine  = $owner === $scope->actorId();

        $customer = '';
        $ownerName = '';

        if ($visible) {
            $customer = $row['company_name']
                ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

            $user      = $owner > 0 ? get_userdata($owner) : false;
            $ownerName = $user ? $user->display_name : '';
        }

        return [
            // Zero -- or '' below -- when out of scope: the row must not be
            // openable, and nothing past the fact of the clash may cross the
            // boundary (see class docblock). AUDIT 29/08: these four used to
            // leak unconditionally.
            'id'           => $visible ? (int) $row['id'] : 0,
            'code'         => $visible ? $row['code'] : '',
            'status'       => $visible ? $row['status'] : '',
            'status_label' => $visible ? ($labels[$row['status']] ?? $row['status']) : '',
            'provider'     => $visible ? $row['provider_name'] : '',
            'customer'     => $customer ?: ($visible ? '—' : 'άλλος συνεργάτης δικτύου'),
            'owner'        => $isMine ? 'εσύ' : ($ownerName ?: ($visible ? '—' : '')),
            'is_mine'      => $isMine,
            'in_scope'     => $visible,
            'match_on'     => ($afm !== '' && $row['afm'] === $afm) ? 'afm' : 'supply',
        ];
    }
}
