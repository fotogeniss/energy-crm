<?php

/**
 * `CommissionRepository::countPayable()` / `payable()` -- το LIMIT 2000.
 *
 * AUDIT εύρημα §2.5 (EKKREMI-29-08.html): "countPayable() -- γνωστή,
 * γραμμένη, 'σιωπηλά λάθος ευρώ' περίπτωση πέρα από το LIMIT 2.000". Το
 * `CommissionsController::index()` ήδη υπολογίζει `truncated = available >
 * count(rows)` για να το δείξει στην οθόνη (δικό του σχόλιο: "Το LIMIT της
 * payable() δεν φαινόταν πουθενά ... Τώρα η οθόνη ξέρει ότι κοιτάζει
 * μέρος") -- αλλά ΚΑΝΕΝΑ test δεν υπήρχε ποτέ για ολόκληρο αυτό το αρχείο.
 * Το `payable()` δέχεται ήδη `$limit` ως παράμετρο, οπότε δεν χρειάζονται
 * 2000 πραγματικές γραμμές για να αποδειχθεί η περικοπή -- ένα μικρό,
 * ρητό limit πάνω σε λίγα δεδομένα αναπαράγει το ίδιο ακριβώς σχήμα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\CommissionRepository;
use EnergyCRM\Persistence\ContractRepository;

final class CommissionsPayableLimitTest extends IntegrationTestCase
{
    private CommissionRepository $commissions;

    private ContractRepository $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->commissions = new CommissionRepository();
        $this->contracts   = new ContractRepository();
    }

    /**
     * The whole point of countPayable() existing separately from payable():
     * it must keep counting past whatever limit payable() was capped at.
     */
    public function testCountPayableIgnoresTheLimitAndCountsEveryPayableContract(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        for ($i = 0; $i < 5; $i++) {
            $this->payableContractFor($partner, '77700000' . $i);
        }

        $scope = UserScope::forSelf($partner);

        self::assertSame(5, $this->commissions->countPayable($scope, ['active']));
    }

    /**
     * The truncation itself: with more payable contracts than the limit,
     * payable() must return exactly the limit while countPayable() still
     * reports the true total -- the gap between the two IS the "truncated"
     * signal CommissionsController shows on screen.
     */
    public function testPayableStopsAtTheLimitWhileCountPayableSeesThemAll(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        for ($i = 0; $i < 5; $i++) {
            $this->payableContractFor($partner, '77700001' . $i);
        }

        $scope = UserScope::forSelf($partner);

        $rows      = $this->commissions->payable($scope, ['active'], 2);
        $available = $this->commissions->countPayable($scope, ['active']);

        self::assertCount(2, $rows, 'payable() must respect the limit it was called with.');
        self::assertSame(5, $available);
        self::assertGreaterThan(
            count($rows),
            $available,
            'This gap is exactly what CommissionsController turns into "truncated": true.'
        );
    }

    /** A stranger's payable contracts must not inflate the count. */
    public function testCountPayableIsScopedNotGlobal(): void
    {
        $stranger = $this->makeCrmUser(Roles::SELLER);
        $this->payableContractFor($stranger, '77700002' . '0');

        $partner = $this->makeCrmUser(Roles::SELLER);
        $this->payableContractFor($partner, '77700002' . '1');

        $scope = UserScope::forSelf($partner);

        self::assertSame(1, $this->commissions->countPayable($scope, ['active']));
    }

    private function payableContractFor(int $partnerId, string $supply): int
    {
        $id = $this->contracts->create(
            ['status' => 'active', 'supply_number' => $supply, 'energy_type' => 'power'],
            UserScope::forSelf($partnerId)
        );

        self::assertGreaterThan(0, $id);

        return $id;
    }
}
