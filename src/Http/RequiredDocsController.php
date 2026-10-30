<?php

/**
 * (341) GET /docs/required -- τα απαιτούμενα δικαιολογητικά για τον «Έλεγχο
 * πριν την αποστολή» της φόρμας (docs/UI-PRESEND-CHECK.html).
 *
 * Η ίδια λίστα με την καρτέλα αίτησης και την πύλη πληρωμής
 * (`ECRM_Docs::required_for()`), για να μη λέει η φόρμα κάτι άλλο από αυτό
 * που θα ζητήσει αργότερα η πύλη. Με `contract_id` (αίτηση που έχει ήδη
 * αποθηκευτεί) επιστρέφει και τα είδη που έχουν ήδη ανέβει -- μόνο αν η
 * αίτηση είναι μέσα στο scope του χρήστη.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Docs;
use EnergyCRM\Access\NotAuthenticated;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Persistence\ContractRepository;
use WP_REST_Request;
use WP_REST_Response;

final class RequiredDocsController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/docs/required', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'activation_type' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key'],
                'energy_type'     => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key'],
                'contract_id'     => ['type' => 'integer', 'default' => 0],
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

        $energy   = (string) $request['energy_type'];
        $required = ECRM_Docs::required_for((string) $request['activation_type'], $energy !== '' ? $energy : null);
        $present  = [];
        $id       = (int) $request['contract_id'];

        if ($id > 0 && $this->contracts->find($id, $scope) !== null) {
            $present = ECRM_Docs::present_kinds($id);
        }

        return new WP_REST_Response([
            'ok'       => true,
            'required' => array_map(
                static fn (string $kind): array => ['kind' => $kind, 'label' => ECRM_Docs::label($kind)],
                array_values($required)
            ),
            'present'  => array_values($present),
        ], 200);
    }
}
