<?php

/**
 * Η επιλογή κανόνα εγγύησης, δηλωμένη αντί να είναι εμπιστευμένη.
 *
 * Δύο πράγματα ελέγχονται εδώ που ο αδελφός `RuleMatchTest` δεν μπορεί να
 * ελέγξει, και είναι ακριβώς τα σημεία όπου η μηχανή εγγυήσεων αποκλίνει
 * σκόπιμα από εκείνη των προμηθειών:
 *
 * 1. **Το «καμία πρόταση» ξεχωρίζει από το «πρόταση 0 €».** Ο πάροχος που δεν
 *    ζητά εγγύηση δεν είναι το ίδιο πράγμα με τον πάροχο για τον οποίο δεν
 *    ξέρουμε τίποτα, και η οθόνη πρέπει να μπορεί να τα πει διαφορετικά.
 * 2. **Το πρόγραμμα νικά τον πάροχο.** Ο `RuleMatchTest` καταγράφει ρητά το
 *    αντίθετο ως γνωστή ατέλεια που ο ιδιοκτήτης ανέβαλε· εδώ ο πίνακας
 *    γεννιέται άδειος, οπότε μπαίνει σωστά από την πρώτη μέρα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Guarantee;

use EnergyCRM\Domain\Guarantee\GuaranteeMatch;
use PHPUnit\Framework\TestCase;

final class GuaranteeMatchTest extends TestCase
{
    /** Μια σύμβαση ΔΕΗ / πρόγραμμα 7 / ρεύμα / οικιακό / 8 kVA. */
    private const CONTRACT = [
        'provider_id'  => 3,
        'program_id'   => 7,
        'energy_type'  => 'power',
        'category'     => 'home',
        'agreed_power' => '8',
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
            'kva_min'     => null,
            'kva_max'     => null,
            'amount'      => '0.00',
        ], $overrides);
    }

    // --- σιωπή vs μηδέν ----------------------------------------------------

    public function testNoRulesMeansNoSuggestionAtAll(): void
    {
        self::assertNull(GuaranteeMatch::amountFor([], self::CONTRACT));
    }

    public function testAZeroRuleIsARealSuggestionAndNotSilence(): void
    {
        $rules = [self::rule(['provider_id' => 3, 'amount' => '0.00'])];

        self::assertSame(0.0, GuaranteeMatch::amountFor($rules, self::CONTRACT));
    }

    public function testARuleThatDoesNotMatchLeavesNoSuggestion(): void
    {
        $rules = [self::rule(['provider_id' => 99, 'amount' => '150.00'])];

        self::assertNull(GuaranteeMatch::amountFor($rules, self::CONTRACT));
    }

    // --- ειδικότητα --------------------------------------------------------

    public function testARuleWithNoConditionsMatchesEverything(): void
    {
        $rules = [self::rule(['amount' => '120.00'])];

        self::assertSame(120.0, GuaranteeMatch::amountFor($rules, self::CONTRACT));
    }

    public function testTheProgramRuleBeatsTheProviderRule(): void
    {
        $rules = [
            self::rule(['id' => 2, 'program_id' => 7, 'amount' => '90.00']),
            self::rule(['id' => 1, 'provider_id' => 3, 'amount' => '150.00']),
        ];

        self::assertSame(90.0, GuaranteeMatch::amountFor($rules, self::CONTRACT));
    }

    /** Η σειρά της λίστας δεν αλλάζει τον νικητή — μόνο η ειδικότητα. */
    public function testTheProgramRuleStillWinsWhenItComesSecond(): void
    {
        $rules = [
            self::rule(['id' => 2, 'provider_id' => 3, 'amount' => '150.00']),
            self::rule(['id' => 1, 'program_id' => 7, 'amount' => '90.00']),
        ];

        self::assertSame(90.0, GuaranteeMatch::amountFor($rules, self::CONTRACT));
    }

    public function testEqualSpecificityIsBrokenByTheNewestRuleComingFirst(): void
    {
        $rules = [
            self::rule(['id' => 2, 'provider_id' => 3, 'amount' => '200.00']),
            self::rule(['id' => 1, 'provider_id' => 3, 'amount' => '150.00']),
        ];

        self::assertSame(200.0, GuaranteeMatch::amountFor($rules, self::CONTRACT));
    }

    // --- κλίμακα kVA -------------------------------------------------------

    public function testAPowerBandMatchesInsideItsBounds(): void
    {
        $rules = [self::rule(['kva_min' => '5', 'kva_max' => '15', 'amount' => '110.00'])];

        self::assertSame(110.0, GuaranteeMatch::amountFor($rules, self::CONTRACT));
    }

    public function testBothBoundsAreInclusive(): void
    {
        $lower = [self::rule(['kva_min' => '8', 'kva_max' => '15', 'amount' => '110.00'])];
        $upper = [self::rule(['kva_min' => '1', 'kva_max' => '8', 'amount' => '70.00'])];

        self::assertSame(110.0, GuaranteeMatch::amountFor($lower, self::CONTRACT));
        self::assertSame(70.0, GuaranteeMatch::amountFor($upper, self::CONTRACT));
    }

    public function testAPowerBandOutsideTheContractDoesNotMatch(): void
    {
        $rules = [self::rule(['kva_min' => '15', 'amount' => '300.00'])];

        self::assertNull(GuaranteeMatch::amountFor($rules, self::CONTRACT));
    }

    public function testAnOpenEndedBandOnlyConstrainsTheEndItDeclares(): void
    {
        $rules = [self::rule(['kva_max' => '25', 'amount' => '130.00'])];

        self::assertSame(130.0, GuaranteeMatch::amountFor($rules, self::CONTRACT));
    }

    /** «8,5» και «8 kVA» γράφτηκαν και τα δύο σε αυτό το ελεύθερο πεδίο. */
    public function testThePowerFieldIsReadAsWrittenByAHuman(): void
    {
        $rules = [self::rule(['kva_min' => '8', 'kva_max' => '9', 'amount' => '110.00'])];

        self::assertSame(110.0, GuaranteeMatch::amountFor($rules, ['agreed_power' => '8,5']));
        self::assertSame(110.0, GuaranteeMatch::amountFor($rules, ['agreed_power' => ' 8 kVA ']));
    }

    /**
     * Κανόνας που μιλά για ισχύ, σύμβαση που δεν τη δηλώνει: σιωπή, όχι εικασία.
     *
     * Η εναλλακτική — να θεωρηθεί η κενή ισχύς μηδέν — θα πρότεινε στον πωλητή
     * το ποσό της χαμηλότερης κλίμακας κάθε φορά που ξεχνιέται ένα πεδίο, και
     * θα το πρότεινε με την ίδια σιγουριά με τα σωστά.
     */
    public function testAMissingPowerCannotSatisfyAPowerRule(): void
    {
        $rules = [self::rule(['kva_min' => '1', 'kva_max' => '15', 'amount' => '110.00'])];

        self::assertNull(GuaranteeMatch::amountFor($rules, ['agreed_power' => '']));
        self::assertNull(GuaranteeMatch::amountFor($rules, ['agreed_power' => 'τριφασική']));
        self::assertNull(GuaranteeMatch::amountFor($rules, []));
    }

    /** Χωρίς όρια ισχύος, η κενή ισχύς δεν εμποδίζει τίποτα. */
    public function testARuleWithoutBandIgnoresTheMissingPower(): void
    {
        $rules = [self::rule(['provider_id' => 3, 'amount' => '150.00'])];

        self::assertSame(150.0, GuaranteeMatch::amountFor($rules, ['provider_id' => 3]));
    }

    // --- συνδυασμοί --------------------------------------------------------

    public function testEveryDeclaredCriterionMustMatch(): void
    {
        $rules = [self::rule([
            'provider_id' => 3,
            'energy_type' => 'gas',
            'amount'      => '150.00',
        ])];

        self::assertNull(GuaranteeMatch::amountFor($rules, self::CONTRACT));
    }

    public function testTheNarrowerOfTwoMatchingRulesWins(): void
    {
        $rules = [
            self::rule(['id' => 2, 'provider_id' => 3, 'amount' => '150.00']),
            self::rule(['id' => 1, 'provider_id' => 3, 'kva_min' => '5', 'kva_max' => '15', 'amount' => '110.00']),
        ];

        self::assertSame(110.0, GuaranteeMatch::amountFor($rules, self::CONTRACT));
    }
}
