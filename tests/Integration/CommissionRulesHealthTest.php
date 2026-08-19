<?php

/**
 * Η οθόνη Υγεία λέει αν υπάρχει κανόνας προμήθειας.
 *
 * Είναι ο πιο αθόρυβος τρόπος να αποτύχει αυτό το σύστημα. Με άδειο
 * `commission_rules` η `amount_for()` επιστρέφει 0 για τα πάντα: κάθε γραμμή
 * της οθόνης Προμήθειες, κάθε κατάταξη, κάθε εκκαθάριση, κάθε βεβαίωση.
 * Τίποτα δεν σκάει και κανείς δεν πληρώνεται.
 *
 * Στις 18/08/2026 ο πίνακας ήταν όντως άδειος και οι δεκαεπτά υπόλοιποι
 * έλεγχοι της οθόνης ήταν πράσινοι. Αυτό το test υπάρχει ώστε να μη γίνει ξανά
 * η ίδια σιωπή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\HealthChecks;
use EnergyCRM\Persistence\Tables;

final class CommissionRulesHealthTest extends IntegrationTestCase
{
    private const LABEL = 'Ενεργοί κανόνες';

    /** Άδειος πίνακας: κόκκινο, με τη φράση που εξηγεί τι σημαίνει. */
    public function testAnEmptyRuleTableIsReportedAsAProblem(): void
    {
        $check = $this->check();

        self::assertFalse($check['ok']);
        self::assertStringContainsString('0 €', $check['detail']);
    }

    /** Ένας ενεργός κανόνας αρκεί για να έχουν οι υπολογισμοί πάνω σε τι να πέσουν. */
    public function testOneActiveRuleTurnsTheCheckGreen(): void
    {
        $this->makeRule(1);

        self::assertTrue($this->check()['ok']);
    }

    /**
     * Ανενεργός κανόνας δεν μετράει.
     *
     * Χωρίς αυτό, ο έλεγχος θα γινόταν πράσινος από έναν κανόνα που κανείς δεν
     * χρησιμοποιεί — δηλαδή θα καθησύχαζε ακριβώς στην περίπτωση που κάποιος
     * απενεργοποίησε τον τελευταίο του.
     */
    public function testAnInactiveRuleDoesNotCount(): void
    {
        $this->makeRule(0);

        self::assertFalse($this->check()['ok']);
    }

    /**
     * Ο έλεγχος των κανόνων, από όλη τη λίστα της οθόνης.
     *
     * @return array{group: string, label: string, ok: bool|null, detail: string}
     */
    private function check(): array
    {
        foreach ((new HealthChecks())->all() as $row) {
            if ($row['label'] === self::LABEL) {
                return $row;
            }
        }

        self::fail('Ο έλεγχος «' . self::LABEL . '» δεν υπάρχει στην οθόνη Υγεία.');
    }

    private function makeRule(int $active): void
    {
        global $wpdb;

        // provider_id NULL, δηλαδή «όλοι οι πάροχοι»: υπάρχει ξένο κλειδί προς
        // τον πίνακα παρόχων, οπότε το 0 δεν είναι «κανένας» — είναι πάροχος
        // που δεν υπάρχει, και η βάση το απορρίπτει. Ο κανόνας δοκιμής δεν
        // χρειάζεται πάροχο· χρειάζεται μόνο να είναι ενεργός ή όχι.
        $wpdb->insert(Tables::name(Tables::COMMISSION_RULES), [
            'provider_id' => null,
            'amount'      => 10,
            'active'      => $active,
        ]);

        self::assertSame('', $wpdb->last_error, 'Ο κανόνας δοκιμής δεν αποθηκεύτηκε.');
    }
}
