<?php

/**
 * (421, 422) Η αναζήτηση συγκρίνει ΑΦΜ, αριθμό παροχής και τηλέφωνο με τον ίδιο
 * κανόνα που τα αποθηκεύει.
 *
 * Πριν: «090 003 373» δεν έβρισκε τον πελάτη όταν το ΑΦΜ είναι κρυπτογραφημένο,
 * ο αριθμός παροχής με κενά/παύλες δεν έβρισκε την αίτηση, και το κινητό
 * έβρισκε μόνο στη γρήγορη αναζήτηση, όχι στις λίστες.
 *
 * @kanonas K11
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;

final class SearchFindsWhatWasTypedTest extends IntegrationTestCase
{
    private const AFM = '090003373';

    private int $seller;

    private int $customerId;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->seller     = $this->makePartner();
        $this->customerId = (new CustomerRepository())->create([
            'first_name' => 'Αναζήτηση',
            'last_name'  => 'Πελάτη',
            'afm'        => self::AFM,
            'mobile'     => '6971234567',
            'phone'      => '210 123 4567',
        ]);

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'customer_id'     => $this->customerId,
            'partner_user_id' => $this->seller,
            'status'          => 'draft',
            'code'            => 'ΕΝ-SRCH-' . $this->customerId,
            'supply_number'   => '1 23456 78901',
            'energy_type'     => 'power',
        ]);
        $this->contractId = (int) $wpdb->insert_id;
    }

    public function testTheAfmIsFoundWithSpacesOrALabel(): void
    {
        $customers = new CustomerRepository();
        $scope     = UserScope::forSelf($this->seller);

        self::assertContains($this->customerId, $this->ids($customers->search($scope, '090 003 373')));
        self::assertContains($this->customerId, $this->ids($customers->search($scope, 'ΑΦΜ 090003373')));
        self::assertContains($this->contractId, $this->ids((new ContractQueries())->search($scope, '', '090 003 373')));
    }

    public function testTheSupplyNumberIsFoundWithoutItsSpacesOrDashes(): void
    {
        $queries = new ContractQueries();
        $scope   = UserScope::forSelf($this->seller);

        self::assertContains($this->contractId, $this->ids($queries->search($scope, '', '12345678901')));
        self::assertContains($this->contractId, $this->ids($queries->search($scope, '', '123-456')));
        self::assertContains($this->contractId, $this->ids($queries->quickSearch($scope, '12345678901')));
    }

    public function testTheMobileIsFoundInTheLists(): void
    {
        $scope = UserScope::forSelf($this->seller);

        self::assertContains($this->customerId, $this->ids((new CustomerRepository())->search($scope, '6971234')));
        self::assertContains(
            $this->customerId,
            $this->ids((new CustomerRepository())->page($scope, '6971234', 'all', 'recent', 1, 50)['rows'])
        );
        self::assertContains($this->contractId, $this->ids((new ContractQueries())->search($scope, '', '6971234')));
    }

    /** (422) Το σταθερό, όπως κι αν γράφτηκε, μέσα από το `phone_hash`. */
    public function testThePhoneIsFoundHoweverItIsWritten(): void
    {
        $this->encryptionOn();

        // Ξαναγράφεται κρυπτογραφημένο: έτσι το LIKE δεν βρίσκει τίποτα και
        // μετράει μόνο το hash.
        (new CustomerRepository())->update(
            $this->customerId,
            UserScope::forAdministrator(1),
            ['phone' => '210 123 4567', 'mobile' => '697 123 4567']
        );
        $customers = new CustomerRepository();
        $scope     = UserScope::forSelf($this->seller);

        foreach (['2101234567', '+30 210 1234567', '210-123-4567'] as $typed) {
            self::assertContains($this->customerId, $this->ids($customers->search($scope, $typed)), $typed);
        }

        // Το κινητό (απλό κείμενο) γράφτηκε με κενά: ψηφία με ψηφία.
        $queries = new ContractQueries();

        foreach (['6971234567', '+30 6971234567', '697-1234'] as $typed) {
            self::assertContains($this->customerId, $this->ids($customers->search($scope, $typed)), $typed);
            self::assertContains($this->contractId, $this->ids($queries->search($scope, '', $typed)), $typed);
            self::assertContains($this->contractId, $this->ids($queries->quickSearch($scope, $typed)), $typed);
        }
    }

    public function testASeparatorAloneDoesNotMatchEverything(): void
    {
        // Χωρίς τη φύλαξη του supplyLike(), το «/» γινόταν «%%» και έφερνε όλες.
        $rows = (new ContractQueries())->search(UserScope::forSelf($this->seller), '', '/');

        self::assertNotContains($this->contractId, $this->ids($rows));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
