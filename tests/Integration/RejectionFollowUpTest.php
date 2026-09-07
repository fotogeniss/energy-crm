<?php

/**
 * Η εργασία που αφήνει πίσω της μια ακύρωση «από εμάς».
 *
 * ## Το bug που διορθώνει αυτό το αρχείο (07/09/2026, μετά το commit 254)
 *
 * Το `RejectionFollowUp` άκουγε ως τις 07/09 το παλιό status `Rejected` --
 * φτανόταν ΜΟΝΟ όταν ο πάροχος είχε πράγματι απορρίψει την αίτηση. Το (254)
 * συγχώνευσε το `Rejected` και το γενικό `Cancelled` σε ένα `cancelled_by_us`
 * (`MigrateStatusVocabulary::MAP`), και η κλάση ξανασυνδέθηκε στο νέο status.
 *
 * Το πρόβλημα: το `cancelled_by_us` καλύπτει πλέον και τους **τρεις** λόγους
 * ακύρωσης «από εμάς» του `docs/STATUS-MODEL.md` §7 -- Δέσμευση συμβολαίου,
 * Πολιτική αποδοχής, Δεν ολοκληρώθηκαν δικαιολογητικά -- όχι μόνο την
 * απόρριψη παρόχου. Το κείμενο της εργασίας όμως έμεινε κυριολεκτικά
 * «Απορρίφθηκε από πάροχο -- ο πάροχος απέρριψε την αίτηση», που είναι ψέμα
 * για τους άλλους δύο λόγους. Καμία υπάρχουσα σουίτα δεν το έπιανε, γιατί δεν
 * υπήρχε κανένα test για αυτή την κλάση.
 *
 * Διορθώθηκε ώστε το κείμενο να είναι αληθές και για τους τρεις λόγους --
 * θα ξαναγίνει συγκεκριμένο μόλις υπάρξει η στήλη `cancellation_reason`
 * (commit 256) να ρωτηθεί. Αυτό το αρχείο κλειδώνει: (1) ότι η εργασία
 * δημιουργείται για ΚΑΘΕ ακύρωση από εμάς, ανεξαρτήτως λόγου· (2) ότι το
 * κείμενό της δεν ισχυρίζεται πλέον κάτι που δεν ξέρει· (3) ότι η ακύρωση
 * από τον πελάτη δεν δημιουργεί καμία τέτοια εργασία.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class RejectionFollowUpTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->owner      = $this->makePartner();
    }

    /** Κάθε ακύρωση «από εμάς» αφήνει εργασία -- ανεξαρτήτως λόγου, όχι μόνο απόρριψη παρόχου. */
    public function testACancellationByUsCreatesAFollowUpTask(): void
    {
        $contractId = $this->contractOf($this->owner);

        $this->moveTo($contractId, 'cancelled_by_us');

        $task = $this->taskFor($contractId);

        self::assertNotNull($task, 'Καμία εργασία δεν δημιουργήθηκε για ακύρωση από εμάς.');
        self::assertSame($this->owner, (int) $task['assigned_to']);
        self::assertSame('high', $task['priority']);
        self::assertSame('open', $task['status']);
    }

    /**
     * Το κείμενο δεν λέει πλέον «ο πάροχος απέρριψε» -- αυτό ήταν το ίδιο το
     * bug: μια ακύρωση λόγω δέσμευσης συμβολαίου ή ημιτελών δικαιολογητικών
     * δεν έχει καμία σχέση με τον πάροχο, και το παλιό κείμενο το ισχυριζόταν
     * ούτως ή άλλως.
     */
    public function testTheTaskTextDoesNotClaimAProviderRejection(): void
    {
        $contractId = $this->contractOf($this->owner);

        $this->moveTo($contractId, 'cancelled_by_us');

        $task = $this->taskFor($contractId);

        self::assertNotNull($task);
        self::assertStringNotContainsString('πάροχ', $task['title']);
        self::assertStringNotContainsString('πάροχ', $task['note']);
    }

    /** Η ακύρωση από τον πελάτη δεν δημιουργεί εργασία -- μόνο η δική μας. */
    public function testACustomerCancellationCreatesNoTask(): void
    {
        $contractId = $this->contractOf($this->owner);

        $this->moveTo($contractId, 'cancelled_by_customer');

        self::assertNull($this->taskFor($contractId));
    }

    /** Ένα συνηθισμένο βήμα δεν δημιουργεί εργασία. */
    public function testAnOrdinaryStepCreatesNoTask(): void
    {
        $contractId = $this->contractOf($this->owner);

        $this->moveTo($contractId, 'registration');

        self::assertNull($this->taskFor($contractId));
    }

    // --- fixtures ------------------------------------------------------

    private function moveTo(int $contractId, string $status): void
    {
        self::assertTrue(
            Services::lifecycle()->moveTo($contractId, $status),
            'Η μετάβαση σε ' . $status . ' απορρίφθηκε.'
        );
    }

    private function contractOf(int $ownerId): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'presale', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($ownerId)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function taskFor(int $contractId): ?array
    {
        global $wpdb;

        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE contract_id = %d ORDER BY id DESC LIMIT 1',
                Tables::name(Tables::TASKS),
                $contractId
            ),
            ARRAY_A
        );

        return $row ?: null;
    }
}
