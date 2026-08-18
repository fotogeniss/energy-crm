<?php

/**
 * The search box of the Excel export, once the ΑΦΜ is ciphertext.
 *
 * The export claims in its own docblock to honour "the same status + search
 * filters as the list view". It did not: the list matches the ΑΦΜ through the
 * blind index as well as the column, the export only through the column. With
 * randomised encryption a column never equals itself, so the export answered
 * "no such contract" to a search the screen beside it had just answered with a
 * row. Same field, two behaviours — which is the failure mode this suite exists
 * to make loud.
 *
 * The name case is here for the same reason the ΑΦΜ case is: it is the half of
 * the WHERE clause that already worked, and a fix to the other half must not
 * cost it.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Export;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;

final class ExportSearchTest extends IntegrationTestCase
{
    /** Only for the fixtures: creating a contract is not this class's subject. */
    private ContractRepository $contracts;

    private CustomerRepository $customers;

    private int $alice;

    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->customers = new CustomerRepository();

        $this->alice = $this->makePartner();
        $this->bob   = $this->makePartner();
    }

    /** The whole ΑΦΜ still reaches the export once the column is ciphertext. */
    public function testTheExportFindsAnEncryptedTaxNumberInFull(): void
    {
        $this->encryptionOn();

        $this->contractFor($this->alice, ['status' => 'new'], $this->customerData('987654321'));

        $rows = $this->rowsFor($this->alice, '987654321');

        self::assertCount(1, $rows);
        self::assertSame('Γιώργος Παπαδόπουλος', $rows[0][0]);
    }

    /**
     * A tax number that belongs to nobody returns nothing.
     *
     * Without this the test above passes on a WHERE clause that matches every
     * row — the way a broken filter usually looks from the outside.
     */
    public function testAnUnknownTaxNumberReturnsNothing(): void
    {
        $this->encryptionOn();

        $this->contractFor($this->alice, ['status' => 'new'], $this->customerData('987654321'));

        self::assertSame([], $this->rowsFor($this->alice, '111111111'));
    }

    /** Searching by name was never broken, and stays that way. */
    public function testTheExportStillFindsACustomerByName(): void
    {
        $this->encryptionOn();

        $this->contractFor($this->alice, ['status' => 'new'], $this->customerData('987654321'));

        self::assertCount(1, $this->rowsFor($this->alice, 'Παπαδόπουλος'));
    }

    /** The ΑΦΜ opens no door around the scope: it is one condition among many. */
    public function testTheTaxNumberDoesNotReachAnotherPartnersContract(): void
    {
        $this->encryptionOn();

        $this->contractFor($this->bob, ['status' => 'new'], $this->customerData('987654321'));

        self::assertSame([], $this->rowsFor($this->alice, '987654321'));
    }

    // --- fixtures ----------------------------------------------------------

    /**
     * The export's data rows for one partner and one search term.
     *
     * @return array<int, array<int, string>>
     */
    private function rowsFor(int $ownerId, string $term): array
    {
        $dataset = ECRM_Export::contracts_dataset('', $term, [], [$ownerId]);

        return $dataset['rows'];
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $customer
     */
    private function contractFor(int $ownerId, array $data, array $customer = []): int
    {
        if ($customer !== []) {
            $data['customer_id'] = $this->customers->create($customer);
        }

        $contractId = $this->contracts->create($data, UserScope::forSelf($ownerId));

        self::assertGreaterThan(0, $contractId, 'The contract fixture was not inserted.');

        return $contractId;
    }
}
