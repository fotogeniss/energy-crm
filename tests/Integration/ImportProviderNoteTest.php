<?php

/**
 * Το σχόλιο του παρόχου, από το Excel, φτάνει στο ιστορικό της σύμβασης.
 *
 * ## Τι μετρήθηκε πριν γραφτεί αυτό
 *
 * Η ροή "Εισαγωγή Excel παρόχου" υπήρχε ήδη ολόκληρη -- ανάγνωση αρχείου,
 * αντιστοίχιση στηλών, προεπισκόπηση που σέβεται τον γράφο, εφαρμογή μέσω
 * ContractLifecycle. Αυτό που ΔΕΝ υπήρχε ήταν το κείμενο του παρόχου: η
 * `apply()` έγραφε πάντα το ίδιο σταθερό μήνυμα ('Ενημέρωση από Excel
 * παρόχου'), και ό,τι έγραφε ο πάροχος στη διπλανή στήλη -- η αιτιολογία
 * μιας απόρριψης, λόγου χάρη -- πετιόταν.
 *
 * Δεύτερο, ανεξάρτητο εύρημα στην ίδια μέτρηση: το `message` ενός
 * `status_change` γεγονότος δεν εμφανιζόταν ΠΟΥΘΕΝΑ στην οθόνη, σε καμία
 * διαδρομή -- όχι μόνο στην εισαγωγή. Η `ecrm-view-detail.js` έδειχνε μόνο
 * «από → προς». Αυτό διορθώθηκε ξεχωριστά (`ecrm-view-detail.js`,
 * `ecrm-app.css`) και δεν καλύπτεται εδώ, που δοκιμάζει μόνο το backend.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Import;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Services;

final class ImportProviderNoteTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private int $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->partner   = $this->makeCrmUser(Roles::SELLER);

        wp_set_current_user($this->partner);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    private function contractWith(string $status, string $supply): int
    {
        $id = $this->contracts->create(
            ['status' => $status, 'supply_number' => $supply, 'energy_type' => 'power'],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $id);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function eventsFor(int $contractId): array
    {
        return Services::events()->forContract($contractId);
    }

    public function testStatusChangeCarriesTheProviderNote(): void
    {
        $id = $this->contractWith('new', '11100000001');

        $report = ECRM_Import::apply(
            [['supply' => '11100000001', 'status' => 'processing', 'message' => 'Καθυστέρηση εγγράφων']],
            false
        );

        self::assertSame(1, $report['updated']);
        self::assertSame(0, $report['noted']);

        $events = $this->eventsFor($id);
        self::assertCount(1, $events);
        self::assertSame('status_change', $events[0]['type']);
        self::assertSame(
            'Ενημέρωση από Excel παρόχου: «Καθυστέρηση εγγράφων»',
            $events[0]['message']
        );
    }

    /** Χωρίς σχόλιο, το μήνυμα μένει ακριβώς όπως ήταν πριν από αυτή την αλλαγή. */
    public function testStatusChangeWithoutNoteKeepsTheGenericMessage(): void
    {
        $id = $this->contractWith('new', '11100000002');

        ECRM_Import::apply([['supply' => '11100000002', 'status' => 'processing']], false);

        $events = $this->eventsFor($id);
        self::assertCount(1, $events);
        self::assertSame('Ενημέρωση από Excel παρόχου', $events[0]['message']);
    }

    /**
     * Ίδια κατάσταση + νέο σχόλιο = γεγονός, όχι σιωπή. Απόφαση ιδιοκτήτη
     * 27/08/2026 -- «είπε κάτι» μετράει ακόμα κι όταν δεν κουνήθηκε τίποτα.
     */
    public function testUnchangedStatusWithNewNoteWritesAPlainNoteEvent(): void
    {
        $id = $this->contractWith('processing', '11100000003');

        $report = ECRM_Import::apply(
            [['supply' => '11100000003', 'status' => 'processing', 'message' => 'Ο μετρητής άλλαξε']],
            false
        );

        self::assertSame(0, $report['updated']);
        self::assertSame(0, $report['unchanged']);
        self::assertSame(1, $report['noted']);

        $events = $this->eventsFor($id);
        self::assertCount(1, $events);
        // ΟΧΙ 'status_change' -- θα ζωγράφιζε «Σε επεξεργασία → Σε επεξεργασία».
        self::assertSame('note', $events[0]['type']);
        self::assertSame('Ο πάροχος (Excel): «Ο μετρητής άλλαξε»', $events[0]['message']);
    }

    /** Η προηγούμενη συμπεριφορά: ίδια κατάσταση, τίποτα να πει κανείς, καμία εγγραφή. */
    public function testUnchangedStatusWithoutNoteWritesNothing(): void
    {
        $id = $this->contractWith('processing', '11100000004');

        $report = ECRM_Import::apply([['supply' => '11100000004', 'status' => 'processing']], false);

        self::assertSame(1, $report['unchanged']);
        self::assertSame(0, $report['noted']);
        self::assertSame([], $this->eventsFor($id));
    }

    /**
     * 300 χαρακτήρες, απόφαση ιδιοκτήτη 27/08/2026 -- αρκεί για μια πρόταση
     * αιτιολογίας, δεν αφήνει ολόκληρη ελεύθερη στήλη να γεμίσει το ιστορικό.
     */
    public function testProviderNoteIsTruncatedAt300Characters(): void
    {
        $id  = $this->contractWith('processing', '11100000005');
        $raw = str_repeat('α', 350);

        ECRM_Import::apply([['supply' => '11100000005', 'status' => 'processing', 'message' => $raw]], false);

        $events = $this->eventsFor($id);
        self::assertCount(1, $events);
        // Το μήνυμα είναι 'Ο πάροχος (Excel): «' . note . '»' -- αφαιρούμε το
        // περιτύλιγμα για να μετρήσουμε μόνο το κομμένο σχόλιο.
        $note = mb_substr(
            (string) $events[0]['message'],
            mb_strlen('Ο πάροχος (Excel): «'),
            -mb_strlen('»')
        );
        self::assertSame(300, mb_strlen($note));
    }

    /** Το dry run μετρά, δεν γράφει -- ίδιος κανόνας με τις πραγματικές αλλαγές κατάστασης. */
    public function testDryRunReportsNotedWithoutWriting(): void
    {
        $id = $this->contractWith('processing', '11100000006');

        $report = ECRM_Import::apply(
            [['supply' => '11100000006', 'status' => 'processing', 'message' => 'Θα γραφτεί;']],
            true
        );

        self::assertSame(1, $report['noted']);
        self::assertSame([], $this->eventsFor($id));
    }
}
