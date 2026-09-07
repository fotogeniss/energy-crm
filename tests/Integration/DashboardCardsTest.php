<?php

/**
 * Οι τέσσερις μετρητές του πίνακα, πάνω σε πραγματική βάση.
 *
 * Ελέγχονται τα δύο που πρόσθεσε η απόφαση της 21/08 (εκδοχή Β,
 * docs/UI-KPI-DELTA.html) και ένα που ήταν ήδη εκεί και μετρούσε λάθος:
 *
 * 1. **Ότι το «χθες» είναι ΚΛΕΙΣΤΟ παράθυρο.** Με `created_at >= χθες` και
 *    τίποτα άλλο, οι σημερινές θα μετριόνταν και στα δύο, και η μεταβολή θα
 *    ήταν πάντα θετική. Η κάρτα θα έλεγε «ανέβηκες» κάθε μέρα.
 *
 * 2. **Ότι χωρίς συμβάσεις σε μια κατάσταση η ηλικία είναι `null`, όχι 0.**
 *    Ένα 0 στην οθόνη διαβάζεται «η παλαιότερη μπήκε σήμερα» — δηλαδή ψέμα
 *    ακριβώς εκεί που δεν υπάρχει τίποτα.
 *
 * 3. **Ότι τα όρια των παραθύρων τα ορίζει ο καλών, όχι το ρολόι του
 *    repository.** Έτσι ο έλεγχος είναι ντετερμινιστικός, και ο controller
 *    μένει το μόνο σημείο που ξέρει τι ώρα είναι.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\DashboardRepository;
use EnergyCRM\Persistence\Tables;

final class DashboardCardsTest extends IntegrationTestCase
{
    private DashboardRepository $dashboard;

    private ContractRepository $contracts;

    private int $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboard = new DashboardRepository();
        $this->contracts = new ContractRepository();
        $this->partner   = $this->makePartner();
    }

    private function contractFor(string $status): int
    {
        $id = $this->contracts->create(
            ['status' => $status, 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $id);

        return $id;
    }

    /** @param array<string, string> $columns */
    private function stamp(int $contractId, array $columns): void
    {
        global $wpdb;

        $wpdb->update(Tables::name(Tables::CONTRACTS), $columns, ['id' => $contractId]);
    }

    /**
     * Γερνάει μια σύμβαση κατά N μέρες, ΣΧΕΤΙΚΑ με το τώρα της βάσης.
     *
     * Σχετικά και όχι με σταθερή ημερομηνία, επειδή το `oldestPerStatus()`
     * μετράει με `DATEDIFF(NOW(), …)`: μια καρφωμένη ημερομηνία θα έδινε
     * αριθμό που μεγαλώνει κάθε μέρα που περνά, και ο έλεγχος θα σάπιζε.
     *
     * Το `updated_at` έχει `ON UPDATE CURRENT_TIMESTAMP`, αλλά ρητή ανάθεση
     * στο ίδιο UPDATE υπερισχύει — γι' αυτό γράφεται με raw query και όχι με
     * `$wpdb->update()`, που θα περνούσε την τιμή ως συμβολοσειρά.
     */
    private function ageByDays(int $contractId, int $days): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET updated_at = NOW() - INTERVAL %d DAY WHERE id = %d',
                Tables::name(Tables::CONTRACTS),
                $days,
                $contractId
            )
        );
    }

    /** @return array<string, mixed> */
    private function cardsFor(string $today, string $month, string $yesterday): array
    {
        return $this->dashboard->cards($this->partner, $today, $month, $yesterday);
    }

    // ── 1. Το «χθες» δεν επικαλύπτεται με το «σήμερα» ────────────────

    public function testYesterdayExcludesToday(): void
    {
        $todayOne = $this->contractFor('presale');
        $todayTwo = $this->contractFor('presale');
        $earlier  = $this->contractFor('presale');

        $this->stamp($todayOne, ['created_at' => '2026-08-21 09:00:00']);
        $this->stamp($todayTwo, ['created_at' => '2026-08-21 18:00:00']);
        $this->stamp($earlier, ['created_at' => '2026-08-20 11:00:00']);

        $cards = $this->cardsFor('2026-08-21 00:00:00', '2026-08-01 00:00:00', '2026-08-20 00:00:00');

        // Δύο σήμερα, ΜΙΑ χθες. Αν το παράθυρο ήταν ανοιχτό, το «χθες» θα
        // έλεγε τρία και η μεταβολή θα ήταν μονίμως αρνητική.
        self::assertSame(2, $cards['today']);
        self::assertSame(1, $cards['yesterday']);
    }

    public function testNothingYesterdayIsZeroAndNotMissing(): void
    {
        $today = $this->contractFor('presale');
        $this->stamp($today, ['created_at' => '2026-08-21 09:00:00']);

        $cards = $this->cardsFor('2026-08-21 00:00:00', '2026-08-01 00:00:00', '2026-08-20 00:00:00');

        self::assertSame(1, $cards['today']);
        self::assertSame(0, $cards['yesterday']);
    }

    // ── 2. Η ηλικία ανά κατάσταση ────────────────────────────────────

    public function testAgeIsNullWhenNothingIsInThatStatus(): void
    {
        $this->contractFor('draft'); // ούτε presale ούτε finalisation

        $cards = $this->cardsFor('2026-08-21 00:00:00', '2026-08-01 00:00:00', '2026-08-20 00:00:00');

        self::assertNull($cards['oldest']['presale']);
        self::assertNull($cards['oldest']['finalisation']);
    }

    public function testAgeCountsTheOldestOfEachStatusSeparately(): void
    {
        $staleOne      = $this->contractFor('presale');
        $freshOne      = $this->contractFor('presale');
        $finalisation  = $this->contractFor('finalisation');

        $this->ageByDays($staleOne, 12);
        $this->ageByDays($freshOne, 2);
        $this->ageByDays($finalisation, 5);

        $cards = $this->cardsFor('2026-08-21 00:00:00', '2026-08-01 00:00:00', '2026-08-20 00:00:00');

        // Η παλαιότερη, όχι η νεότερη ούτε ο μέσος όρος — και οι δύο
        // καταστάσεις μετριούνται χώρια.
        self::assertSame(12, $cards['oldest']['presale']);
        self::assertSame(5, $cards['oldest']['finalisation']);
    }

    public function testSomethingTouchedTodayIsZeroDaysAndNotNull(): void
    {
        $justMoved = $this->contractFor('presale');
        $this->ageByDays($justMoved, 0);

        $cards = $this->cardsFor('2026-08-21 00:00:00', '2026-08-01 00:00:00', '2026-08-20 00:00:00');

        // Μηδέν μέρες ΕΙΝΑΙ μέτρηση («μπήκε σήμερα») και πρέπει να ξεχωρίζει
        // από το null («δεν υπάρχει καμία»). Η οθόνη τα λέει διαφορετικά.
        self::assertSame(0, $cards['oldest']['presale']);
        self::assertNotNull($cards['oldest']['presale']);
    }

    // ── 3. Τα όρια τα ορίζει ο καλών ─────────────────────────────────

    public function testTheMonthWindowIsWhateverTheCallerPassed(): void
    {
        $inside  = $this->contractFor('presale');
        $outside = $this->contractFor('presale');

        $this->stamp($inside, ['created_at' => '2026-08-03 10:00:00']);
        $this->stamp($outside, ['created_at' => '2026-07-30 10:00:00']);

        $cards = $this->cardsFor('2026-08-21 00:00:00', '2026-08-01 00:00:00', '2026-08-20 00:00:00');

        self::assertSame(1, $cards['month']);
    }
}
