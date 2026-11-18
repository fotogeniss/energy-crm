<?php

/**
 * (416) Ο μήνας της προμήθειας δεν ακολουθεί την τελευταία επεξεργασία.
 *
 * @kanonas K5
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Persistence;

use EnergyCRM\Persistence\PayableClause;
use PHPUnit\Framework\TestCase;

final class PayableMonthTest extends TestCase
{
    public function testTheMonthItWasEarnedWins(): void
    {
        self::assertSame('2026-10', PayableClause::monthOf([
            'earned_at'  => '2026-10-05 12:00:00',
            'signed_at'  => '2026-09-30 10:00:00',
            'updated_at' => '2026-11-16 09:00:00',
        ]));
    }

    public function testWithoutAnActiveEventAnEditDoesNotMoveIt(): void
    {
        // Εισαγωγή: ήδη Ενεργή, χωρίς γεγονός. Μια διόρθωση τον Νοέμβριο.
        self::assertSame('2026-09', PayableClause::monthOf([
            'earned_at'  => null,
            'signed_at'  => null,
            'created_at' => '2026-09-12 08:00:00',
            'updated_at' => '2026-11-16 09:00:00',
        ]));

        self::assertSame('2026-08', PayableClause::monthOf([
            'earned_at'  => '',
            'signed_at'  => '2026-08-30 18:00:00',
            'created_at' => '2026-08-01 08:00:00',
            'updated_at' => '2026-11-16 09:00:00',
        ]));
    }

    public function testAnEmptyRowHasNoMonth(): void
    {
        self::assertSame('', PayableClause::monthOf([]));
        self::assertSame('2026-11', PayableClause::monthOf(['updated_at' => '2026-11-16 09:00:00']));
    }
}
