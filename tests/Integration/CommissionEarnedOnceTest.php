<?php

/**
 * (404) Η προμήθεια κερδίζεται μία φορά: όταν η αίτηση γίνει Ενεργή.
 *
 * Απόφαση ιδιοκτήτη 16/11 (α): ό,τι έγινε ΠΟΤΕ Ενεργό είναι πληρωτέο, όπου
 * κι αν βρίσκεται τώρα -- ο ίδιος κανόνας με την ακύρωση (05/10). Ως τότε οι
 * Προμήθειες και οι Εκκαθαρίσεις κοίταζαν μόνο την τωρινή κατάσταση, και ο
 * μήνας της προμήθειας ήταν η τελευταία αλλαγή της αίτησης.
 *
 * @kanonas K5
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\AnalyticsRepository;
use EnergyCRM\Persistence\CommissionRepository;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\PayableClause;
use EnergyCRM\Persistence\Tables;

final class CommissionEarnedOnceTest extends IntegrationTestCase
{
    private int $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = $this->makeCrmUser(Roles::SELLER);
    }

    public function testActiveThatMovedOnStillEarns(): void
    {
        $issue     = $this->contract('pending_issue', ['presale', 'active', 'pending_issue']);
        $cancelled = $this->contract('cancelled', ['presale', 'active', 'cancelled']);
        $never     = $this->contract('pending_issue', ['presale', 'pending_issue']);
        $active    = $this->contract('active', ['presale', 'active']);

        $ids = array_map('intval', array_column($this->payable(), 'id'));
        sort($ids);

        $expected = [$issue, $cancelled, $active];
        sort($expected);

        self::assertSame($expected, $ids, 'Ό,τι έγινε ποτέ Ενεργό, μόνο αυτό.');
        self::assertNotContains($never, $ids);
        self::assertSame(3, $this->commissions()->countPayable($this->scope(), ['active']));
    }

    public function testExpectedCommissionDoesNotCountWhatAlreadyEarned(): void
    {
        $this->contract('pending_issue', ['presale', 'active', 'pending_issue']);
        $inFlight = $this->contract('pending_issue', ['presale', 'pending_issue']);

        $rows = $this->commissions()->inProgress(
            $this->scope(),
            ContractStatus::intermediateValues(),
            2000,
            ['active']
        );

        self::assertCount(1, $rows, 'Η κερδισμένη μετρά στις πληρωτέες, όχι και στις αναμενόμενες.');
        self::assertGreaterThan(0, $inFlight);
    }

    public function testTheMonthIsWhenItWasEarnedNotTheLastEdit(): void
    {
        global $wpdb;

        $id = $this->contract('active', ['presale', 'active']);

        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET created_at = '2026-10-05 12:00:00' WHERE contract_id = %d AND to_status = 'active'",
            Tables::name(Tables::EVENTS),
            $id
        ));
        $wpdb->update(Tables::name(Tables::CONTRACTS), ['updated_at' => '2026-11-16 09:00:00'], ['id' => $id]);

        $row = $this->payable()[0];

        self::assertSame(
            '2026-10',
            PayableClause::monthOf($row),
            'Μια διόρθωση τον Νοέμβριο δεν μετακομίζει την προμήθεια.'
        );
    }

    public function testStatisticsSkipDraftsAndDiscardedDrafts(): void
    {
        $this->contract('draft', ['draft']);
        $this->contract('cancelled', ['draft', 'cancelled']);
        $this->contract('cancelled', ['presale', 'cancelled']);
        $this->contract('presale', ['presale']);

        $analytics = new AnalyticsRepository();
        $counts    = $analytics->countsByStatus($this->scope());

        self::assertSame(1, $counts['cancelled'] ?? 0, 'Το πεταμένο πρόχειρο δεν είναι ακύρωση.');
        $byType = array_map('intval', array_column($analytics->byEnergyType($this->scope()), 'c'));
        self::assertSame(2, array_sum($byType));
    }

    /** @param list<string> $path */
    private function contract(string $status, array $path): int
    {
        global $wpdb;

        $id = (new ContractRepository())->create(
            ['status' => $path[0], 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->seller)
        );
        self::assertGreaterThan(0, $id);

        $events = new EventRepository();
        $events->record($id, $this->seller, 'created', ['to_status' => $path[0]]);

        for ($i = 1; $i < count($path); $i++) {
            $events->record($id, $this->seller, 'status_change', [
                'from_status' => $path[$i - 1],
                'to_status'   => $path[$i],
            ]);
        }

        $wpdb->update(Tables::name(Tables::CONTRACTS), ['status' => $status], ['id' => $id]);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function payable(): array
    {
        return $this->commissions()->payable($this->scope(), ['active']);
    }

    private function commissions(): CommissionRepository
    {
        return new CommissionRepository();
    }

    private function scope(): UserScope
    {
        return UserScope::forSelf($this->seller);
    }
}
