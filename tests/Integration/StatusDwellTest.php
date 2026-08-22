<?php

/**
 * Ο χρόνος στην κατάσταση, και ο συνήθης χρόνος των άλλων.
 *
 * Τρία πράγματα φυλάει αυτό το αρχείο, και κανένα δεν είναι «βγάζει σωστό
 * νούμερο» γενικά:
 *
 * 1. **Ότι μετριέται η κατάσταση, όχι το άγγιγμα.** Η κάρτα του πίνακα μετράει
 *    `updated_at` — «πόσο καιρό δεν την άγγιξε κανείς» — και είναι σωστό εκεί.
 *    Εδώ η ερώτηση είναι άλλη, και μια διόρθωση σε σημείωση δεν πρέπει να
 *    μηδενίζει τον χρόνο.
 *
 * 2. **Ότι ο διάμεσος αντέχει τον έναν ξεχασμένο.** Με μέσο όρο, μία σύμβαση
 *    ξεχασμένη 200 μέρες κάνει το «συνηθισμένο» τόσο μεγάλο ώστε τίποτα να μη
 *    φαίνεται ποτέ αργό — δηλαδή η κάρτα θα σιωπούσε ακριβώς όταν χρειάζεται.
 *
 * 3. **Ότι κάτω από δείγμα δεν λέγεται τίποτα.** Ένα ψεύτικο «αυτή αργεί»
 *    εκπαιδεύει τον συνεργάτη να αγνοεί την κάρτα, και τότε τη χάνει και τις
 *    φορές που έχει δίκιο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\StatusDwellRepository;
use EnergyCRM\Persistence\Tables;

final class StatusDwellTest extends IntegrationTestCase
{
    private StatusDwellRepository $dwell;

    private int $partner;

    protected function setUp(): void
    {
        parent::setUp();

        // Χωρίς προσωρινή αποθήκευση: αλλιώς ο δεύτερος έλεγχος θα διάβαζε το
        // αποτέλεσμα του πρώτου και θα περνούσε χωρίς να τρέξει ερώτημα.
        $this->dwell   = new StatusDwellRepository(0);
        $this->partner = $this->makePartner();
    }

    private function contract(string $status, int $providerId = 1): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $this->partner,
            'provider_id'     => $providerId,
            'status'          => $status,
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
        ]);

        return (int) $wpdb->insert_id;
    }

    /** Γεγονός κατάστασης N μέρες πριν, ΣΧΕΤΙΚΑ με το τώρα της βάσης. */
    private function moved(int $contractId, string $to, int $daysAgo): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (contract_id, user_id, type, to_status, created_at)
                 VALUES (%d, 0, %s, %s, NOW() - INTERVAL %d DAY)',
                Tables::name(Tables::EVENTS),
                $contractId,
                'status_change',
                $to,
                $daysAgo
            )
        );
    }

    /** Μία σύμβαση που μπήκε σε κατάσταση και έφυγε μετά από N μέρες. */
    private function dwelt(string $status, int $days, int $providerId = 1): void
    {
        $id = $this->contract('routed', $providerId);

        $this->moved($id, $status, 40);
        $this->moved($id, 'routed', 40 - $days);
    }

    // ── 1. Ο χρόνος ΑΥΤΗΣ της σύμβασης ──────────────────────────────────

    public function testWithNoHistoryTheAnswerIsNullAndNotZero(): void
    {
        $id = $this->contract('pending');

        // Σύμβαση φτιαγμένη με raw SQL δεν έχει γεγονότα. Ένα 0 εδώ θα έλεγε
        // «μπήκε σήμερα» για κάτι που δεν ξέρουμε πότε μπήκε.
        self::assertNull($this->dwell->daysInStatus($id, 'pending'));
    }

    public function testItCountsFromTheEntryIntoTheStatus(): void
    {
        $id = $this->contract('pending');
        $this->moved($id, 'pending', 11);

        self::assertSame(11, $this->dwell->daysInStatus($id, 'pending'));
    }

    public function testAContractThatCameBackCountsFromTheLastEntry(): void
    {
        $id = $this->contract('pending');

        $this->moved($id, 'pending', 30);
        $this->moved($id, 'processing', 20);
        $this->moved($id, 'pending', 3);

        // Τρεις μέρες, όχι τριάντα: ο συνεργάτης βλέπει την τωρινή παραμονή.
        self::assertSame(3, $this->dwell->daysInStatus($id, 'pending'));
    }

    // ── 2. Ο συνήθης χρόνος ─────────────────────────────────────────────

    public function testBelowTheSampleFloorNothingIsClaimed(): void
    {
        for ($i = 0; $i < StatusDwellRepository::MIN_SAMPLE - 1; $i++) {
            $this->dwelt('pending', 4);
        }

        self::assertNull($this->dwell->typicalDays('pending', 1));
    }

    public function testAtTheSampleFloorItSpeaks(): void
    {
        for ($i = 0; $i < StatusDwellRepository::MIN_SAMPLE; $i++) {
            $this->dwelt('pending', 4);
        }

        $typical = $this->dwell->typicalDays('pending', 1);

        self::assertNotNull($typical);
        self::assertSame(4, $typical['days']);
        self::assertSame(StatusDwellRepository::MIN_SAMPLE, $typical['sample']);
    }

    /**
     * Ο ΕΛΕΓΧΟΣ ΠΟΥ ΔΙΚΑΙΟΛΟΓΕΙ ΤΟΝ ΔΙΑΜΕΣΟ.
     *
     * Εννιά συμβάσεις των 4 ημερών και μία ξεχασμένη 200. Ο μέσος όρος βγαίνει
     * 24 μέρες — δηλαδή μια σύμβαση 20 ημερών θα θεωρούνταν φυσιολογική και η
     * κάρτα δεν θα εμφανιζόταν ποτέ.
     */
    public function testOneForgottenContractDoesNotMoveTheTypicalTime(): void
    {
        for ($i = 0; $i < StatusDwellRepository::MIN_SAMPLE - 1; $i++) {
            $this->dwelt('pending', 4);
        }

        $this->dwelt('pending', 200);

        $typical = $this->dwell->typicalDays('pending', 1);

        self::assertNotNull($typical);
        self::assertSame(4, $typical['days'], 'Ο διάμεσος αγνοεί την ουρά· ο μέσος όρος θα έλεγε 24.');
    }

    public function testContractsStillSittingThereAreNotCounted(): void
    {
        for ($i = 0; $i < StatusDwellRepository::MIN_SAMPLE; $i++) {
            $this->dwelt('pending', 4);
        }

        // Πέντε κολλημένες, χωρίς έξοδο. Αν μετρούσαν, το «συνηθισμένο» θα
        // μεγάλωνε όσο μεγαλώνει η ουρά — και η κάρτα θα σταματούσε να χτυπάει
        // ακριβώς όταν τα πράγματα χειροτερεύουν.
        for ($i = 0; $i < 5; $i++) {
            $stuck = $this->contract('pending');
            $this->moved($stuck, 'pending', 90);
        }

        $typical = $this->dwell->typicalDays('pending', 1);

        self::assertNotNull($typical);
        self::assertSame(StatusDwellRepository::MIN_SAMPLE, $typical['sample']);
        self::assertSame(4, $typical['days']);
    }

    public function testEachProviderIsMeasuredOnItsOwn(): void
    {
        for ($i = 0; $i < StatusDwellRepository::MIN_SAMPLE; $i++) {
            $this->dwelt('pending', 3, 1);
        }
        for ($i = 0; $i < StatusDwellRepository::MIN_SAMPLE; $i++) {
            $this->dwelt('pending', 20, 2);
        }

        self::assertSame(3, $this->dwell->typicalDays('pending', 1)['days']);
        self::assertSame(20, $this->dwell->typicalDays('pending', 2)['days']);

        // Χωρίς πάροχο: όλες μαζί, οπότε ο διάμεσος πέφτει ανάμεσα.
        $all = $this->dwell->typicalDays('pending', null);

        self::assertNotNull($all);
        self::assertSame(StatusDwellRepository::MIN_SAMPLE * 2, $all['sample']);
    }

    public function testAStatusNobodyHasBeenInSaysNothing(): void
    {
        self::assertNull($this->dwell->typicalDays('terminated', null));
    }
}
