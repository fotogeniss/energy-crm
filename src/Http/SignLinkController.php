<?php

/**
 * POST /contracts/{id}/sign-link — send the customer away to sign.
 *
 * The link itself is the ordinary tracking URL: the customer follows their
 * application and signs from the same page, so there is no second token to
 * expire or leak. What this endpoint really does is move the contract to
 * "awaiting the customer's signature" and, optionally, email the link.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Admin;
use ECRM_Tracking;
use EnergyCRM\Access\NotAuthenticated;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Infrastructure\DocumentQueue;
use EnergyCRM\Persistence\ContractRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SignLinkController implements Controller
{
    private const TARGET_STATUS = 'pending_signature';

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly DocumentQueue $documents,
        private readonly ContractLifecycle $lifecycle,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/sign-link', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id'    => ['type' => 'integer', 'required' => true],
                'email' => ['type' => 'boolean', 'default' => false],
            ],
        ]);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
        }

        $id       = (int) $request['id'];
        $contract = $this->contracts->findDetailed($id, $scope);

        if ($contract === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $moved = $this->lifecycle->moveTo($id, self::TARGET_STATUS, [
            'user_id' => $scope->actorId(),
            'message' => 'Αποστολή για υπογραφή — αναμονή υπογραφής πελάτη',
        ]);

        // The old handler ignored this and handed back a working signing link
        // for a contract the pipeline had refused to move — a cancelled one,
        // most obviously. A customer could then sign something already dead.
        if (! $moved) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Η σύμβαση δεν μπορεί να σταλεί για υπογραφή από την τρέχουσα κατάστασή της.',
            ], 409);
        }

        // The one moment the stored document has to exist: the customer is
        // about to be sent to a page that shows it. Saving only schedules the
        // render, so this closes the window — and does nothing when the cron
        // already got there first.
        $this->documents->ensure($id);

        $url = ECRM_Tracking::url($id);

        return new WP_REST_Response([
            'ok'      => true,
            'url'     => $url,
            'emailed' => $request['email'] ? $this->email($contract, $url) : false,
        ], 200);
    }

    /**
     * @param array<string, mixed> $contract Joined with the customer.
     */
    private function email(array $contract, string $url): bool
    {
        $to = (string) ($contract['email'] ?? '');

        if (! is_email($to)) {
            return false;
        }

        $company = (string) ECRM_Admin::get('company_name', get_bloginfo('name'));

        return wp_mail(
            $to,
            'Υπογραφή σύμβασης - ' . $company,
            sprintf(
                "Αγαπητέ/ή %s,\n\n"
                . "Παρακαλούμε υπογράψτε τη σύμβασή σας ηλεκτρονικά στον παρακάτω σύνδεσμο:\n%s\n\n"
                . "Με εκτίμηση,\n%s",
                $this->customerName($contract) ?: 'πελάτη',
                $url,
                $company
            )
        );
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function customerName(array $contract): string
    {
        $company = trim((string) ($contract['company_name'] ?? ''));

        if ($company !== '') {
            return $company;
        }

        return trim(
            (string) ($contract['first_name'] ?? '') . ' ' . (string) ($contract['last_name'] ?? '')
        );
    }
}
