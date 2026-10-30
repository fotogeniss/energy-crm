<?php

/**
 * (330) Μια αίτηση ρεύματος που γίνεται «Ενεργή» αποκτά λήξη για τις Ανανεώσεις.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Infrastructure\ActivationTerm;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\ContractTransitions;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;

final class ActivationTermTest extends IntegrationTestCase
{
    public function testActivePowerWithoutDatesGetsATwelveMonthTerm(): void
    {
        $id  = $this->contract('power', []);
        $row = $this->activate($id);

        self::assertSame(current_time('Y-m-d'), substr((string) $row['start_date'], 0, 10));
        self::assertSame(12, (int) $row['term_months']);
        self::assertNotEmpty($row['end_date']);
    }

    public function testAnEndDateTypedByHandIsLeftAlone(): void
    {
        $id  = $this->contract('gas', ['start_date' => '2026-01-10', 'term_months' => 12, 'end_date' => '2026-12-31']);
        $row = $this->activate($id);

        self::assertSame('2026-12-31', substr((string) $row['end_date'], 0, 10));
    }

    public function testMobileIsNotTouched(): void
    {
        $row = $this->activate($this->contract('mobile', []));

        self::assertEmpty($row['end_date']);
    }

    /**
     * @param array<string, mixed> $dates
     */
    private function contract(string $energy, array $dates): int
    {
        $partner  = $this->makeCrmUser();
        $customer = (new CustomerRepository())->create($this->customerData());

        $id = (new ContractRepository())->create(array_merge([
            'status' => 'presale', 'energy_type' => $energy, 'customer_id' => $customer,
        ], $dates), UserScope::forSelf($partner));
        self::assertGreaterThan(0, $id);

        return $id;
    }

    /** @return array<string, mixed> */
    private function activate(int $id): array
    {
        global $wpdb;

        (new ActivationTerm(new ContractTransitions()))->onStatusChanged($id, 'active');

        return (array) $wpdb->get_row(
            $wpdb->prepare(
                'SELECT start_date, term_months, end_date FROM %i WHERE id = %d',
                Tables::name(Tables::CONTRACTS),
                $id
            ),
            ARRAY_A
        );
    }
}
