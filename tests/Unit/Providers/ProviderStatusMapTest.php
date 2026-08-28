<?php

/**
 * Ο χάρτης καταστάσεων παρόχου — καθαρή λογική, χωρίς βάση και χωρίς WordPress.
 *
 * Το ότι αυτό το αρχείο τρέχει σε unit suite είναι το ίδιο το επιχείρημα του
 * `HANDOVER.md` §1.12: ο κανόνας ζει σε `Domain`, οπότε δοκιμάζεται χωρίς
 * bootstrap, χωρίς βάση, σε χιλιοστά του δευτερολέπτου — και μεταφέρεται
 * αυτούσιος. Ως τις 28/08 ο ίδιος κανόνας ζούσε σε JavaScript και δεν
 * δοκιμαζόταν καθόλου.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Providers;

use EnergyCRM\Providers\Domain\ProviderStatusMap;
use PHPUnit\Framework\TestCase;

final class ProviderStatusMapTest extends TestCase
{
    public function testAnEmptyMapGuessesFromTheText(): void
    {
        $resolved = ProviderStatusMap::empty()->resolve(['ΕΝΕΡΓΟΠΟΙΗΘΗΚΕ', 'ΑΚΥΡΩΘΗΚΕ']);

        self::assertSame(
            ['ΕΝΕΡΓΟΠΟΙΗΘΗΚΕ' => 'active', 'ΑΚΥΡΩΘΗΚΕ' => 'cancelled'],
            $resolved['map']
        );
        self::assertSame(['ΕΝΕΡΓΟΠΟΙΗΘΗΚΕ', 'ΑΚΥΡΩΘΗΚΕ'], $resolved['guessed']);
        self::assertSame([], $resolved['saved']);
    }

    /**
     * Ό,τι αποφάσισε άνθρωπος νικάει ό,τι μαντεύει η μηχανή.
     *
     * Ίδια σημασιολογία με το `keepExisting` της φόρμας και το `apply=1` της
     * εξαγωγής. Εδώ φαίνεται καθαρά: η τιμή λέει «ΕΝΕΡΓΟ», που η ευρετική θα
     * το έκανε `active`, αλλά κάποιος έχει ήδη πει ότι γι' αυτόν τον πάροχο
     * σημαίνει κάτι άλλο — και αυτό μετράει.
     */
    public function testASavedDecisionBeatsTheGuess(): void
    {
        $map = ProviderStatusMap::fromArray(['ΕΝΕΡΓΟ' => 'processing']);

        $resolved = $map->resolve(['ΕΝΕΡΓΟ']);

        self::assertSame(['ΕΝΕΡΓΟ' => 'processing'], $resolved['map']);
        self::assertSame(['ΕΝΕΡΓΟ'], $resolved['saved']);
        self::assertSame([], $resolved['guessed']);
    }

    /** Τιμή που δεν λέει τίποτα σε κανέναν μένει κενή και ζητά απόφαση. */
    public function testAnUnrecognisedValueIsReportedAsUnknown(): void
    {
        $resolved = ProviderStatusMap::empty()->resolve(['ΚΩΔ. 47/Β']);

        self::assertSame(['ΚΩΔ. 47/Β' => ''], $resolved['map']);
        self::assertSame(['ΚΩΔ. 47/Β'], $resolved['unknown']);
    }

    /**
     * Άγνωστο slug ξεχνιέται αντί να αποθηκευτεί.
     *
     * Ο χάρτης είναι βοήθημα, όχι κρίσιμο δεδομένο: κατάσταση που καταργήθηκε
     * δεν πρέπει να μπλοκάρει ολόκληρη την εισαγωγή — η οθόνη απλώς ξαναρωτά.
     */
    public function testUnknownSlugsAreDroppedNotStored(): void
    {
        $map = ProviderStatusMap::fromArray([
            'ΕΝΕΡΓΟΠΟΙΗΘΗΚΕ' => 'active',
            'ΚΑΤΙ'           => 'δεν_υπάρχει_τέτοιο',
            'ΑΛΛΟ'           => '',
        ]);

        self::assertSame(['ΕΝΕΡΓΟΠΟΙΗΘΗΚΕ' => 'active'], $map->toArray());
    }

    public function testJsonSurvivesARoundTrip(): void
    {
        $map = ProviderStatusMap::fromArray(['ΣΕ ΕΞΕΛΙΞΗ' => 'processing']);

        self::assertSame(
            $map->toArray(),
            ProviderStatusMap::fromJson((string) json_encode($map->toArray()))->toArray()
        );
    }

    /** Σκουπίδι αντί για JSON δεν ρίχνει τίποτα — απλώς δεν υπάρχει χάρτης. */
    public function testBrokenJsonBecomesAnEmptyMap(): void
    {
        self::assertTrue(ProviderStatusMap::fromJson('{όχι json')->isEmpty());
        self::assertTrue(ProviderStatusMap::fromJson(null)->isEmpty());
        self::assertTrue(ProviderStatusMap::fromJson('')->isEmpty());
    }

    /**
     * Οι ευρετικές είναι ΟΙ ΙΔΙΕΣ που ζούσαν στη guessStatus() του
     * ecrm-view-import.js ως τις 28/08. Καρφώνονται εδώ ώστε η μετακόμιση από
     * JavaScript σε PHP να μην έχει αλλάξει σιωπηλά ούτε μία εικασία.
     */
    public function testTheHeuristicsMatchTheOnesMovedFromJavascript(): void
    {
        $cases = [
            'ΕΝΕΡΓΗ ΠΑΡΟΧΗ'    => 'active',
            'ΑΚΥΡΩΘΗΚΕ'        => 'cancelled',
            'ΕΚΚΡΕΜΕΙ ΕΓΓΡΑΦΟ' => 'pending',
            'ΔΡΟΜΟΛΟΓΗΘΗΚΕ'    => 'routed',
            'ΕΠΙΛΥΘΗΚΕ'        => 'resolved',
            'ΠΡΟΣ ΥΠΟΓΡΑΦΗ'    => 'pending_signature',
            'ΣΕ ΕΠΕΞΕΡΓΑΣΙΑ'   => 'processing',
            'ΤΕΡΜΑΤΙΣΤΗΚΕ'     => 'terminated',
            'ΝΕΑ ΑΙΤΗΣΗ'       => 'new',
            'active'           => 'active',
            'CANCELLED'        => 'cancelled',
            ''                 => '',
        ];

        foreach ($cases as $raw => $expected) {
            self::assertSame($expected, ProviderStatusMap::guess((string) $raw), 'εικασία για: ' . $raw);
        }
    }
}
