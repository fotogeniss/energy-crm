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
            ['ΕΝΕΡΓΟΠΟΙΗΘΗΚΕ' => 'active', 'ΑΚΥΡΩΘΗΚΕ' => 'cancelled_by_us'],
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
        $map = ProviderStatusMap::fromArray(['ΕΝΕΡΓΟ' => 'registration']);

        $resolved = $map->resolve(['ΕΝΕΡΓΟ']);

        self::assertSame(['ΕΝΕΡΓΟ' => 'registration'], $resolved['map']);
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
        $map = ProviderStatusMap::fromArray(['ΣΕ ΕΞΕΛΙΞΗ' => 'registration']);

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
     * Οι ευρετικές του `GUESSES`, καρφωμένες μία προς μία.
     *
     * Ξαναγράφτηκαν στο νέο λεξιλόγιο στις 07/09/2026 (δες το σχόλιο πάνω από
     * το `GUESSES`): δύο μοτίβα έφυγαν χωρίς αντικατάσταση (`εκκρεμ|pending`,
     * `επιλ|resolv` -- έγιναν εμπόδια, δεν ζουν πια στη στήλη status), και οι
     * δύο απορρίψεις/ακυρώσεις παρόχου συγκλίνουν και οι δύο στο
     * `cancelled_by_us` (το Excel δεν λέει ποιος αποφάσισε). Δεν είναι πια «οι
     * ίδιες που ζούσαν στο JavaScript» -- είναι η επόμενη γενιά τους, και αυτό
     * το test τις καρφώνει όπως είναι σήμερα, ώστε η επόμενη αλλαγή να είναι
     * ρητή κι όχι σιωπηλή.
     */
    public function testTheHeuristicsMatchTheCurrentVocabulary(): void
    {
        $cases = [
            'ΕΝΕΡΓΗ ΠΑΡΟΧΗ'    => 'active',
            'ΑΚΥΡΩΘΗΚΕ'        => 'cancelled_by_us',
            'ΑΠΟΡΡΙΦΘΗΚΕ'      => 'cancelled_by_us',
            'ΕΚΚΡΕΜΕΙ ΕΓΓΡΑΦΟ' => '',
            'ΔΡΟΜΟΛΟΓΗΘΗΚΕ'    => 'finalisation',
            'ΕΠΙΛΥΘΗΚΕ'        => '',
            'ΠΡΟΣ ΥΠΟΓΡΑΦΗ'    => 'awaiting_signature',
            'ΘΕΛΕΙ SIM'        => 'awaiting_sim',
            'ΣΕ ΕΠΕΞΕΡΓΑΣΙΑ'   => 'registration',
            'ΤΕΡΜΑΤΙΣΤΗΚΕ'     => 'terminated',
            'ΝΕΑ ΑΙΤΗΣΗ'       => 'presale',
            'active'           => 'active',
            'CANCELLED'        => 'cancelled_by_us',
            ''                 => '',
        ];

        foreach ($cases as $raw => $expected) {
            self::assertSame($expected, ProviderStatusMap::guess((string) $raw), 'εικασία για: ' . $raw);
        }
    }
}
