<?php

/**
 * Το κατώφλι της πρότασης «συνήθως βάζεις...» — καθαρή λογική, χωρίς βάση.
 *
 * Ο λόγος που αυτά τα tests υπάρχουν δεν είναι η αριθμητική: είναι ότι το
 * κατώφλι είναι **ο μοναδικός φύλακας** ανάμεσα σε μια χρήσιμη πρόταση και σε
 * ένα ψέμα που ο πωλητής θα πατήσει επειδή του δόθηκε κουμπί. «Συνήθως βάζεις
 * Χ» πάνω σε μία σύμβαση δεν είναι πρόταση. Αν κάποιος χαλαρώσει το
 * `MIN_TIMES` χωρίς να το σκεφτεί, εδώ θα κοκκινίσει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Providers;

use EnergyCRM\Providers\Domain\UsualChoice;
use PHPUnit\Framework\TestCase;

final class UsualChoiceTest extends TestCase
{
    /** Καθαρή συνήθεια: περνάει και ταξιδεύει ολόκληρη. */
    public function testAClearHabitIsReported(): void
    {
        $usual = UsualChoice::from(7, 'power', 3, 14, 20);

        self::assertTrue($usual->isKnown());
        self::assertSame(
            ['provider_id' => 7, 'energy_type' => 'power', 'program_id' => 3, 'times' => 14, 'of' => 20],
            $usual->toArray()
        );
    }

    /** Ακριβώς στο κατώφλι: περνάει. Το όριο είναι «τουλάχιστον», όχι «πάνω από». */
    public function testExactlyTheThresholdIsEnough(): void
    {
        self::assertTrue(UsualChoice::from(7, 'power', 3, UsualChoice::MIN_TIMES, 20)->isKnown());
    }

    /** Μία κάτω από το κατώφλι: καμία πρόταση, και `null` προς την οθόνη. */
    public function testBelowTheThresholdSaysNothing(): void
    {
        $usual = UsualChoice::from(7, 'power', 3, UsualChoice::MIN_TIMES - 1, 20);

        self::assertFalse($usual->isKnown());
        self::assertNull($usual->toArray());
    }

    /**
     * Πωλητής χωρίς πάροχο δεν έχει συνήθεια, όσες φορές κι αν εμφανιστεί.
     *
     * Δεν είναι θεωρητικό: το `provider_id` της σύμβασης είναι `NULL`-able, και
     * μια ομάδα από είκοσι γραμμές χωρίς πάροχο θα περνούσε άνετα το κατώφλι
     * του πλήθους. Η κάρτα χωρίς πάροχο δεν έχει τι να προτείνει.
     */
    public function testAMissingProviderIsNeverAHabit(): void
    {
        self::assertFalse(UsualChoice::from(0, 'power', 3, 20, 20)->isKnown());
    }

    /** Ούτε κενό είδος: η λίστα προγραμμάτων φιλτράρεται από αυτό. */
    public function testAMissingEnergyTypeIsNeverAHabit(): void
    {
        self::assertFalse(UsualChoice::from(7, '', 3, 20, 20)->isKnown());
    }

    /**
     * Χωρίς πρόγραμμα είναι ακόμα συνήθεια — του παρόχου.
     *
     * Πωλητής που δεν συμπληρώνει ποτέ πρόγραμμα θα έχανε ολόκληρη την πρόταση
     * αν το πρόγραμμα ήταν υποχρεωτικό, ενώ η συνήθειά του ως προς τον πάροχο
     * είναι εξίσου καθαρή. Η οθόνη δείχνει τότε μόνο πάροχο και είδος.
     */
    public function testAHabitWithoutAProgrammeStillCounts(): void
    {
        $usual = UsualChoice::from(7, 'gas', null, 9, 20);

        self::assertTrue($usual->isKnown());
        self::assertNull($usual->toArray()['program_id']);
    }

    /** Το «καμία πρόταση» δεν διαρρέει ποτέ αριθμούς στην οθόνη. */
    public function testNoneCarriesNothing(): void
    {
        self::assertFalse(UsualChoice::none()->isKnown());
        self::assertNull(UsualChoice::none()->toArray());
    }
}
