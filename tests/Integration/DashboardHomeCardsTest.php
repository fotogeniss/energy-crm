<?php

/**
 * (381) Αρχική με κάρτες: οι νέοι αριθμοί του `/dashboard`.
 *
 * 1. `closed_prev_month`: ενεργοποιήσεις μέσα στο ΚΛΕΙΣΤΟ παράθυρο του
 *    προηγούμενου μήνα -- ούτε οι φετινές του μήνα ούτε οι παλαιότερες.
 * 2. `byProgramSince()`: αιτήσεις ανά πρόγραμμα, οι περισσότερες πρώτες,
 *    χωρίς τα πρόχειρα και χωρίς αιτήσεις άλλου συνεργάτη.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\DashboardRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\Tables;

final class DashboardHomeCardsTest extends IntegrationTestCase
{
    private DashboardRepository $dashboard;

    private ContractRepository $contracts;

    private int $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboard = new DashboardRepository();
        $this->contracts = new ContractRepository();
        $this->partner   = $this->makeCrmUser();

        wp_set_current_user($this->partner);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testThePreviousMonthCountsOnlyItsOwnWindow(): void
    {
        global $wpdb;

        $monthStart = (string) $wpdb->get_var('SELECT NOW() - INTERVAL 10 DAY');
        $prevStart  = (string) $wpdb->get_var('SELECT NOW() - INTERVAL 40 DAY');

        $this->activatedDaysAgo($this->contract('active'), 5);   // αυτόν τον μήνα
        $this->activatedDaysAgo($this->contract('active'), 20);  // τον προηγούμενο
        $this->activatedDaysAgo($this->contract('active'), 25);  // τον προηγούμενο
        $this->activatedDaysAgo($this->contract('active'), 60);  // πιο παλιά

        $tiles = $this->dashboard->tiles($this->partner, $monthStart, $prevStart);

        self::assertSame(1, $tiles['closed_month']);
        self::assertSame(2, $tiles['closed_prev_month']);
    }

    public function testWithoutAPreviousWindowThereIsNothingToCompare(): void
    {
        global $wpdb;

        $this->activatedDaysAgo($this->contract('active'), 20);

        $tiles = $this->dashboard->tiles($this->partner, (string) $wpdb->get_var('SELECT NOW() - INTERVAL 10 DAY'));

        self::assertSame(0, $tiles['closed_prev_month']);
    }

    public function testProgramsAreRankedWithoutDraftsOrOtherPartners(): void
    {
        global $wpdb;

        // Σταθερό «από», όχι σχετικό με το ρολόι της βάσης: το τεστ ελέγχει
        // την κατάταξη, όχι το παράθυρο ημερομηνιών.
        $since = '2000-01-01 00:00:00';
        $sure  = $this->program('Δοκιμή Sure', 'fixed');
        $flex  = $this->program('Δοκιμή Flex', 'variable');

        $this->contract('presale', $sure);
        $this->contract('active', $sure);
        $this->contract('awaiting_signature', $flex);
        $this->contract('draft', $flex);
        $this->contract('draft', $flex);

        $other = $this->makeCrmUser();
        $this->contracts->create(
            ['status' => 'presale', 'supply_number' => '12345678901', 'energy_type' => 'power', 'program_id' => $flex],
            UserScope::forSelf($other)
        );

        $stored = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE partner_user_id = %d AND program_id IN (%d, %d)',
                Tables::name(Tables::CONTRACTS),
                $this->partner,
                $sure,
                $flex
            )
        );
        self::assertSame(5, $stored, 'Οι αιτήσεις του τεστ δεν γράφτηκαν με το πρόγραμμά τους.');

        $rows = $this->dashboard->byProgramSince($this->partner, $since);
        self::assertSame('', $wpdb->last_error, 'Το ερώτημα του byProgramSince() απέτυχε.');

        self::assertSame(['Δοκιμή Sure', 'Δοκιμή Flex'], array_column($rows, 'name'));
        self::assertSame([2, 1], array_column($rows, 'c'));
        self::assertSame('fixed', $rows[0]['price_type']);
    }

    private function contract(string $status, int $program = 0): int
    {
        global $wpdb;

        $data = ['status' => $status, 'supply_number' => '12345678901', 'energy_type' => 'power'];

        if ($program > 0) {
            $data['program_id'] = $program;
        }

        $id = $this->contracts->create($data, UserScope::forSelf($this->partner));
        self::assertGreaterThan(0, $id);

        if ($program > 0) {
            // Το πρόγραμμα γράφεται ρητά, όποιο κι αν είναι το φίλτρο του create().
            $wpdb->update(Tables::name(Tables::CONTRACTS), ['program_id' => $program], ['id' => $id]);
        }

        return $id;
    }

    private function program(string $name, string $priceType): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => 0,
            'name'        => $name,
            'energy_type' => 'power',
            'price_type'  => $priceType,
            'active'      => 1,
        ]);

        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id, 'Το πρόγραμμα του τεστ δεν μπήκε: ' . $wpdb->last_error);

        return $id;
    }

    private function activatedDaysAgo(int $contractId, int $days): void
    {
        global $wpdb;

        (new EventRepository())->record($contractId, 0, 'status_change', ['to_status' => 'active']);

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET created_at = NOW() - INTERVAL %d DAY WHERE contract_id = %d ORDER BY id DESC LIMIT 1',
                Tables::name(Tables::EVENTS),
                $days,
                $contractId
            )
        );
    }
}
