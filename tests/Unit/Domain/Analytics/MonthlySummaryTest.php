<?php

/**
 * (345) Το κείμενο του email της 1ης του μήνα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Analytics;

use EnergyCRM\Domain\Analytics\MonthlySummary;
use PHPUnit\Framework\TestCase;

final class MonthlySummaryTest extends TestCase
{
    public function testTheFullSummary(): void
    {
        $report = $this->report();

        self::assertSame('Οκτώβριος 2026 σε μια ματιά', MonthlySummary::subject($report));
        self::assertSame(
            [
                'Αιτήσεις: 100 (+9% από Σεπτέμβριο, στόχος 120 · 83%)',
                'Έγιναν ενεργές: 61 · Απορρίψεις/ακυρώσεις: 9 (κυρίως: ελλιπή δικαιολογητικά)',
                'Υποψήφιοι: 42 · έγιναν αίτηση: 17 · από QR code: 6',
                'Πρώτη θέση: Μαρία Κ. 38',
                'Κάτω από τον στόχο: Νίκος Τ. 33/40',
            ],
            MonthlySummary::lines($report)
        );
    }

    /** Χωρίς σύγκριση, χωρίς στόχους, χωρίς υποψηφίους: μόνο ό,τι υπάρχει. */
    public function testOnlyWhatExists(): void
    {
        $report                       = $this->report();
        $report['tiles']['change']    = null;
        $report['tiles']['target']    = null;
        $report['tiles']['leads']     = 0;
        $report['tiles']['top_reason'] = '';
        $report['members']            = [];

        self::assertSame(
            ['Αιτήσεις: 100', 'Έγιναν ενεργές: 61 · Απορρίψεις/ακυρώσεις: 9'],
            MonthlySummary::lines($report)
        );
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return [
            'label'    => 'Οκτώβριος 2026',
            'previous' => 'Σεπτέμβριο',
            'tiles'    => [
                'applications'    => 100,
                'change'          => 9,
                'target'          => 120,
                'of_target'       => 83,
                'activated'       => 61,
                'lost'            => 9,
                'top_reason'      => 'Ελλιπή δικαιολογητικά',
                'leads'           => 42,
                'leads_converted' => 17,
                'leads_qr'        => 6,
            ],
            'members'  => [
                ['name' => 'Μαρία Κ.', 'applications' => 38, 'target' => 35],
                ['name' => 'Νίκος Τ.', 'applications' => 33, 'target' => 40],
                ['name' => 'Γιάννης Π.', 'applications' => 29, 'target' => null],
            ],
        ];
    }
}
