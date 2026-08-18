<?php

/**
 * Η επιλογή κανόνα προμήθειας, δηλωμένη αντί να είναι εμπιστευμένη.
 *
 * Δύο από αυτά τα tests περιγράφουν συμπεριφορά που **δεν** είναι η επιθυμητή:
 * το πρόγραμμα χάνει από τον πάροχο. Είναι εκεί επίτηδες. Ο πίνακας κανόνων
 * ήταν άδειος στις 18/08/2026 και ο ιδιοκτήτης ανέβαλε ρητά την αλλαγή των
 * βαρών μέχρι να υπάρχουν κανόνες· όταν αλλάξουν, αυτά τα δύο θα κοκκινίσουν
 * και θα δείξουν ακριβώς ποια συμβόλαια μετακινούνται. Ένα test που καταγράφει
 * γνωστή ατέλεια αξίζει περισσότερο από ένα σχόλιο που την περιγράφει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Commission;

use EnergyCRM\Domain\Commission\RuleMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuleMatchTest extends TestCase
{
    /** Μια σύμβαση ΔΕΗ / πρόγραμμα 7 / ρεύμα / οικιακό. */
    private const CONTRACT = [
        'provider_id' => 3,
        'program_id'  => 7,
        'energy_type' => 'power',
        'category'    => 'home',
    ];

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function rule(array $overrides): array
    {
        return array_merge([
            'id'          => 1,
            'provider_id' => null,
            'program_id'  => null,
            'energy_type' => null,
            'category'    => null,
            'amount'      => '0.00',
        ], $overrides);
    }

    public function testNoRulesMeansNoCommission(): void
    {
        self::assertSame(0.0, RuleMatch::amountFor([], self::CONTRACT));
    }

    public function testARuleWithNoConditionsMatchesEverything(): void
    {
        $rules = [self::rule(['amount' => '5.00'])];

        self::assertSame(5.0, RuleMatch::amountFor($rules, self::CONTRACT));
    }

    public function testARuleThatContradictsTheContractIsSkipped(): void
    {
        $rules = [self::rule(['provider_id' => 99, 'amount' => '5.00'])];

        self::assertSame(0.0, RuleMatch::amountFor($rules, self::CONTRACT));
    }

    public function testTheMoreSpecificRuleWins(): void
    {
        $rules = [
            self::rule(['id' => 2, 'provider_id' => 3, 'energy_type' => 'power', 'amount' => '12.00']),
            self::rule(['id' => 1, 'provider_id' => 3, 'amount' => '8.00']),
        ];

        self::assertSame(12.0, RuleMatch::amountFor($rules, self::CONTRACT));
    }

    /**
     * Το λάθος που διορθώθηκε: δύο κανόνες ίδιας ειδικότητας έδιναν αποτέλεσμα
     * που εξαρτιόταν από τη σειρά της MySQL. Ο συνεργάτης έβλεπε ένα ποσό, η
     * εκκαθάριση υπολόγιζε άλλο, χωρίς να έχει αλλάξει τίποτα.
     *
     * Η σειρά είναι πλέον συμβόλαιο: ο καλών δίνει νεότερο πρώτο, και σε
     * ισοβαθμία κερδίζει αυτός.
     */
    public function testOnATieTheNewerRuleWins(): void
    {
        $newerFirst = [
            self::rule(['id' => 2, 'provider_id' => 3, 'amount' => '12.00']),
            self::rule(['id' => 1, 'provider_id' => 3, 'amount' => '10.00']),
        ];

        self::assertSame(12.0, RuleMatch::amountFor($newerFirst, self::CONTRACT));
    }

    /** Η ίδια λίστα ανάποδα δίνει το άλλο ποσό — γι' αυτό το ORDER BY. */
    public function testTheOrderOfEqualRulesIsWhatDecides(): void
    {
        $olderFirst = [
            self::rule(['id' => 1, 'provider_id' => 3, 'amount' => '10.00']),
            self::rule(['id' => 2, 'provider_id' => 3, 'amount' => '12.00']),
        ];

        self::assertSame(
            10.0,
            RuleMatch::amountFor($olderFirst, self::CONTRACT),
            'Αν αυτό αλλάξει, το ORDER BY id DESC στην ECRM_Commissions δεν είναι πια αυτό που ορίζει τη νίκη.'
        );
    }

    /**
     * ΓΝΩΣΤΗ ΑΤΕΛΕΙΑ — δες RuleMatch::WEIGHTS και docs/AUDIT-BACKEND.md (3).
     *
     * Ένα πρόγραμμα ανήκει σε έναν και μόνο πάροχο, άρα «όλοι οι πάροχοι +
     * πρόγραμμα 7» ταιριάζει σε αυστηρά λιγότερες συμβάσεις από «πάροχος 3,
     * οτιδήποτε». Παρ' όλα αυτά χάνει, 4 έναντι 8.
     *
     * Όταν αντιστραφούν τα βάρη, εδώ θα περιμένεις 20.0.
     */
    public function testAProgrammeOnlyRuleCurrentlyLosesToAProviderOnlyRule(): void
    {
        $rules = [
            self::rule(['id' => 2, 'program_id' => 7, 'amount' => '20.00']),
            self::rule(['id' => 1, 'provider_id' => 3, 'amount' => '8.00']),
        ];

        self::assertSame(8.0, RuleMatch::amountFor($rules, self::CONTRACT));
    }

    /** Με τον πάροχο δηλωμένο κιόλας, ο κανόνας προγράμματος κερδίζει κανονικά. */
    public function testAProgrammeRuleThatAlsoNamesItsProviderWins(): void
    {
        $rules = [
            self::rule(['id' => 2, 'provider_id' => 3, 'program_id' => 7, 'amount' => '20.00']),
            self::rule(['id' => 1, 'provider_id' => 3, 'amount' => '8.00']),
        ];

        self::assertSame(20.0, RuleMatch::amountFor($rules, self::CONTRACT));
    }

    /**
     * Ο παλιός κώδικας έγραφε `if ( $r['provider_id'] )`. Η μεταφορά έπρεπε να
     * κρατήσει ότι το 0, το '0', το '' και το null σημαίνουν όλα «οποιοδήποτε»
     * — αλλιώς κανόνες που σήμερα ταιριάζουν παντού θα σταματούσαν να ταιριάζουν.
     */
    #[DataProvider('emptyConstraints')]
    public function testAnEmptyConstraintMeansAny(mixed $empty): void
    {
        $rules = [self::rule(['provider_id' => $empty, 'amount' => '5.00'])];

        self::assertSame(5.0, RuleMatch::amountFor($rules, self::CONTRACT));
    }

    /** @return array<string, array{0: mixed}> */
    public static function emptyConstraints(): array
    {
        return [
            'null'          => [null],
            'μηδέν'         => [0],
            'μηδέν ως text' => ['0'],
            'κενό'          => [''],
        ];
    }

    /**
     * Οι ταυτότητες συγκρίνονται ως αριθμοί επειδή η βάση τις επιστρέφει ως
     * κείμενο: '3' από τον κανόνα και 3 από τη σύμβαση είναι το ίδιο πράγμα.
     */
    public function testIdentifiersCompareNumericallyAcrossStringAndInt(): void
    {
        $rules = [self::rule(['provider_id' => '3', 'amount' => '7.00'])];

        self::assertSame(7.0, RuleMatch::amountFor($rules, self::CONTRACT));
    }

    /** Ένα πεδίο που λείπει από τη σύμβαση δεν ταιριάζει σε δηλωμένο κριτήριο. */
    public function testAMissingContractFieldDoesNotSatisfyAConstraint(): void
    {
        $rules = [self::rule(['category' => 'business', 'amount' => '9.00'])];

        self::assertSame(0.0, RuleMatch::amountFor($rules, ['provider_id' => 3]));
    }
}
