<?php

/**
 * (419) Κάθε εγγραφή CHANGELOG από την (419) και μετά λέει ποιους κανόνες του
 * `docs/KANONES.md` αγγίζει: `**Κανόνες:** K1, K3` ή `**Κανόνες:** κανένας`.
 *
 * Είναι η στιγμή της διαδικασίας 1: «μπορεί η αλλαγή μου να παρακάμψει κανόνα;».
 * Αν η γραμμή λείπει, η ερώτηση δεν έγινε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ChangelogNamesTheRulesTest extends TestCase
{
    private const FIRST_ENTRY_UNDER_THE_RULE = 419;

    public function testEveryNewEntryNamesTheRulesItTouches(): void
    {
        $rules   = array_keys(EveryRuleHasATestTest::rules());
        $missing = [];
        $unknown = [];

        foreach (ChangelogEntries::all() as $label => $body) {
            if (ChangelogEntries::number($label) < self::FIRST_ENTRY_UNDER_THE_RULE) {
                continue;
            }

            if (preg_match('/^\*\*Κανόνες:\*\*(.*)$/mu', $body, $m) !== 1) {
                $missing[] = $label;
                continue;
            }

            preg_match_all('/K\d+/', $m[1], $named);

            foreach (array_diff($named[0], $rules) as $bad) {
                $unknown[] = $label . ': ' . $bad;
            }
        }

        self::assertSame([], $missing, 'Εγγραφές χωρίς «**Κανόνες:**»: ' . implode(', ', $missing));
        self::assertSame([], $unknown, 'Κανόνες που δεν υπάρχουν: ' . implode(', ', $unknown));
    }
}
