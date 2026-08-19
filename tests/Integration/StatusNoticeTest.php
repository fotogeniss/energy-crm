<?php

/**
 * Ποιος μαθαίνει ότι μια αίτηση χρειάζεται ενέργεια.
 *
 * Ως τις 19/08/2026 κανείς — ή πολύ αργά. Τα τρία κανάλια μετρήθηκαν ένα προς
 * ένα: το καμπανάκι κάλυπτε μόνο «ο πελάτης ανέβασε έγγραφο» και «ο πελάτης
 * υπέγραψε»· το email της εκκρεμότητας πήγαινε στο `$options['user_id']`, που
 * οι τρεις πόρτες της οθόνης γεμίζουν με τον **δράστη**, δηλαδή στο back office
 * που μόλις την έβαλε· και η ημερήσια περίληψη στέλνει μόνο για ό,τι έχει μείνει
 * ακίνητο πέντε μέρες, ενώ η ίδια η αλλαγή κατάστασης μόλις ανανέωσε το
 * `updated_at`.
 *
 * Αποτέλεσμα: ο συνεργάτης μάθαινε ότι κόλλησε η αίτησή του **στην καλύτερη
 * περίπτωση πέντε μέρες μετά**, και μόνο αν στο μεταξύ δεν την άγγιζε κανείς.
 *
 * Το αρχείο δοκιμάζει και το αντίθετο, που είναι εξίσου σημαντικό: οι
 * ενδιάμεσες καταστάσεις **δεν** χτυπούν καμπανάκι. Ειδοποίηση σε κάθε βήμα
 * μαθαίνει τον χρήστη να μην κοιτάζει, και τότε η μία που μετράει χάνεται μαζί
 * με τις υπόλοιπες.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\NotificationRepository;
use EnergyCRM\Persistence\TeamRepository;
use EnergyCRM\Services;

final class StatusNoticeTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private NotificationRepository $notifications;

    /** Ο κάτοχος της σύμβασης. */
    private int $owner;

    /** Ο προϊστάμενός του, που κάνει και τις αλλαγές. */
    private int $manager;

    /** @var list<string> */
    private array $recipients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts     = new ContractRepository();
        $this->notifications = new NotificationRepository();

        $this->manager = $this->makeCrmUser(Roles::PARTNER);
        $this->owner   = $this->makeCrmUser(Roles::SELLER);

        (new TeamRepository())->attach($this->owner, $this->manager);

        // Ο διαχειριστής είναι αυτός που ενεργεί — που είναι ακριβώς η
        // περίπτωση όπου το email πήγαινε σε λάθος άνθρωπο.
        wp_set_current_user($this->manager);

        $this->captureMail();
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_wp_mail');
        wp_set_current_user(0);

        parent::tearDown();
    }

    // --- το καμπανάκι ------------------------------------------------------

    /** Η εκκρεμότητα φτάνει στον κάτοχο, όχι σε αυτόν που την έβαλε. */
    public function testThePendingStatusReachesTheOwner(): void
    {
        $this->moveTo($this->contractOf($this->owner), 'pending');

        self::assertCount(1, $this->noticesFor($this->owner));
    }

    /** Και στον από πάνω του: η προμήθεια ανεβαίνει το ίδιο δέντρο. */
    public function testTheManagerIsToldToo(): void
    {
        $this->moveTo($this->contractOf($this->owner), 'pending');

        self::assertCount(1, $this->noticesFor($this->manager));
    }

    /** Η ακύρωση επίσης: τερματίζει τη δουλειά του. */
    public function testACancellationIsAnnounced(): void
    {
        $this->moveTo($this->contractOf($this->owner), 'cancelled');

        self::assertCount(1, $this->noticesFor($this->owner));
    }

    /**
     * Οι ενδιάμεσες καταστάσεις σιωπούν.
     *
     * Χωρίς αυτό, το «ειδοποίησε τον συνεργάτη» θα γινόταν «ειδοποίησέ τον για
     * τα πάντα», που είναι ο ίδιος τρόπος να μη μάθει τίποτα.
     */
    public function testAnOrdinaryStepDoesNotRingTheBell(): void
    {
        $this->moveTo($this->contractOf($this->owner), 'processing');

        self::assertSame([], $this->noticesFor($this->owner));
    }

    // --- το email ----------------------------------------------------------

    /** Ο παραλήπτης βγαίνει από τη σύμβαση, όχι από αυτόν που πάτησε το κουμπί. */
    public function testTheEmailGoesToTheOwnerAndNotTheActor(): void
    {
        $this->moveTo($this->contractOf($this->owner), 'pending');

        $ownerEmail   = (string) get_userdata($this->owner)->user_email;
        $managerEmail = (string) get_userdata($this->manager)->user_email;

        self::assertContains($ownerEmail, $this->recipients);
        self::assertNotContains($managerEmail, $this->recipients);
    }

    /** Και δεν στέλνεται email για ό,τι δεν είναι εκκρεμότητα. */
    public function testNoEmailForAnOrdinaryStep(): void
    {
        $this->moveTo($this->contractOf($this->owner), 'processing');

        self::assertSame([], $this->recipients);
    }

    // --- fixtures ----------------------------------------------------------

    /**
     * Κρατά τους παραλήπτες και δεν στέλνει τίποτα.
     *
     * Το `pre_wp_mail` κόβει την αποστολή επιστρέφοντας μη-null, οπότε η σουίτα
     * δεν αγγίζει ποτέ mailer — και ταυτόχρονα δίνει ακριβώς την πληροφορία που
     * κρίνεται εδώ: σε ποιον θα πήγαινε.
     */
    private function captureMail(): void
    {
        add_filter('pre_wp_mail', function ($short, array $atts) {
            foreach ((array) ($atts['to'] ?? []) as $address) {
                $this->recipients[] = (string) $address;
            }

            return true;
        }, 10, 2);
    }

    private function moveTo(int $contractId, string $status): void
    {
        self::assertTrue(
            Services::lifecycle()->moveTo($contractId, $status, ['user_id' => $this->manager]),
            'Η μετάβαση σε ' . $status . ' απορρίφθηκε.'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function noticesFor(int $userId): array
    {
        return array_values(array_filter(
            $this->notifications->recentFor($userId),
            static fn (array $row): bool => (string) $row['type'] === 'status'
        ));
    }

    private function contractOf(int $ownerId): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($ownerId)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }
}
