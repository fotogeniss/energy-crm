<?php

/**
 * Η στήλη `contracts.updated_at` γράφεται από ΕΝΑ ρολόι — της βάσης.
 *
 * ## Τι έσπερνε δύο ρολόγια στην ίδια στήλη
 *
 * Η CREATE TABLE βάζει ήδη `updated_at DATETIME ... ON UPDATE CURRENT_TIMESTAMP`
 * — το ρολόι ΤΗΣ MySQL. Η `ContractTransitions::applyTransition()` όμως έγραφε
 * πάνω από αυτό, στο ίδιο UPDATE, `current_time('mysql')` — το ρολόι ΤΗΣ PHP,
 * που ακολουθεί το `timezone_string` του site. Όσο οι δύο ζώνες συμπίπτουν δεν
 * φαίνεται τίποτα· τη στιγμή που αποκλίνουν, η στήλη λέει ψέματα για το πόσο
 * καιρό μια σύμβαση δεν έχει αγγιχτεί — και το `DashboardRepository::
 * oldestPerStatus()` τη διαβάζει ακριβώς γι' αυτό (`DATEDIFF(NOW(), MIN(updated_at))`,
 * δηλαδή συγκρίνει το ρολόι ΤΗΣ ΒΑΣΗΣ με μια τιμή που έγραφε η PHP).
 *
 * Ίδιο σχήμα με το (83) στο `PayoutRepository::markPaid()` — δες
 * PayoutPaidAtTest. Η λύση είναι η ίδια: γράφει η βάση, με `NOW()`, όχι η PHP.
 *
 * ## Γιατί δύο ξεχωριστοί έλεγχοι
 *
 * Ο πρώτος είναι ο καθιερωμένος έλεγχος-ζώνης (σύγκριση δύο στηλών μεταξύ
 * τους, όχι με σταθερή ώρα) — αλλά περνάει ακόμα κι όταν οι δύο ζώνες τυχαία
 * συμπίπτουν, όπως εδώ σήμερα. Δεν αρκεί μόνος του.
 *
 * Ο δεύτερος είναι αυτός που πραγματικά εξηγεί γιατί η διόρθωση δεν έγινε με
 * μία ενιαία `$wpdb->update()`: η `moveTo()` καλεί `applyTransition()` ακόμα
 * και όταν η κατάσταση ΔΕΝ αλλάζει (`force => true`). Αν το `updated_at`
 * εξαρτιόταν από το σιωπηλό `ON UPDATE CURRENT_TIMESTAMP` της MySQL, το αν
 * αυτό ενεργοποιείται όταν η γραμμένη τιμή είναι ίδια με την προηγούμενη είναι
 * στοίχημα ανά έκδοση/ρύθμιση — όχι σιγουριά. Η ρητή δεύτερη ερώτηση με NOW()
 * δεν έχει αυτό το ερώτημα, και αυτός ο έλεγχος το αποδεικνύει μετρώντας το.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class ContractUpdatedAtTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private ContractLifecycle $lifecycle;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->lifecycle = Services::lifecycle();

        $partner = $this->makePartner();

        $this->contractId = $this->contracts->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($partner)
        );

        self::assertGreaterThan(0, $this->contractId);
    }

    /**
     * Ο καθιερωμένος έλεγχος-ζώνης: δύο στήλες της ίδιας γραμμής, μεταξύ τους.
     *
     * Το `created_at` το γράφει η MySQL (`DEFAULT CURRENT_TIMESTAMP`). Αν το
     * `updated_at` ξαναγραφτεί από ρολόι PHP που αποκλίνει, η απόσταση των δύο
     * θα φανεί εδώ πρώτα.
     */
    public function testUpdatedAtIsInTheSameZoneAsCreatedAtBesideIt(): void
    {
        $this->lifecycle->moveTo($this->contractId, 'processing');

        $row     = $this->storedRow('contracts', $this->contractId);
        $created = strtotime((string) $row['created_at']);
        $updated = strtotime((string) $row['updated_at']);

        self::assertNotFalse($created);
        self::assertNotFalse($updated);

        // Δέκα λεπτά ανοχή για αργή σουίτα· η παλιά συμπεριφορά έδινε ώρες
        // απόκλισης όταν οι δύο ζώνες διαφέρουν.
        self::assertLessThan(
            600,
            abs($updated - $created),
            'Το updated_at και το created_at πρέπει να διαβάζονται με τον ίδιο τρόπο — αλλιώς μια '
            . 'σύμβαση φαίνεται ενημερωμένη πριν δημιουργηθεί, και το «πόσο καιρό δεν κινήθηκε» στο '
            . 'dashboard λέει ψέματα.'
        );
    }

    /**
     * Το σημείο που δεν θα το έπιανε ο πρώτος έλεγχος αν βασιζόταν στο
     * σιωπηλό `ON UPDATE CURRENT_TIMESTAMP` της MySQL.
     *
     * `force => true` καλεί applyTransition() ενώ η κατάσταση μένει ίδια. Το
     * updated_at ξεκινά τεχνητά παλιό ώστε η ανανέωση να είναι μετρήσιμη
     * ανεξάρτητα από το αν η στιγμή πέφτει στο ίδιο δευτερόλεπτο.
     */
    public function testForceStillRefreshesUpdatedAtEvenWithoutAStatusChange(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET updated_at = '2020-01-01 00:00:00' WHERE id = %d",
            Tables::name(Tables::CONTRACTS),
            $this->contractId
        ));

        $this->lifecycle->moveTo($this->contractId, 'new', ['force' => true]);

        self::assertNotSame(
            '2020-01-01 00:00:00',
            $this->storedRow('contracts', $this->contractId)['updated_at'],
            'Μια force μετάβαση χωρίς αλλαγή κατάστασης πρέπει ΚΑΙ ΤΟΤΕ να ανανεώνει το updated_at — '
            . 'αλλιώς η ανανέωση εξαρτάται από το αν η MySQL θεωρεί «ίδια τιμή» αλλαγή, που δεν είναι '
            . 'εγγυημένο σε κάθε έκδοση/ρύθμιση.'
        );
    }
}
