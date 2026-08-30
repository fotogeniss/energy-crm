<?php

/**
 * Η μαζική αλλαγή κατάστασης δεν έβγαζε πρόχειρα χωρίς ΑΦΜ από το draft.
 *
 * Η δεύτερη και τρίτη πόρτα του DraftExitAfmTest (POST /contracts,
 * POST /contracts/{id}/status) ρωτάνε ρητά. Αυτή είναι η τέταρτη — POST
 * /contracts/bulk, action=status — και έμεινε ανοιχτή: το
 * ContractsBulkController::changeStatus() περνούσε κάθε γραμμή απευθείας
 * στο ContractLifecycle::moveTo() χωρίς ποτέ να ρωτήσει το DraftExitGate,
 * ενώ οι δύο άλλες δύο πόρτες το κάνουν από 2026-08-16. Μια «Αλλαγή
 * κατάστασης» σε πολλαπλή επιλογή έβγαζε ένα πρόχειρο χωρίς ΑΦΜ κατευθείαν
 * σε «Εκκρεμότητα» με ένα κλικ — ακριβώς αυτό που το gate υπάρχει για να
 * εμποδίζει.
 *
 * Η ίδια βόλτα βρήκε ότι το repository της μαζικής ενέργειας δεν διάβαζε
 * καν τη στήλη `energy_type` — οπότε το ECRM_Docs::missing_labels() παρακάτω
 * στην ίδια μέθοδο δούλευε πάντα με άδειο energy_type, ό,τι κι αν είχε η
 * σύμβαση. Διορθώθηκε στο ίδιο commit (SELECT στο ContractRepository::
 * reachableAmong()), γιατί ήταν το ίδιο σημείο κώδικα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\ContractStatus;
use WP_REST_Request;
use WP_REST_Response;

final class BulkStatusDraftExitGateTest extends IntegrationTestCase
{
    private const SAVE_ROUTE = '/ecrm/v1/contracts';

    private const BULK_ROUTE = '/ecrm/v1/contracts/bulk';

    private const VALID_AFM = '090003373';

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user($this->makeCrmUser(Roles::PARTNER));
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** Μαζική αλλαγή σε πρόχειρο χωρίς ΑΦΜ: απορρίπτεται, όχι σιωπηλά. */
    public function testBulkStatusChangeOnADraftWithoutAnAfmIsRefused(): void
    {
        $contractId = $this->makeDraft('');

        $response = $this->bulk([
            'ids'    => [$contractId],
            'action' => 'status',
            'value'  => ContractStatus::PendingSignature->value,
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        $data = $response->get_data();

        self::assertSame(0, $data['updated'], 'Το gate δεν έπρεπε να αφήσει τη σύμβαση να προχωρήσει.');
        self::assertSame(
            1,
            $data['missing_afm'] ?? null,
            'Η απόρριψη πρέπει να μετράει ξεχωριστά, όχι σαν "skipped" σιωπηλά.'
        );

        self::assertSame(
            ContractStatus::Draft->value,
            $this->statusOf($contractId),
            'Το πρόχειρο βγήκε από το draft χωρίς ΑΦΜ μέσω της μαζικής ενέργειας.'
        );
    }

    /** Το ίδιο πρόχειρο, με ΑΦΜ ήδη αποθηκευμένο: η μαζική αλλαγή περνάει κανονικά. */
    public function testBulkStatusChangeOnADraftWithAnAfmSucceeds(): void
    {
        $contractId = $this->makeDraft(self::VALID_AFM);

        $response = $this->bulk([
            'ids'    => [$contractId],
            'action' => 'status',
            'value'  => ContractStatus::PendingSignature->value,
        ]);

        $data = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));
        self::assertSame(1, $data['updated']);
        self::assertSame(ContractStatus::PendingSignature->value, $this->statusOf($contractId));
    }

    /** Η ακύρωση ενός πρόχειρου χωρίς ΑΦΜ δεν μπλοκάρεται ποτέ, ούτε μαζικά. */
    public function testBulkCancelOfADraftWithoutAnAfmIsNotBlocked(): void
    {
        $contractId = $this->makeDraft('');

        $response = $this->bulk([
            'ids'    => [$contractId],
            'action' => 'status',
            'value'  => ContractStatus::Cancelled->value,
        ]);

        $data = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));
        self::assertSame(1, $data['updated']);
        self::assertSame(ContractStatus::Cancelled->value, $this->statusOf($contractId));
    }

    // --- fixtures and helpers ------------------------------------------------

    private function makeDraft(string $afm): int
    {
        $payload = [
            'first_name' => 'Μαζική',
            'last_name'  => 'Δοκιμή',
            'status'     => ContractStatus::Draft->value,
        ];

        if ($afm !== '') {
            $payload['afm'] = $afm;
        }

        $request = new WP_REST_Request('POST', self::SAVE_ROUTE);
        $request->set_body_params($payload);

        $response = rest_do_request($request);
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));

        return (int) $data['contract_id'];
    }

    private function statusOf(int $contractId): string
    {
        return (string) $this->storedRow('contracts', $contractId)['status'];
    }

    /** @param array<string, mixed> $body */
    private function bulk(array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::BULK_ROUTE);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));

        return rest_do_request($request);
    }
}
