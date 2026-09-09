<?php

/**
 * Το δίχτυ ασφαλείας για τον ζωντανό έλεγχο διπλοεγγραφής που δεν έτρεξε.
 *
 * Επίπεδο ΣΤ (docs/OFFLINE-MODEL.md §2ΣΤ) -- βλ. το πλήρες σκεπτικό στο
 * docblock του `DuplicateFollowUp` για ΓΙΑΤΙ τρέχει σε κάθε δημιουργία αντί
 * να προσπαθεί να ξεχωρίσει «ήρθε από την ουρά». Αυτό το αρχείο κλειδώνει:
 * (1) ότι μια δεύτερη σύμβαση με το ίδιο ΑΦΜ αφήνει εργασία στον ιδιοκτήτη
 * της νέας· (2) ότι η ίδια η καινούρια σύμβαση δεν ταιριάζει ποτέ με τον
 * εαυτό της (το σημείο που θα έσπαγε αν το φιλτράρισμα γινόταν με `code`
 * αντί για `contract_id` -- δύο fixtures χωρίς `assignCode()` έχουν και τα
 * δύο κενό code); (3) ότι ένα σκέτο πρόχειρο δεν ελέγχεται καθόλου, όπως
 * ακριβώς και ο ζωντανός έλεγχος· (4) ότι καμία υπάρχουσα διπλοεγγραφή δεν
 * σημαίνει καμία εργασία.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class DuplicateFollowUpTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private CustomerRepository $customers;

    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->customers = new CustomerRepository();
        $this->owner      = $this->makePartner();
    }

    /** Δεύτερη σύμβαση, ίδιο ΑΦΜ, άλλος πελάτης-εγγραφή: αφήνει εργασία στον ιδιοκτήτη της ΝΕΑΣ. */
    public function testASecondContractWithTheSameAfmLeavesATask(): void
    {
        $afm = '700000001';

        $olderCustomer = $this->customers->create($this->customerData($afm));
        $this->contractOf($olderCustomer, $this->owner);

        $newerCustomer = $this->customers->create($this->customerData($afm));
        $newContractId = $this->contractOf($newerCustomer, $this->owner);

        $this->created($newContractId, 'presale');

        $task = $this->taskFor($newContractId);

        self::assertNotNull($task, 'Καμία εργασία δεν δημιουργήθηκε για διπλό ΑΦΜ.');
        self::assertSame($this->owner, (int) $task['assigned_to']);
        self::assertSame('high', $task['priority']);
        self::assertSame('open', $task['status']);
        self::assertStringContainsString('διπλοεγγραφή', $task['title']);
    }

    /**
     * Η ΙΔΙΑ η καινούρια σύμβαση δεν πρέπει ΠΟΤΕ να ταιριάξει με τον εαυτό
     * της -- το `duplicatesOf()` τη βρίσκει κανονικά (μόλις γράφτηκε, ίδιο
     * ΑΦΜ), και το φιλτράρισμα πρέπει να την αποκλείσει με το δικό της
     * `contract_id`. Κανένα άλλο ΑΦΜ στη βάση, άρα καμία εργασία.
     */
    public function testTheNewContractNeverMatchesItself(): void
    {
        $customerId = $this->customers->create($this->customerData('700000002'));
        $contractId = $this->contractOf($customerId, $this->owner);

        $this->created($contractId, 'presale');

        self::assertNull($this->taskFor($contractId), 'Η σύμβαση ταίριαξε με τον εαυτό της.');
    }

    /** Ο ζωντανός έλεγχος δεν τρέχει σε πρόχειρο -- ούτε το δίχτυ ασφαλείας. */
    public function testADraftIsNeverChecked(): void
    {
        $afm = '700000003';

        $olderCustomer = $this->customers->create($this->customerData($afm));
        $this->contractOf($olderCustomer, $this->owner);

        $newerCustomer = $this->customers->create($this->customerData($afm));
        $draftId = $this->contractOf($newerCustomer, $this->owner);

        $this->created($draftId, 'draft');

        self::assertNull($this->taskFor($draftId));
    }

    /** Κανένα υπάρχον ΑΦΜ να ταιριάξει -- καμία εργασία. */
    public function testNoExistingMatchLeavesNoTask(): void
    {
        $customerId = $this->customers->create($this->customerData('700000004'));
        $contractId = $this->contractOf($customerId, $this->owner);

        $this->created($contractId, 'presale');

        self::assertNull($this->taskFor($contractId));
    }

    // --- fixtures ------------------------------------------------------

    private function created(int $contractId, string $status): void
    {
        Services::lifecycle()->logCreation($contractId, $this->owner, $status);
    }

    private function contractOf(int $customerId, int $ownerId): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'presale', 'customer_id' => $customerId, 'energy_type' => 'power'],
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
