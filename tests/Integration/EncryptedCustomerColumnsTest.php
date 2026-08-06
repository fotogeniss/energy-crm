<?php

/**
 * The tests that would have caught the encryption work going wrong.
 *
 * Fifteen read paths were changed to decrypt, by hand, with nothing verifying
 * any of them. A column encrypted on write but forgotten on read shows an
 * agent base64 where a tax number should be — and it shows it in front of a
 * customer, not in a build.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;

final class EncryptedCustomerColumnsTest extends IntegrationTestCase
{
    private CustomerRepository $customers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customers = new CustomerRepository();
    }

    public function testTheTaxNumberIsUnreadableOnDiskAndReadableThroughTheRepository(): void
    {
        $this->encryptionOn();

        $id = $this->customers->create($this->customerData());
        self::assertGreaterThan(0, $id);

        $stored = $this->storedRow(Tables::CUSTOMERS, $id);

        self::assertTrue(FieldCipher::isEncrypted((string) $stored['afm']));
        self::assertStringNotContainsString('123456789', (string) $stored['afm']);
        self::assertStringNotContainsString('ΑΒ123456', (string) $stored['adt']);
        self::assertStringNotContainsString('Αγίου Δημητρίου', (string) $stored['street']);

        $read = $this->customers->find($id, UserScope::forAdministrator($this->makePartner()));

        self::assertNotNull($read);
        self::assertSame('123456789', $read['afm']);
        self::assertSame('ΑΒ123456', $read['adt']);
        self::assertSame('Αγίου Δημητρίου', $read['street']);
        self::assertSame('14', $read['street_no']);
    }

    /** A county groups a report; a street number identifies a household. */
    public function testColumnsUsedForReportingStayInTheClear(): void
    {
        $this->encryptionOn();

        $id     = $this->customers->create($this->customerData());
        $stored = $this->storedRow(Tables::CUSTOMERS, $id);

        self::assertSame('Θεσσαλονίκη', $stored['city']);
        self::assertSame('Θεσσαλονίκης', $stored['region']);
    }

    /**
     * The tolerance that lets encryption arrive without a flag day: rows
     * written before the switch was flipped must keep working.
     */
    public function testRowsWrittenBeforeEncryptionAreStillReadable(): void
    {
        $plaintextId = $this->customers->create($this->customerData('987654321'));

        $this->encryptionOn();

        $read = $this->customers->find($plaintextId, UserScope::forAdministrator($this->makePartner()));

        self::assertNotNull($read);
        self::assertSame('987654321', $read['afm']);
    }

    /**
     * The blind index earning its place. Without it this lookup returns
     * nothing once the column is encrypted, and an agent reads "no duplicate"
     * as permission to file a second application.
     */
    public function testDuplicateDetectionStillFindsAnEncryptedTaxNumber(): void
    {
        $this->encryptionOn();

        $partner    = $this->makePartner();
        $scope      = UserScope::forAdministrator($partner);
        $customerId = $this->customers->create($this->customerData());

        $this->giveCustomerAContract($customerId, $partner);

        $duplicates = $this->customers->duplicatesOf($scope, '123456789', '');

        self::assertCount(1, $duplicates);
        self::assertSame('123456789', $duplicates[0]['afm']);
    }

    public function testTheHashIsMaintainedEvenWhileEncryptionIsOff(): void
    {
        $id     = $this->customers->create($this->customerData());
        $stored = $this->storedRow(Tables::CUSTOMERS, $id);

        $expected = (new CustomerFields(new FieldCipher(wp_salt('secure_auth'))))->index('123456789');

        self::assertSame($expected, $stored[CustomerFields::INDEX_COLUMN]);
    }

    /**
     * The hash is how we find the row, not part of it. Leaking it into an API
     * response would hand out a stable identifier for a tax number.
     */
    public function testTheHashNeverLeavesThePersistenceLayer(): void
    {
        $id   = $this->customers->create($this->customerData());
        $read = $this->customers->find($id, UserScope::forAdministrator($this->makePartner()));

        self::assertNotNull($read);
        self::assertArrayNotHasKey(CustomerFields::INDEX_COLUMN, $read);
    }

    private function giveCustomerAContract(int $customerId, int $partnerId): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'customer_id'     => $customerId,
            'partner_user_id' => $partnerId,
            'status'          => 'new',
            'supply_number'   => '12345678901',
        ]);

        return (int) $wpdb->insert_id;
    }
}
