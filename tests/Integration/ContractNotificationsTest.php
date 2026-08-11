<?php

/**
 * Who gets told when something happens to a contract.
 *
 * Characterisation, written before the code moved: every assertion here
 * describes `ECRM_REST::notify()`, `notify_document()` and `notify_signed()`
 * exactly as they stand, so that the move can change one thing in this file —
 * the name of what gets called — and nothing else.
 *
 * ## Why this is worth pinning
 *
 * These three write the rows behind the bell in the interface. Nothing covered
 * them, and two things about them are easy to break by accident:
 *
 *   - The upline is notified as well as the owner, all the way to the top. A
 *     refactor that quietly notified only the owner would look correct in every
 *     manual test done by one person on one account.
 *   - The Greek wording is part of the behaviour. It is what the agent reads at
 *     a glance to decide whether to open the contract, and there is no other
 *     copy of it anywhere.
 *
 * The hierarchy is built here through `ecrm_parent`, which is the source of
 * truth for a single edge. `ecrm_path` is derived from it and maintained by
 * NetworkSync on the meta hooks, so setting the parent is enough — and that
 * matters for the next commit, where the walk up the chain is replaced by the
 * path.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_REST;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Persistence\Tables;

final class ContractNotificationsTest extends IntegrationTestCase
{
    private int $owner;

    private int $manager;

    private int $director;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        // director → manager → owner, three levels, because two would not tell
        // "the whole chain" apart from "the parent".
        $this->director = $this->makePartner();
        $this->manager  = $this->makePartnerUnder($this->director);
        $this->owner    = $this->makePartnerUnder($this->manager);

        $this->contractId = $this->makeContract();
    }

    public function testTheOwnerIsToldTheirCustomerSigned(): void
    {
        ECRM_REST::notify_signed($this->contractId, 'Γιώργος');

        $rows = $this->notificationsFor($this->owner);

        self::assertCount(1, $rows);
        self::assertSame('signed', $rows[0]['type']);
        self::assertSame($this->contractId, (int) $rows[0]['contract_id']);
    }

    /**
     * And so is everyone above them, to the top of the chain.
     *
     * This is the assertion a refactor is most likely to lose: a manager who
     * stops being told their team signed something finds out at payout time.
     */
    public function testEveryoneAboveTheOwnerIsToldAsWell(): void
    {
        ECRM_REST::notify_signed($this->contractId, 'Γιώργος');

        self::assertCount(1, $this->notificationsFor($this->manager));
        self::assertCount(1, $this->notificationsFor($this->director));
    }

    public function testTheSignedNoticeNamesTheContractAndTheCustomer(): void
    {
        ECRM_REST::notify_signed($this->contractId, 'Γιώργος');

        $row = $this->notificationsFor($this->owner)[0];

        self::assertStringContainsString('Υπεγράφη σύμβαση', (string) $row['title']);
        self::assertStringContainsString('ECRM-TEST-1', (string) $row['title']);
        self::assertStringContainsString('Παπαδόπουλος', (string) $row['body']);
    }

    /** The signer's name is added when the signing page knows it. */
    public function testTheSignersNameIsIncludedWhenThereIsOne(): void
    {
        ECRM_REST::notify_signed($this->contractId, 'Γιώργος');

        self::assertStringContainsString('(Γιώργος)', (string) $this->notificationsFor($this->owner)[0]['body']);
    }

    public function testADocumentUploadTellsTheOwnerAndTheUpline(): void
    {
        ECRM_REST::notify_document($this->contractId, 'Ταυτότητα');

        foreach ([$this->owner, $this->manager, $this->director] as $userId) {
            $rows = $this->notificationsFor($userId);

            self::assertCount(1, $rows);
            self::assertSame('document', $rows[0]['type']);
        }
    }

    public function testTheDocumentNoticeNamesTheDocument(): void
    {
        ECRM_REST::notify_document($this->contractId, 'Ταυτότητα');

        $row = $this->notificationsFor($this->owner)[0];

        self::assertStringContainsString('Νέο δικαιολογητικό', (string) $row['title']);
        self::assertStringContainsString('Ταυτότητα', (string) $row['body']);
    }

    /** Without a label it still says something happened, just not what. */
    public function testAnUnlabelledDocumentStillProducesANotice(): void
    {
        ECRM_REST::notify_document($this->contractId);

        self::assertStringContainsString('ανέβασε έγγραφο', (string) $this->notificationsFor($this->owner)[0]['body']);
    }

    public function testAContractThatDoesNotExistNotifiesNobody(): void
    {
        ECRM_REST::notify_signed($this->contractId + 100000);

        self::assertSame([], $this->notificationsFor($this->owner));
    }

    /**
     * A notification with no recipient is dropped rather than written.
     *
     * `user_id` is NOT NULL, so a row for user zero is a row nobody can ever
     * read and nobody can ever clear.
     */
    public function testANotificationForNobodyIsNotWritten(): void
    {
        ECRM_REST::notify(0, 'signed', 'Τίτλος', 'Κείμενο', $this->contractId);

        global $wpdb;

        self::assertSame(
            '0',
            (string) $wpdb->get_var(
                $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE user_id = 0', Tables::name(Tables::NOTIFICATIONS))
            )
        );
    }

    /** An unread notice is one with no read_at, which is what the bell counts. */
    public function testANewNoticeStartsUnread(): void
    {
        ECRM_REST::notify($this->owner, 'signed', 'Τίτλος', 'Κείμενο', $this->contractId);

        self::assertNull($this->notificationsFor($this->owner)[0]['read_at']);
    }

    // --- Fixtures ----------------------------------------------------------

    /**
     * A partner who reports to someone.
     *
     * Only `ecrm_parent` is set: `ecrm_path` is derived and NetworkSync keeps
     * it in step from the meta hook, so a fixture that wrote both would be
     * testing the fixture rather than the code.
     */
    private function makePartnerUnder(int $parentId): int
    {
        $userId = $this->makePartner();

        update_user_meta($userId, NetworkRepository::PARENT_META, $parentId);

        return $userId;
    }

    private function makeContract(): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'customer_id'     => (new CustomerRepository())->create($this->customerData()),
            'partner_user_id' => $this->owner,
            'status'          => 'new',
            'energy_type'     => 'power',
            'code'            => 'ECRM-TEST-1',
        ]);

        $contractId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $contractId, 'The contract fixture was not inserted.');

        return $contractId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notificationsFor(int $userId): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE user_id = %d ORDER BY id',
                Tables::name(Tables::NOTIFICATIONS),
                $userId
            ),
            ARRAY_A
        );

        return $rows;
    }
}
