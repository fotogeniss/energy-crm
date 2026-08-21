<?php

/**
 * Τα νούμερα της καρτέλας συνεργάτη, πάνω σε πραγματική βάση.
 *
 * Τρία πράγματα ελέγχονται εδώ, και κανένα δεν το πιάνει ο τύπος:
 *
 * 1. **Ότι το scope μπαίνει όντως στο WHERE.** Το ίδιο που κάνει το
 *    ContractScopeTest για τις συμβάσεις — ένας τύπος εγγυάται ότι η παράμετρος
 *    πέρασε, όχι ότι το SQL τη χρησιμοποίησε. Μια καρτέλα που δείχνει τα νούμερα
 *    ξένου συνεργάτη είναι IDOR με γραφήματα.
 *
 * 2. **Ότι ο παρονομαστής της επιτυχίας δεν μετράει τις εν πτήσει.** Είναι
 *    απόφαση προϊόντος, όχι λεπτομέρεια: αν μια σύμβαση που περιμένει ακόμη
 *    μετρηθεί ως αποτυχία, τιμωρείται ο άνθρωπος που μόλις κατέθεσε δέκα.
 *
 * 3. **Ότι ο μέσος χρόνος χωρίς δείγμα είναι null και όχι 0.** Ένα 0.0 στην
 *    οθόνη διαβάζεται «υπογράφει αυθημερόν» — δηλαδή το χειρότερο δυνατό ψέμα
 *    για έναν δείκτη που υπάρχει για να συγκρίνει ανθρώπους.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\PartnerCardRepository;
use EnergyCRM\Persistence\Tables;

final class PartnerCardTest extends IntegrationTestCase
{
    private PartnerCardRepository $card;

    private ContractRepository $contracts;

    private int $alice;

    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->card      = new PartnerCardRepository();
        $this->contracts = new ContractRepository();
        $this->alice     = $this->makePartner();
        $this->bob       = $this->makePartner();
    }

    /** Μια σύμβαση του δοσμένου κατόχου, σε δοσμένη κατάσταση. */
    private function contractFor(int $owner, string $status): int
    {
        $id = $this->contracts->create(
            ['status' => $status, 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($owner)
        );

        self::assertGreaterThan(0, $id);

        return $id;
    }

    /**
     * Γράφει απευθείας στήλες που το repository δεν εκθέτει (χρόνοι).
     *
     * @param array<string, string> $columns
     */
    private function stamp(int $contractId, array $columns): void
    {
        global $wpdb;

        $wpdb->update(Tables::name(Tables::CONTRACTS), $columns, ['id' => $contractId]);
    }

    // ── 1. Εμβέλεια ──────────────────────────────────────────────────

    public function testAPartnerCannotSeeAnotherPartnersNumbers(): void
    {
        // Σταθερές ημερομηνίες, όχι το ρολόι: ένα gmdate('Y-m-01') τα μεσάνυχτα
        // της πρωτομηνιάς σε site με αρνητική μετατόπιση δίνει άλλο μήνα από το
        // created_at, και ο έλεγχος περνά ή κόβει ανάλογα με την ώρα που έτρεξε.
        $first  = $this->contractFor($this->bob, 'active');
        $second = $this->contractFor($this->bob, 'active');
        $this->stamp($first, ['created_at' => '2026-08-14 09:00:00']);
        $this->stamp($second, ['created_at' => '2026-08-15 09:00:00']);

        // Η Alice ζητά ρητά τα νούμερα του Bob. Η ρήτρα του scope πρέπει να τα
        // μηδενίσει ακόμη κι όταν το memberId λέει «Bob» — γι' αυτό μπαίνουν
        // ΚΑΙ τα δύο στο WHERE.
        $counts = $this->card->monthlyCounts(
            UserScope::forSelf($this->alice),
            $this->bob,
            '2026-08-01 00:00:00',
            '2026-07-01 00:00:00'
        );

        self::assertSame(0, $counts['month']);
    }

    public function testAManagerSeesAMemberOfTheirOwnTeam(): void
    {
        $owned = $this->contractFor($this->bob, 'active');
        $this->stamp($owned, ['created_at' => '2026-08-14 09:00:00']);

        $counts = $this->card->monthlyCounts(
            UserScope::forTeam($this->alice, [$this->bob]),
            $this->bob,
            '2026-08-01 00:00:00',
            '2026-07-01 00:00:00'
        );

        self::assertSame(1, $counts['month']);
    }

    // ── 2. Επιτυχία ──────────────────────────────────────────────────

    public function testInFlightContractsAreNotCountedAsFailures(): void
    {
        $payable  = ['routed', 'active', 'resolved'];
        $terminal = ['cancelled', 'terminated'];

        $this->contractFor($this->alice, 'active');     // πληρωτέα
        $this->contractFor($this->alice, 'cancelled');  // αποτυχία
        $this->contractFor($this->alice, 'new');        // ΑΚΟΜΗ ΣΤΟΝ ΑΕΡΑ
        $this->contractFor($this->alice, 'processing'); // ΑΚΟΜΗ ΣΤΟΝ ΑΕΡΑ

        $success = $this->card->successCounts(
            UserScope::forSelf($this->alice),
            $this->alice,
            $payable,
            $terminal
        );

        // Δύο έκλεισαν, όχι τέσσερις. Μία από τις δύο πέτυχε.
        self::assertSame(2, $success['settled']);
        self::assertSame(1, $success['payable']);
    }

    public function testNothingSettledYetGivesAnEmptyDenominator(): void
    {
        $this->contractFor($this->alice, 'new');

        $success = $this->card->successCounts(
            UserScope::forSelf($this->alice),
            $this->alice,
            ['routed', 'active', 'resolved'],
            ['cancelled', 'terminated']
        );

        // Μηδέν παρονομαστής — η οθόνη πρέπει να μπορεί να δείξει παύλα αντί
        // για διαίρεση με το μηδέν.
        self::assertSame(0, $success['settled']);
        self::assertSame(0, $success['payable']);
    }

    // ── 3. Χρόνος ως υπογραφή ────────────────────────────────────────

    public function testNoSignaturesGivesNullAndNotZero(): void
    {
        $this->contractFor($this->alice, 'new');

        $sign = $this->card->daysToSign(UserScope::forSelf($this->alice), $this->alice);

        self::assertNull($sign['avg']);
        self::assertSame(0, $sign['sample']);
    }

    public function testDaysToSignAveragesOnlySignedContracts(): void
    {
        $signedFast = $this->contractFor($this->alice, 'signed');
        $signedSlow = $this->contractFor($this->alice, 'signed');
        $this->contractFor($this->alice, 'new'); // χωρίς signed_at, εκτός μέσου όρου

        $this->stamp($signedFast, [
            'created_at' => '2026-08-01 10:00:00',
            'signed_at'  => '2026-08-03 10:00:00',
        ]);
        $this->stamp($signedSlow, [
            'created_at' => '2026-08-01 10:00:00',
            'signed_at'  => '2026-08-09 10:00:00',
        ]);

        $sign = $this->card->daysToSign(UserScope::forSelf($this->alice), $this->alice);

        // (2 + 8) / 2 = 5, από δείγμα δύο — και η ανυπόγραφη δεν το αραίωσε.
        self::assertSame(2, $sign['sample']);
        self::assertSame(5.0, $sign['avg']);
    }

    // ── 4. Μήνας έναντι προηγούμενου ─────────────────────────────────

    public function testTheTwoMonthWindowsDoNotOverlap(): void
    {
        $thisMonth = $this->contractFor($this->alice, 'new');
        $lastMonth = $this->contractFor($this->alice, 'new');
        $older     = $this->contractFor($this->alice, 'new');

        $this->stamp($thisMonth, ['created_at' => '2026-08-14 09:00:00']);
        $this->stamp($lastMonth, ['created_at' => '2026-07-14 09:00:00']);
        $this->stamp($older, ['created_at' => '2026-05-14 09:00:00']);

        $counts = $this->card->monthlyCounts(
            UserScope::forSelf($this->alice),
            $this->alice,
            '2026-08-01 00:00:00',
            '2026-07-01 00:00:00'
        );

        // Ο Μάιος δεν μετράει σε κανένα από τα δύο παράθυρα.
        self::assertSame(1, $counts['month']);
        self::assertSame(1, $counts['prev']);
    }

    // ── 5. Πρόσφατες συμβάσεις ───────────────────────────────────────

    public function testRecentContractsStayWithinScope(): void
    {
        $this->contractFor($this->bob, 'active');

        $rows = $this->card->recentContracts(UserScope::forSelf($this->alice), $this->bob);

        self::assertSame([], $rows);
    }
}
