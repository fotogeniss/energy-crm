<?php

/**
 * `ECRM_Import::apply()` — ο μαζικός writer πίσω από `/import/apply`.
 *
 * AUDIT εύρημα 2.5 (EKKREMI-29-08.html): «μαζικός writer χωρίς test
 * εμβέλειας». Ο κώδικας ήδη φιλτράρει το ταίριασμα με
 * `partner_user_id IN (...)` πάνω σε `ECRM_DB::visible_user_ids()` (γραμμή
 * 217/264 του `includes/class-ecrm-import.php`) -- δηλαδή ένας συνεργάτης
 * δεν μπορεί να ενημερώσει σύμβαση εκτός της δικής του downline απλά επειδή
 * ο αριθμός παροχής ταιριάζει. Αυτό το αρχείο είναι το πρώτο test που
 * αποδεικνύει ότι αυτό το φίλτρο πράγματι δουλεύει -- πριν από αυτό, η μόνη
 * κάλυψη (`ImportProviderNoteTest`) δοκίμαζε το μήνυμα του παρόχου, ποτέ την
 * εμβέλεια.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Import;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Services;

final class ImportApplyScopeTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * The exact scenario the scope filter exists for: a supply number that
     * genuinely matches, but belongs to someone else's contract. It must
     * come back as unmatched, not silently updated -- a partner running an
     * import for their own book must not be able to move a stranger's
     * contract by feeding it the right supply number.
     */
    public function testASupplyNumberOwnedByAnotherPartnerIsNotMatched(): void
    {
        $stranger   = $this->makeCrmUser(Roles::SELLER);
        $contractId = $this->contractWith($stranger, 'new', '22200000001');

        $importer = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($importer);

        $report = ECRM_Import::apply(
            [['supply' => '22200000001', 'status' => 'processing']],
            false
        );

        self::assertSame(0, $report['matched']);
        self::assertSame(0, $report['updated']);
        self::assertSame(['22200000001'], $report['unmatched']);
        self::assertSame('new', $this->contracts->find($contractId, UserScope::forSelf($stranger))['status']);
    }

    /** Control: the same importer's own contract IS matched and moved. */
    public function testTheImportersOwnContractIsMatchedAndUpdated(): void
    {
        $importer   = $this->makeCrmUser(Roles::SELLER);
        $contractId = $this->contractWith($importer, 'new', '22200000002');

        wp_set_current_user($importer);

        $report = ECRM_Import::apply(
            [['supply' => '22200000002', 'status' => 'processing']],
            false
        );

        self::assertSame(1, $report['matched']);
        self::assertSame(1, $report['updated']);
        self::assertSame('processing', $this->contracts->find($contractId, UserScope::forSelf($importer))['status']);
    }

    /**
     * Control the other direction: a manager importing on behalf of their
     * downline must still reach a partner's contract -- the filter is scope,
     * not "only your own rows".
     */
    public function testAManagerCanMatchAContractInTheirDownline(): void
    {
        $manager  = $this->makeCrmUser(Roles::PARTNER);
        $partner  = $this->makeCrmUser(Roles::SELLER);

        update_user_meta($partner, NetworkRepository::PARENT_META, $manager);
        (new NetworkRepository())->rebuild($partner);

        $contractId = $this->contractWith($partner, 'new', '22200000003');

        wp_set_current_user($manager);

        $report = ECRM_Import::apply(
            [['supply' => '22200000003', 'status' => 'processing']],
            false
        );

        self::assertSame(1, $report['updated']);
        $managerScope = Services::scopeResolver()->forUser($manager);
        self::assertSame('processing', $this->contracts->find($contractId, $managerScope)['status']);
    }

    /** The dry-run promise: counted as matched/updated, nothing actually moves. */
    public function testDryRunLeavesTheStatusUntouched(): void
    {
        $importer   = $this->makeCrmUser(Roles::SELLER);
        $contractId = $this->contractWith($importer, 'new', '22200000004');

        wp_set_current_user($importer);

        $report = ECRM_Import::apply(
            [['supply' => '22200000004', 'status' => 'processing']],
            true
        );

        self::assertSame(1, $report['updated'], 'The preview count still reports what WOULD change.');
        self::assertSame('new', $this->contracts->find($contractId, UserScope::forSelf($importer))['status']);
    }

    private function contractWith(int $partnerId, string $status, string $supply): int
    {
        $id = $this->contracts->create(
            ['status' => $status, 'supply_number' => $supply, 'energy_type' => 'power'],
            UserScope::forSelf($partnerId)
        );

        self::assertGreaterThan(0, $id);

        return $id;
    }
}
