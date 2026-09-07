<?php

/**
 * Build queue 15 — μια υπογεγραμμένη σύμβαση δεν διαγράφεται, σε καμία
 * διαδρομή, ούτε μερικώς σε μια μαζική επιλογή.
 *
 * Ίδιο σύνορο με το `ContractDeleteBytesTest`: η μονάδα (`DeletionGate`) δεν
 * αγγίζει ΚΑΘΟΛΟΥ WordPress ή βάση πέρα από το `signed_at` της ίδιας γραμμής —
 * αυτό που λείπει είναι η καλωδίωση: ότι το REST endpoint πράγματι ρωτάει την
 * πύλη πριν σβήσει, και ότι τα bytes δεν αγγίζονται όταν αρνηθεί.
 *
 * 07/09/2026: η πύλη ρωτούσε το ιστορικό («έφτασε ποτέ σε `signed`;»). Στο
 * νέο μοντέλο η κατάσταση `signed` δεν υπάρχει -- η υπογραφή είναι γεγονός
 * που γράφεται στη στήλη `signed_at` (δες `DeletionGate::refusalOnDelete()`).
 * Τα fixtures εδώ γράφουν το `signed_at` απευθείας αντί να καταγράφουν ένα
 * ψευδο-γεγονός `status_change` προς μια κατάσταση που δεν υπάρχει πια.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Files;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\DeletionGate;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_User;

final class ContractDeletionGateTest extends IntegrationTestCase
{
    private FileRepository $files;

    private ContractRepository $contracts;

    private EventRepository $events;

    private int $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files     = new FileRepository(ECRM_Files::dir());
        $this->contracts = new ContractRepository();
        $this->events    = new EventRepository();
        $this->actor     = $this->makeCrmUser(Roles::PARTNER);

        $user = get_user_by('id', $this->actor);
        self::assertInstanceOf(WP_User::class, $user);
        $user->add_cap(Capability::DELETE_CONTRACT);

        wp_set_current_user($this->actor);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** 1. DELETE /contracts/{id} αρνείται μια σύμβαση που υπογράφηκε ποτέ. */
    public function testDeletingASignedContractIsRefused(): void
    {
        [$contractId, $path] = $this->contractWithDocument();
        $this->markSigned($contractId);

        $response = rest_do_request(new WP_REST_Request('DELETE', '/ecrm/v1/contracts/' . $contractId));

        self::assertSame(409, $response->get_status());
        self::assertSame(DeletionGate::WAS_SIGNED, $response->get_data()['error'] ?? null);
        self::assertFileExists($path, 'Η άρνηση διαγραφής άφησε τα bytes -- και όμως τα έσβησε.');
    }

    /**
     * 2. Το τρέχον status δεν είναι αξιόπιστο εδώ: μια σύμβαση που είναι
     * ΤΩΡΑ «Ακυρώθηκε» μπορεί κάλλιστα να υπογράφηκε πρώτα -- η πύλη πρέπει
     * να το πιάσει από το `signed_at`, όχι από το τρέχον status.
     */
    public function testACancelledContractThatWasOnceSignedIsStillRefused(): void
    {
        [$contractId, $path] = $this->contractWithDocument();
        $this->markSigned($contractId);
        $this->contracts->update($contractId, UserScope::forSelf($this->actor), ['status' => 'cancelled_by_us']);

        $response = rest_do_request(new WP_REST_Request('DELETE', '/ecrm/v1/contracts/' . $contractId));

        self::assertSame(409, $response->get_status());
        self::assertFileExists($path);
    }

    /** 3. Κανένα `signed_at` → η διαγραφή προχωράει κανονικά. */
    public function testDeletingAContractNeverSignedStillSucceeds(): void
    {
        [$contractId, $path] = $this->contractWithDocument();
        $this->events->record($contractId, 0, 'status_change', ['to_status' => 'registration']);

        $response = rest_do_request(new WP_REST_Request('DELETE', '/ecrm/v1/contracts/' . $contractId));

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertFileDoesNotExist($path);
    }

    /**
     * 4. Μαζική διαγραφή, μεικτή επιλογή: η μία υπογεγραμμένη μπλοκάρεται, η
     * άλλη σβήνεται κανονικά — ίδιο μοτίβο `rejected`/`notice` με το
     * `changeStatus()` της ίδιας κλάσης.
     */
    public function testBulkDeleteSkipsSignedContractsButRemovesTheRest(): void
    {
        [$signedId, $signedPath]     = $this->contractWithDocument();
        [$draftId, $draftPath]       = $this->contractWithDocument();
        $this->markSigned($signedId);

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts/bulk');
        $request->set_body_params(['action' => 'delete', 'ids' => [$signedId, $draftId]]);

        $response = rest_do_request($request);
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));
        self::assertSame(1, $data['updated'] ?? null);
        self::assertSame(1, $data['rejected'] ?? null);
        self::assertNotEmpty($data['notice'] ?? '');

        self::assertFileExists($signedPath, 'Η υπογεγραμμένη σύμβαση διαγράφηκε μέσα σε μαζική επιλογή.');
        self::assertFileDoesNotExist($draftPath, 'Η μη-υπογεγραμμένη σύμβαση δεν διαγράφηκε.');
    }

    /** 5. Μαζική διαγραφή, όλες υπογεγραμμένες: 409, τίποτα δεν αγγίζεται. */
    public function testBulkDeleteRefusesWhenEveryContractWasSigned(): void
    {
        [$firstId, $firstPath]   = $this->contractWithDocument();
        [$secondId, $secondPath] = $this->contractWithDocument();
        $this->markSigned($firstId);
        $this->markSigned($secondId);

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts/bulk');
        $request->set_body_params(['action' => 'delete', 'ids' => [$firstId, $secondId]]);

        $response = rest_do_request($request);

        self::assertSame(409, $response->get_status());
        self::assertFileExists($firstPath);
        self::assertFileExists($secondPath);
    }

    // --- fixtures ------------------------------------------------------------

    /** Γράφει `signed_at` απευθείας -- αυτό, και μόνο αυτό, κρίνει η πύλη. */
    private function markSigned(int $contractId): void
    {
        $this->contracts->update(
            $contractId,
            UserScope::forSelf($this->actor),
            ['signed_at' => gmdate('Y-m-d H:i:s')]
        );
    }

    /**
     * @return array{0:int, 1:string} id και απόλυτη διαδρομή του αρχείου
     */
    private function contractWithDocument(): array
    {
        $scope      = UserScope::forSelf($this->actor);
        $contractId = $this->contracts->create(['status' => 'draft'], $scope);

        self::assertGreaterThan(0, $contractId, 'Το fixture της σύμβασης δεν μπήκε.');

        $saved = ECRM_Files::put_bytes(
            'fixture bytes ' . wp_generate_password(8, false),
            'pdf',
            'application/pdf',
            'contract.pdf'
        );

        self::assertIsArray($saved, 'Δεν γράφτηκαν bytes στην προστατευμένη αποθήκευση.');

        $path = (string) $saved['path'];

        self::assertFileExists($path, 'Το fixture δεν έφτασε ποτέ στον δίσκο — το test δεν μετράει τίποτα.');

        $fileId = $this->files->attach($contractId, 'contract', 'contract.pdf', 'application/pdf', $path);

        self::assertGreaterThan(0, $fileId, 'Η γραμμή αρχείου δεν μπήκε.');

        return [$contractId, $path];
    }
}
