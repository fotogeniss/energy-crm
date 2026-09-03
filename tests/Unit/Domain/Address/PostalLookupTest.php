<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Address;

use EnergyCRM\Domain\Address\PostalLookup;
use PHPUnit\Framework\TestCase;

final class PostalLookupTest extends TestCase
{
    protected function tearDown(): void
    {
        PostalLookup::forget();

        parent::tearDown();
    }

    private static function dataDir(): string
    {
        return dirname(__DIR__, 4) . '/assets/data';
    }

    public function testAKnownPrefixResolvesToItsNomos(): void
    {
        self::assertSame(
            ['nomos' => 'Αττικής', 'diamerisma' => 'Στερεά Ελλάδα'],
            PostalLookup::nomos('10431', self::dataDir())
        );
    }

    /**
     * Τα 70 προθέματα (10-38, 40-74, 80-85) πρέπει να καλύπτονται όλα -- τα
     * κενά 39 και 75-79 δεν αντιστοιχούν σε ταχυδρομική περιοχή εκ σχεδιασμού.
     */
    public function testEveryAdministrativePrefixIsCovered(): void
    {
        $gaps = ['39', '75', '76', '77', '78', '79'];

        foreach (range(10, 85) as $prefix) {
            $prefix = (string) $prefix;

            if (in_array($prefix, $gaps, true)) {
                continue;
            }

            self::assertNotNull(
                PostalLookup::nomos($prefix . '000', self::dataDir()),
                'prefix ' . $prefix . ' should resolve to a nomos'
            );
        }
    }

    public function testAPostalCodeShorterThanFiveDigitsResolvesToNothing(): void
    {
        self::assertNull(PostalLookup::nomos('104', self::dataDir()));
        self::assertNull(PostalLookup::city('104', self::dataDir()));
    }

    public function testNonDigitCharactersAreStripped(): void
    {
        self::assertSame(
            PostalLookup::nomos('10431', self::dataDir()),
            PostalLookup::nomos('104 31', self::dataDir())
        );
    }

    public function testAKnownPostalCodeResolvesToACity(): void
    {
        self::assertSame('ΒΟΥΛΗ ΤΩΝ ΕΛΛΗΝΩΝ', PostalLookup::city('10021', self::dataDir()));
    }

    /**
     * Το grpostcodes δεν καλύπτει όλη την Ελλάδα -- σωστό αποτέλεσμα, όχι
     * ελλιπές αρχείο. `null`, ποτέ μάντεμα από το πρόθεμα.
     */
    public function testAPostalCodeMissingFromTheCityTableReturnsNullNotAGuess(): void
    {
        self::assertNull(PostalLookup::city('00000', self::dataDir()));
    }

    public function testAMissingDataDirDegradesToNoSuggestion(): void
    {
        $noSuchDir = sys_get_temp_dir() . '/ecrm-no-such-dir-216';

        self::assertNull(PostalLookup::nomos('10431', $noSuchDir));
        self::assertNull(PostalLookup::city('10431', $noSuchDir));
    }
}
