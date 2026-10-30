<?php

/**
 * (338) Πότε μια αίτηση «κόλλησε» -- κάρτα και λίστα με τον ίδιο κανόνα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\StuckRule;
use PHPUnit\Framework\TestCase;

final class StuckRuleTest extends TestCase
{
    public function testTwiceTheTypicalTimeIsStuck(): void
    {
        $typical = ['days' => 4, 'sample' => 12];

        self::assertNull(StuckRule::judge(7, $typical, 10));

        $verdict = StuckRule::judge(8, $typical, 10);
        self::assertNotNull($verdict);
        self::assertSame(['days' => 8, 'typical' => 4, 'sample' => 12, 'compared' => true, 'limit' => 8], $verdict);
    }

    /** Συνήθως αυθημερόν: όχι «κόλλησε» από τη μέρα 0. */
    public function testASameDayStatusNeedsAtLeastOneDay(): void
    {
        $typical = ['days' => 0, 'sample' => 30];

        self::assertNull(StuckRule::judge(0, $typical, 10));
        self::assertNotNull(StuckRule::judge(1, $typical, 10));
    }

    /** Με σύγκριση, το σταθερό όριο δεν μετράει -- ούτε προς τα πάνω ούτε προς τα κάτω. */
    public function testTheFixedLimitIsOnlyAFallback(): void
    {
        self::assertNull(StuckRule::judge(12, ['days' => 7, 'sample' => 10], 10), '12 < 14');
        self::assertNotNull(StuckRule::judge(4, ['days' => 2, 'sample' => 10], 10), '4 ≥ 4');
    }

    public function testWithoutAComparisonTheListFallsBackAndTheCardStaysSilent(): void
    {
        self::assertNull(StuckRule::judge(9, null, 10));
        self::assertSame(
            ['days' => 10, 'typical' => null, 'sample' => 0, 'compared' => false, 'limit' => 10],
            StuckRule::judge(10, null, 10)
        );

        // Η κάρτα: χωρίς σύγκριση, σιωπή, όσο κι αν κάθεται.
        self::assertNull(StuckRule::judge(400, null, null));
    }

    public function testFilterAsksOncePerStatusAndProviderAndFallsBackToAllProviders(): void
    {
        $asked = [];
        $typical = static function (string $status, ?int $provider) use (&$asked): ?array {
            $asked[] = $status . '|' . (string) $provider;

            // Protergia (1) έχει δικό της δείγμα· ο πάροχος 2 όχι, όλοι μαζί ναι.
            return match ([$status, $provider]) {
                ['processing', 1] => ['days' => 3, 'sample' => 20],
                ['processing', null] => ['days' => 10, 'sample' => 40],
                default => null,
            };
        };

        $rows = [
            ['id' => 1, 'status' => 'processing', 'provider_id' => 1, 'dwell' => '7'],
            ['id' => 2, 'status' => 'processing', 'provider_id' => 1, 'dwell' => '5'],
            ['id' => 3, 'status' => 'processing', 'provider_id' => 2, 'dwell' => '7'],
            ['id' => 4, 'status' => 'processing', 'provider_id' => 2, 'dwell' => '25'],
            ['id' => 5, 'status' => 'presale', 'provider_id' => 0, 'dwell' => '11'],
            ['id' => 6, 'status' => 'presale', 'provider_id' => 0, 'dwell' => null],
        ];

        $out = StuckRule::filter($rows, $typical, 10);

        self::assertSame([4, 5, 1], array_column($out, 'id'), 'πιο παλιά πρώτα');
        self::assertSame(10, $out[0]['stuck']['typical'], 'πάροχος 2 → όλοι μαζί');
        self::assertFalse($out[1]['stuck']['compared'], 'χωρίς κανένα δείγμα → σταθερό όριο');
        self::assertSame(['processing|1', 'processing|2', 'processing|', 'presale|'], $asked);
    }
}
