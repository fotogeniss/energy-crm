<?php

/**
 * Η διαγραφή σύμβασης φτάνει ως τον δίσκο — από τη διαδρομή REST, όχι μόνο από
 * το repository.
 *
 * ## Γιατί δεν αρκούσε ό,τι υπήρχε
 *
 * Το `FileRepositoryTest` δοκιμάζει την `purgeForContracts()` απευθείας και
 * περνά. Το `ContractRestAccessTest` δοκιμάζει τη διαδρομή DELETE, αλλά μόνο
 * τις **αρνήσεις** της — 403 και 404 — και δεν αγγίζει ποτέ επιτυχή διαγραφή με
 * αρχείο. Ανάμεσά τους έμενε ακάλυπτο ακριβώς αυτό που μετράει: **ότι ο
 * controller πράγματι καλεί το repository πριν σβήσει τη γραμμή.**
 *
 * Ίδιο σύνορο με τα υπόλοιπα ευρήματα της 16ης Αυγούστου: η μονάδα αποδεδειγμένη,
 * η καλωδίωση όχι, και η ζημιά ανάμεσα.
 *
 * ## Τι κοστίζει αν σπάσει
 *
 * Το `files.contract_id` είναι `ON DELETE CASCADE`: η γραμμή φεύγει μόνη της τη
 * στιγμή που φεύγει η σύμβαση. Αν τα bytes δεν έχουν σβηστεί πρώτα, το αρχείο
 * μένει στον δίσκο **χωρίς καμία γραμμή να δείχνει σε αυτό** — και τότε δεν
 * αποδίδεται πια σε πρόσωπο. Ο `PersonalDataEraser` δεν το φτάνει (δουλεύει από
 * τις γραμμές προς τα αρχεία), η εξαγωγή Άρθρου 15 δεν το αναφέρει, και αίτημα
 * Άρθρου 17 το αφήνει εκεί.
 *
 * Δεν είναι υποθετικό. Το `diagnose-orphan-documents.php` μέτρησε **81 τέτοια
 * αρχεία** στο τοπικό — 57 PDF και 24 εικόνες υπογραφών, 126 MB — υπολείμματα
 * της εποχής πριν το `cc946b0` (2026-08-04), όταν η διαγραφή έσβηνε μόνο
 * εγγραφές. Αυτά τα tests υπάρχουν ώστε η εποχή εκείνη να μην επιστρέψει
 * σιωπηλά.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Files;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class ContractDeleteBytesTest extends IntegrationTestCase
{
    private FileRepository $files;

    private ContractRepository $contracts;

    private int $actor;

    protected function setUp(): void
    {
        parent::setUp();

        // Ο ίδιος φάκελος που δίνει το Services::files() στην παραγωγή. Ο
        // constructor απαιτεί διαδρομή επίτηδες: το DocumentStorage αρνείται να
        // αγγίξει ό,τι δεν επιλύεται μέσα σε αυτήν, και μια προεπιλογή θα ήταν
        // δεύτερη εκδοχή του «ασφαλής διαδρομή».
        $this->files     = new FileRepository(ECRM_Files::dir());
        $this->contracts = new ContractRepository();
        $this->actor     = $this->makeCrmUser(Roles::PARTNER);

        // Ο Συνεργάτης δεν έχει πια DELETE_CONTRACT εξ ορισμού (v3, 25/08 —
        // δες Roles::matrix()). Αυτό το αρχείο δοκιμάζει τη ΜΗΧΑΝΙΚΗ της
        // διαγραφής (φεύγουν τα bytes μαζί με τη γραμμή, με σωστή σειρά) —
        // όχι ποιος επιτρέπεται να τη ζητήσει, αυτό το απαντά το
        // ContractRestAccessTest/ContractsBulkController. Το capability
        // δίνεται εδώ απευθείας στον χρήστη, ίδια λογική με το
        // ContractRestAccessTest::testAPartnerWithTheCapabilityStillCannotDeleteOutsideTheirScope().
        $this->grantDelete($this->actor);

        wp_set_current_user($this->actor);
    }

    private function grantDelete(int $userId): void
    {
        $user = get_user_by('id', $userId);

        self::assertInstanceOf(WP_User::class, $user);
        $user->add_cap(Capability::DELETE_CONTRACT);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** 1. DELETE /contracts/{id} παίρνει μαζί του τα bytes. */
    public function testDeletingAContractRemovesItsDocumentFromDisk(): void
    {
        [$contractId, $path] = $this->contractWithDocument();

        $response = rest_do_request(new WP_REST_Request('DELETE', '/ecrm/v1/contracts/' . $contractId));

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        self::assertFileDoesNotExist(
            $path,
            'Η σύμβαση διαγράφηκε και το έγγραφο έμεινε στον δίσκο — ορφανό, μη αποδώσιμο σε πρόσωπο.'
        );
    }

    /**
     * 2. Η μαζική διαγραφή το ίδιο.
     *
     * Δική της διαδρομή, δικός της καλών, και ιστορικά η μαζική ενέργεια είναι
     * αυτή που ξεχνιέται: το §6β καταγράφει ότι η μαζική Ανάθεση έμεινε σπασμένη
     * ακριβώς επειδή κανείς δεν την είχε δοκιμάσει ξεχωριστά.
     */
    public function testBulkDeleteRemovesDocumentsFromDisk(): void
    {
        [$contractId, $path] = $this->contractWithDocument();

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts/bulk');
        $request->set_body_params(['action' => 'delete', 'ids' => [$contractId]]);

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        self::assertFileDoesNotExist($path, 'Η μαζική διαγραφή άφησε το έγγραφο στον δίσκο.');
    }

    /**
     * 3. Άρνηση για scope δεν αγγίζει bytes — και αυτό είναι το επικίνδυνο.
     *
     * Η `purgeForContracts()` **δεν παίρνει scope**: σβήνει ό,τι id της δοθεί.
     * Την ασφάλεια τη φυλάει αποκλειστικά το ότι ο έλεγχος πρόσβασης τρέχει
     * πρώτος. Αν κάποιος αντιστρέψει τη σειρά, ένα DELETE σε ξένη σύμβαση θα
     * απαντούσε 404 **έχοντας ήδη καταστρέψει τα έγγραφά της** — η γραμμή θα
     * επιζούσε, τα bytes όχι, και το θύμα δεν θα το μάθαινε ποτέ.
     *
     * Κανένα άλλο test δεν θα κοκκίνιζε γι' αυτό: η απάντηση 404 θα ήταν σωστή.
     */
    public function testARefusedDeleteLeavesTheDocumentAlone(): void
    {
        [$contractId, $path] = $this->contractWithDocument();

        // Χρειάζεται ΚΑΙ αυτός το capability, αλλιώς η άρνηση θα ερχόταν από
        // το permission_callback (403, «δεν έχεις καν το δικαίωμα») αντί από
        // τον scope-έλεγχο του controller (404, «δεν υπάρχει για σένα») —
        // δύο διαφορετικές αρνήσεις, και αυτό το test δοκιμάζει ρητά τη
        // δεύτερη: ότι μια άρνηση scope δεν αγγίζει bytes πριν απαντήσει.
        $foreignPartner = $this->makeCrmUser(Roles::PARTNER);
        $this->grantDelete($foreignPartner);
        wp_set_current_user($foreignPartner);

        $response = rest_do_request(new WP_REST_Request('DELETE', '/ecrm/v1/contracts/' . $contractId));

        self::assertSame(404, $response->get_status(), 'Η ξένη σύμβαση δεν έπρεπε καν να βρεθεί.');

        self::assertFileExists(
            $path,
            'Απορρίφθηκε με 404 και όμως έσβησε τα bytes ξένης σύμβασης.'
        );
    }

    // --- fixtures ------------------------------------------------------------

    /**
     * Μια σύμβαση του δράστη, με πραγματικά bytes στην προστατευμένη αποθήκευση.
     *
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
