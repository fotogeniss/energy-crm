<?php

/**
 * `Tables::all()` -- κάθε πίνακας που δηλώνει το dbDelta, κανένας παραπάνω.
 *
 * AUDIT εύρημα, 29/08: το `kb_read` υπάρχει στο dbDelta (class-ecrm-db.php)
 * από τότε που φτιάχτηκε το feature, αλλά έλειπε από αυτή τη λίστα -- το
 * ΜΟΝΟ σημείο που ρωτά «ποιοι πίνακες υπάρχουν;» για την οθόνη Υγείας
 * (HealthChecks::schema()) και για τη μετατροπή σε InnoDB (EnsureInnoDb,
 * 0003). Η οθόνη έλεγε «14 από 14» ενώ υπήρχε 15ος πίνακας απαρατήρητος --
 * ένας χαμένος πίνακας δεν θα το πρόσεχε ΠΟΤΕ ο έλεγχος που έχτισε ακριβώς
 * γι' αυτό. Το `kb_read` βρέθηκε ήδη InnoDB (MySQL 8 default engine), οπότε
 * δεν χρειάστηκε νέο migration -- μόνο η λίστα να λέει αλήθεια.
 *
 * Δύο κατευθύνσεις, και οι δύο εδώ: κάθε δηλωμένος πίνακας πρέπει να
 * υπάρχει πραγματικά (αλλιώς ένα φανταστικό όνομα θα περνούσε αθόρυβα), και
 * η ίδια η οθόνη Υγείας πρέπει να δείχνει το σωστό πλήθος.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\HealthChecks;
use EnergyCRM\Persistence\Tables;

final class TablesListMatchesTheRealSchemaTest extends IntegrationTestCase
{
    public function testKbReadIsNoLongerMissing(): void
    {
        self::assertContains(Tables::KB_READ, Tables::all());
    }

    /** Κάθε πίνακας που δηλώνει η λίστα υπάρχει πραγματικά στη βάση. */
    public function testEveryDeclaredTableActuallyExists(): void
    {
        global $wpdb;

        foreach (Tables::all() as $table) {
            $name = Tables::name($table);

            self::assertSame(
                $name,
                $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)),
                "Ο πίνακας '{$table}' δηλώνεται στο Tables::all() αλλά δεν υπάρχει."
            );
        }
    }

    /** Η ίδια η οθόνη Υγείας μετράει σωστά, όχι μόνο η λίστα από κάτω. */
    public function testTheHealthScreenReportsEveryTable(): void
    {
        $rows = (new HealthChecks())->all();

        $tablesRow = null;

        foreach ($rows as $row) {
            if ($row['group'] === 'Βάση' && $row['label'] === 'Πίνακες') {
                $tablesRow = $row;

                break;
            }
        }

        self::assertNotNull($tablesRow, 'Δεν βρέθηκε η γραμμή "Βάση / Πίνακες" στην οθόνη Υγείας.');
        self::assertTrue($tablesRow['ok']);
        self::assertSame(count(Tables::all()) . ' πίνακες στη θέση τους.', $tablesRow['detail']);
    }
}
