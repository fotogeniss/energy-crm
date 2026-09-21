<?php

/**
 * Τι γράφεται όταν ένας manager αποθηκεύει τη λίστα παρόχων ενός μέλους (278)
 * -- καθαρή λογική, χωρίς βάση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Providers;

use EnergyCRM\Providers\Domain\GrantChange;
use PHPUnit\Framework\TestCase;

final class GrantChangeTest extends TestCase
{
    /**
     * Ο,τι δεν ήταν στα χέρια του manager μένει όπως ήταν: αλλάζει μόνο το
     * κομμάτι της λίστας που μπορούσε να δει.
     */
    public function testOnlyTheEditablePartChanges(): void
    {
        $change = GrantChange::replaceWithin([1, 2, 3, 8], [1, 2, 3, 4], [2, 4]);

        self::assertSame([2, 4, 8], $change->grants, 'Χάθηκε πάροχος που ο manager ούτε είδε.');
        self::assertSame([1, 3], $change->removed);
        self::assertSame([4], $change->added);
        self::assertTrue($change->changed());
    }

    /** Ζητημένο έξω από τα επιτρεπτά δεν μπαίνει ποτέ -- ούτε αν ο έλεγχος πριν ξεχαστεί. */
    public function testNothingOutsideTheEditableListGetsIn(): void
    {
        $change = GrantChange::replaceWithin([1], [1, 2], [1, 2, 99]);

        self::assertSame([1, 2], $change->grants);
        self::assertNotContains(99, $change->added);
    }

    public function testSavingTheSameListChangesNothing(): void
    {
        $change = GrantChange::replaceWithin([2, 1], [1, 2, 3], [1, 2]);

        self::assertSame([1, 2], $change->grants);
        self::assertFalse($change->changed());
    }

    public function testGivingToEveryone(): void
    {
        $change = GrantChange::toggle([1, 2], 3, true);

        self::assertSame([1, 2, 3], $change->grants);
        self::assertSame([3], $change->added);
        self::assertSame([], $change->removed, 'Μια προσθήκη δεν κατεβάζει αφαίρεση σε κανέναν.');
    }

    /**
     * Η μαζική αφαίρεση ζητά να σβηστεί από κάτω ΠΑΝΤΑ -- ακόμα κι όταν το ίδιο
     * το μέλος δεν τον είχε, γιατί μετά από μετακίνηση στο δίκτυο μπορεί να τον
     * έχει κάποιος από κάτω του.
     */
    public function testTakingFromEveryoneAlwaysReachesBelow(): void
    {
        self::assertSame([2], GrantChange::toggle([1, 2], 2, false)->removed);
        self::assertSame([7], GrantChange::toggle([1, 2], 7, false)->removed);
        self::assertSame([1], GrantChange::toggle([1, 2], 2, false)->grants);
    }
}
