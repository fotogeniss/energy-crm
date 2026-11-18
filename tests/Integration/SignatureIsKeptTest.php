<?php

/**
 * (417) Η υπογραφή του πελάτη δεν χάνεται από πωλητή.
 *
 * Έλεγχος 18/11, ευρήματα 1, 3, 4: το «ξαναστείλε» έσβηνε υπογραφή και
 * `signed_at` και σε Ενεργή (μετά γινόταν και διαγραφή της αίτησης), έσβηνε
 * την υπογραφή ακόμη κι όταν η αποστολή αρνιόταν, και η λίστα εγγράφων
 * έσβηνε αρχεία του συστήματος.
 *
 * @kanonas K1
 * @kanonas K2
 * @kanonas K10
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Files;
use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\DeletionGate;
use EnergyCRM\Http\DocumentsController;
use EnergyCRM\Http\SignLinkController;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\FileRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class SignatureIsKeptTest extends IntegrationTestCase
{
    private int $seller;

    private FileRepository $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = $this->makeCrmUser(Roles::SELLER);
        $this->files  = new FileRepository(ECRM_Files::dir());

        wp_set_current_user($this->seller);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testAnActiveContractIsNotReopenedForSignature(): void
    {
        [$contractId] = $this->signedContract('active', true);

        $response = $this->resend($contractId);

        self::assertSame(409, $response->get_status());
        self::assertSame(SignLinkController::ACTIVE_RESIGN, $response->get_data()['error'] ?? null);
        self::assertNotNull($this->files->latestPathOfKind($contractId, 'signature'), 'Η υπογραφή σβήστηκε.');
        self::assertSame('active', (string) $this->storedRow('contracts', $contractId)['status']);
    }

    public function testARefusedResendLeavesTheSignature(): void
    {
        [$contractId] = $this->signedContract('cancelled', false);

        $response = $this->resend($contractId);

        self::assertSame(409, $response->get_status());
        self::assertNotNull($this->files->latestPathOfKind($contractId, 'signature'), 'Άρνηση, και όμως σβήστηκε.');
        self::assertNotEmpty($this->storedRow('contracts', $contractId)['signed_at']);
    }

    public function testAContractThatWasActiveIsNotDeletedEvenWithoutSignedAt(): void
    {
        global $wpdb;

        [$contractId] = $this->signedContract('pending_issue', true);
        $wpdb->update(Tables::name(Tables::CONTRACTS), ['signed_at' => null], ['id' => $contractId]);

        $response = rest_do_request(new WP_REST_Request('DELETE', '/ecrm/v1/contracts/' . $contractId));

        self::assertSame(409, $response->get_status());
        self::assertSame(DeletionGate::WAS_ACTIVE, $response->get_data()['error'] ?? null);
    }

    public function testTheSignatureFileIsNotDeletedFromTheDocumentList(): void
    {
        [$contractId, $fileId] = $this->signedContract('finalisation', false);

        $route = '/ecrm/v1/contracts/' . $contractId . '/files/' . $fileId;

        $seller = rest_do_request(new WP_REST_Request('DELETE', $route));
        self::assertSame(403, $seller->get_status());
        self::assertSame(DocumentsController::SYSTEM_FILE, $seller->get_data()['error'] ?? null);
        self::assertNotNull($this->files->latestPathOfKind($contractId, 'signature'));

        wp_set_current_user($this->makeCrmUser('administrator'));
        self::assertSame(200, rest_do_request(new WP_REST_Request('DELETE', $route))->get_status());
    }

    public function testAnUploadCannotClaimToBeASignature(): void
    {
        self::assertSame('other', DocumentsController::uploadKind('signature'));
        self::assertSame('other', DocumentsController::uploadKind('signature_energy'));
        self::assertSame('other', DocumentsController::uploadKind('signed_pdf'));
        self::assertSame('id_card', DocumentsController::uploadKind('id_card'));
    }

    // --- fixtures ----------------------------------------------------------

    /** @return array{0: int, 1: int} [contract id, signature file id] */
    private function signedContract(string $status, bool $wasActive): array
    {
        global $wpdb;

        $customerId = (new CustomerRepository())->create([
            'first_name' => 'Υπογραφή',
            'last_name'  => 'Πελάτη',
            'afm'        => '090003373',
            'email'      => 'pelatis@example.com',
        ]);

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'customer_id'     => $customerId,
            'partner_user_id' => $this->seller,
            'status'          => $status,
            'code'            => 'ΕΝ-SIG-' . $customerId,
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
            'signed_at'       => current_time('mysql'),
        ]);
        $contractId = (int) $wpdb->insert_id;

        if ($wasActive) {
            (new EventRepository())->record($contractId, $this->seller, 'status_change', [
                'from_status' => 'finalisation',
                'to_status'   => 'active',
            ]);
        }

        $saved = ECRM_Files::put_bytes('sig ' . wp_generate_password(8, false), 'png', 'image/png', 'sig.png');
        self::assertIsArray($saved);

        $fileId = $this->files->attach($contractId, 'signature', 'sig.png', 'image/png', (string) $saved['path']);
        self::assertGreaterThan(0, $fileId);

        return [$contractId, $fileId];
    }

    private function resend(int $contractId): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts/' . $contractId . '/sign-link');
        $request->set_param('confirm_resend', true);

        return rest_do_request($request);
    }
}
