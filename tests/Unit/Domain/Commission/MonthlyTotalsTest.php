<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Commission;

use EnergyCRM\Domain\Commission\MonthlyTotals;
use PHPUnit\Framework\TestCase;

final class MonthlyTotalsTest extends TestCase
{
    public function testItSumsAndCountsPerMonth(): void
    {
        $result = MonthlyTotals::from([
            ['month' => '2026-03', 'amount' => 10.0],
            ['month' => '2026-03', 'amount' => 15.5],
            ['month' => '2026-02', 'amount' => 20.0],
        ]);

        self::assertSame('Μάρτιος 2026', $result['months'][0]['label']);
        self::assertSame(2, $result['months'][0]['count']);
        self::assertSame(25.5, $result['months'][0]['amount']);
    }

    public function testTheNewestMonthComesFirst(): void
    {
        $result = MonthlyTotals::from([
            ['month' => '2025-12', 'amount' => 1.0],
            ['month' => '2026-01', 'amount' => 1.0],
            ['month' => '2026-11', 'amount' => 1.0],
        ]);

        self::assertSame(
            ['Νοέμβριος 2026', 'Ιανουάριος 2026', 'Δεκέμβριος 2025'],
            array_column($result['months'], 'label')
        );
    }

    public function testTheBestMonthIsTheLargestSumNotTheLargestSingleAmount(): void
    {
        $result = MonthlyTotals::from([
            ['month' => '2026-01', 'amount' => 100.0],
            ['month' => '2026-02', 'amount' => 60.0],
            ['month' => '2026-02', 'amount' => 60.0],
        ]);

        self::assertSame('Φεβρουάριος', $result['best_label']);
        self::assertSame(120.0, $result['best']);
    }

    public function testAmountsAreRoundedToCents(): void
    {
        $result = MonthlyTotals::from([
            ['month' => '2026-01', 'amount' => 0.1],
            ['month' => '2026-01', 'amount' => 0.2],
        ]);

        self::assertSame(0.3, $result['months'][0]['amount']);
    }

    public function testNothingEarnedYieldsNoMonthsAndNoBest(): void
    {
        $result = MonthlyTotals::from([]);

        self::assertSame([], $result['months']);
        self::assertSame(0.0, $result['best']);
        self::assertSame('', $result['best_label']);
    }

    /**
     * Ο μήνας κουβαλά ΠΟΣΕΣ πληρώθηκαν, όχι αν πληρώθηκε.
     *
     * Η οθόνη τύπωνε σταθερό badge «Καταχωρημένο» σε κάθε γραμμή, σε πίνακα που
     * λέγεται «Οι εκκαθαρίσεις μου». Με σημαία αντί για αριθμό, ο μισοπληρωμένος
     * μήνας θα έπρεπε να διαλέξει ανάμεσα σε δύο ψέματα.
     */
    public function testAMonthCarriesHowManyOfItsContractsWerePaid(): void
    {
        $result = MonthlyTotals::from([
            ['month' => '2026-07', 'amount' => 10.0, 'paid' => true],
            ['month' => '2026-07', 'amount' => 20.0, 'paid' => false],
            ['month' => '2026-07', 'amount' => 30.0, 'paid' => true],
        ]);

        self::assertSame(3, $result['months'][0]['count']);
        self::assertSame(2, $result['months'][0]['paid']);
    }

    public function testAMonthWithNothingPaidSaysZeroAndNotNothing(): void
    {
        $result = MonthlyTotals::from([
            ['month' => '2026-07', 'amount' => 10.0, 'paid' => false],
        ]);

        // Η οθόνη διακρίνει «0 από 1» από «καμία πληροφορία». Ένα κλειδί που
        // λείπει θα την ανάγκαζε να μαντέψει.
        self::assertSame(0, $result['months'][0]['paid']);
    }

    /**
     * Χωρίς το κλειδί, τίποτα δεν σπάει.
     *
     * Ο μοναδικός καλών το στέλνει πάντα — αλλά μια αγνώστου προελεύσεως γραμμή
     * δεν πρέπει να μετρήσει ως πληρωμένη. Στα λεφτά, η προεπιλογή είναι
     * «απλήρωτο».
     */
    public function testAnEntryWithoutThePaidKeyCountsAsUnpaid(): void
    {
        $result = MonthlyTotals::from([
            ['month' => '2026-07', 'amount' => 10.0],
        ]);

        self::assertSame(0, $result['months'][0]['paid']);
    }
}
