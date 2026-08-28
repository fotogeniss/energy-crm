<?php

/**
 * Το σχόλιο του παρόχου, κομμένο σε μήκος που χωράει σε ιστορικό.
 *
 * Ο κανόνας γεννήθηκε στην (159) μέσα στην `ECRM_Import::apply()` — legacy
 * κλάση ανάγνωσης υπολογιστικού φύλλου — και μετακόμισε στην (161). Αυτό το
 * αρχείο υπάρχει επειδή ο κανόνας έχει πλέον **δύο** καλούντες: τον εισαγωγέα
 * Excel σήμερα και τη διαδρομή API παρόχου αύριο (§1.13).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Providers;

use EnergyCRM\Providers\Domain\ProviderNote;
use PHPUnit\Framework\TestCase;

final class ProviderNoteTest extends TestCase
{
    /** Κενό κελί και κελί με κενά είναι η ίδια απάντηση: ο πάροχος δεν είπε τίποτα. */
    public function testNothingSaidBecomesNull(): void
    {
        self::assertNull(ProviderNote::fromRaw(''));
        self::assertNull(ProviderNote::fromRaw('   '));
    }

    public function testAShortNoteSurvivesUntouchedApartFromTrimming(): void
    {
        self::assertSame('Λάθος αριθμός μετρητή', ProviderNote::fromRaw('  Λάθος αριθμός μετρητή  '));
    }

    /**
     * 300 χαρακτήρες, απόφαση ιδιοκτήτη 27/08/2026 — και μετριούνται σε
     * ΧΑΡΑΚΤΗΡΕΣ, όχι bytes. Με `substr` αντί για `mb_substr` ένα ελληνικό
     * σχόλιο θα κοβόταν στη μέση χαρακτήρα και θα αποθηκευόταν σπασμένο.
     */
    public function testALongNoteIsCutAtThreeHundredCharacters(): void
    {
        $note = ProviderNote::fromRaw(str_repeat('α', 350));

        self::assertNotNull($note);
        self::assertSame(300, mb_strlen($note));
        self::assertSame(str_repeat('α', 300), $note);
    }

    public function testExactlyThreeHundredIsNotTouched(): void
    {
        self::assertSame(300, mb_strlen((string) ProviderNote::fromRaw(str_repeat('β', 300))));
    }
}
