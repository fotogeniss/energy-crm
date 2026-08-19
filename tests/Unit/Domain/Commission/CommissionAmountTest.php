<?php

/**
 * Ποιο από τα δύο ποσά μετράει, και πότε δεν υπολογίζεται καθόλου το δεύτερο.
 *
 * Ο κανόνας είναι δύο γραμμές και γι' αυτό ακριβώς είχε γραφτεί τέσσερις φορές.
 * Εδώ δηλώνεται μία, χωρίς βάση και χωρίς WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Commission;

use EnergyCRM\Domain\Commission\CommissionAmount;
use PHPUnit\Framework\TestCase;

final class CommissionAmountTest extends TestCase
{
    /** Το ποσό που πληρώθηκε, ακόμη κι αν οι κανόνες λένε πλέον άλλο. */
    public function testTheSnapshotWinsWhenItExists(): void
    {
        $amount = CommissionAmount::of(
            ['payout_amount' => '12.50'],
            static fn (): float => 99.0
        );

        self::assertSame(12.5, $amount);
    }

    /**
     * Και δεν υπολογίζεται καν το ζωντανό.
     *
     * Όχι για την ταχύτητα: υπολογισμός που γίνεται και πετιέται είναι ο τρόπος
     * να μπει αργότερα μια παρενέργεια σε δρόμο που υποτίθεται ότι δεν
     * περπατιέται.
     */
    public function testTheLiveCalculationIsNotEvenRunWhenASnapshotExists(): void
    {
        $ran = false;

        CommissionAmount::of(
            ['payout_amount' => '12.50'],
            static function () use (&$ran): float {
                $ran = true;

                return 99.0;
            }
        );

        self::assertFalse($ran, 'Ο ζωντανός υπολογισμός έτρεξε ενώ υπήρχε στιγμιότυπο.');
    }

    /**
     * Στιγμιότυπο μηδέν είναι στιγμιότυπο.
     *
     * Η προφανής υλοποίηση — `$row['payout_amount'] ?: $live(...)` — θα το
     * περνούσε για «χωρίς στιγμιότυπο» και θα ξαναϋπολόγιζε σύμβαση που
     * σφραγίστηκε στα 0 €. Είναι απολύτως δυνατή περίπτωση: πριν μπουν κανόνες
     * προμήθειας, κάθε παρτίδα σφραγίζεται έτσι.
     */
    public function testASnapshotOfZeroIsStillASnapshot(): void
    {
        $amount = CommissionAmount::of(
            ['payout_amount' => '0.00'],
            static fn (): float => 99.0
        );

        self::assertSame(0.0, $amount);
    }

    /** Χωρίς στιγμιότυπο: ζωντανά. */
    public function testTheLiveCalculationAnswersWhenTheSnapshotIsNull(): void
    {
        $amount = CommissionAmount::of(
            ['payout_amount' => null],
            static fn (): float => 42.0
        );

        self::assertSame(42.0, $amount);
    }

    /** Και το ίδιο όταν η στήλη δεν ήρθε καν στο ερώτημα. */
    public function testAMissingColumnIsTreatedAsNoSnapshot(): void
    {
        self::assertSame(42.0, CommissionAmount::of([], static fn (): float => 42.0));
    }

    /** Η γραμμή περνά στον υπολογισμό, γιατί εκείνος τη χρειάζεται ολόκληρη. */
    public function testTheRowReachesTheLiveCalculation(): void
    {
        $seen = [];

        CommissionAmount::of(
            ['provider_id' => 7, 'energy_type' => 'power'],
            static function (array $row) use (&$seen): float {
                $seen = $row;

                return 0.0;
            }
        );

        self::assertSame(7, $seen['provider_id']);
    }
}
