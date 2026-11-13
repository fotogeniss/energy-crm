<?php

/**
 * (377) Πελάτες σε σελίδες: σύνολο, μετρητές φίλτρων, ταξινόμηση.
 *
 * Το παλιό `search()` σταματούσε σιωπηλά στους 500. Εδώ φυλάγεται ότι η
 * σελίδα λέει πόσοι είναι συνολικά και ότι οι επόμενοι βρίσκονται.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;

final class CustomersPageTest extends IntegrationTestCase
{
    private int $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = $this->makeCrmUser(Roles::SELLER);
    }

    public function testPagesCoverEveryCustomerWithTheTotal(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->customer('Πελάτης' . $i, 'home', 'power', '69000000' . $i . '0');
        }

        $repo  = new CustomerRepository();
        $scope = UserScope::forSelf($this->seller);
        $one   = $repo->page($scope, '', 'all', 'name', 1, 2);
        $three = $repo->page($scope, '', 'all', 'name', 3, 2);

        self::assertSame(5, $one['total']);
        self::assertCount(2, $one['rows']);
        self::assertCount(1, $three['rows']);
        self::assertSame('Πελάτης5', $three['rows'][0]['last_name']);
    }

    public function testFiltersAndCounts(): void
    {
        $this->customer('Οικιακός', 'home', 'power', '6900000001');
        $this->customer('Επαγγελματίας', 'business', 'gas', '');

        $repo  = new CustomerRepository();
        $scope = UserScope::forSelf($this->seller);
        $all   = $repo->page($scope, '', 'all', 'recent', 1, 50);
        $biz   = $repo->page($scope, '', 'business', 'recent', 1, 50);
        $noTel = $repo->page($scope, '', 'nophone', 'recent', 1, 50);

        self::assertSame(['all' => 2, 'home' => 1, 'business' => 1, 'nophone' => 1], $all['counts']);
        self::assertSame(1, $biz['total']);
        self::assertSame('Επαγγελματίας', $biz['rows'][0]['last_name']);
        self::assertSame('1', (string) $biz['rows'][0]['has_gas']);
        self::assertSame('0', (string) $biz['rows'][0]['has_power']);
        self::assertSame('Επαγγελματίας', $noTel['rows'][0]['last_name']);
    }

    public function testSortByContracts(): void
    {
        $one = $this->customer('Ένα', 'home', 'power', '');
        $two = $this->customer('Δύο', 'home', 'power', '');
        $this->contract($two, 'home', 'gas');

        $scope = UserScope::forSelf($this->seller);
        $rows  = (new CustomerRepository())->page($scope, '', 'all', 'contracts', 1, 50)['rows'];

        self::assertSame($two, (int) $rows[0]['id']);
        self::assertSame($one, (int) $rows[1]['id']);
    }

    private function customer(string $lastName, string $category, string $energy, string $phone): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CUSTOMERS), [
            'first_name' => 'Δοκιμή',
            'last_name'  => $lastName,
            'phone'      => $phone,
        ]);
        $id = (int) $wpdb->insert_id;
        $this->contract($id, $category, $energy);

        return $id;
    }

    private function contract(int $customerId, string $category, string $energy): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $this->seller,
            'customer_id'     => $customerId,
            'status'          => 'registration',
            'energy_type'     => $energy,
            'category'        => $category,
            'code'            => 'ECRM-PG-' . wp_generate_password(8, false),
        ]);
    }
}
