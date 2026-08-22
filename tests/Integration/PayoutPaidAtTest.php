<?php

/**
 * Η ώρα της πληρωμής — και ο πρώτος έλεγχος που άγγιξε ποτέ εκκαθάριση.
 *
 * Το §6γ (1) το γράφει από τις 18/08: οι χειριστές `create/pay/remove/pdf` είναι
 * `admin_post` που τελειώνουν σε `exit`, και **η σουίτα δεν τους φτάνει**. Έτσι
 * η μοναδική πράξη του προϊόντος που λέει «αυτά τα λεφτά πληρώθηκαν» δεν είχε
 * κανέναν έλεγχο. Το ερώτημα βγήκε στη `PayoutRepository` γι' αυτόν ακριβώς τον
 * λόγο — όχι για αρχιτεκτονική καθαρότητα.
 *
 * Δύο πράγματα φυλάει:
 *
 * 1. **Ότι το `paid_at` γράφεται με το ίδιο ρολόι με το `created_at` δίπλα του.**
 *    Το δεύτερο το βάζει η MySQL· ό,τι ζώνη κι αν διαλέξει η PHP για το πρώτο
 *    είναι **υπόθεση** για το τι κάνει η βάση. Γι' αυτό το γράφει πλέον η βάση.
 *    *Αυτός ο έλεγχος έπιασε ακριβώς μια τέτοια υπόθεση — δική μου. Δες τον.*
 *
 * 2. **Ότι το δεύτερο κλικ δεν ξαναγράφει την ώρα.** Η συνθήκη
 *    `status = 'pending'` είναι που το κάνει ακίνδυνο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\PayoutRepository;
use EnergyCRM\Persistence\Tables;

final class PayoutPaidAtTest extends IntegrationTestCase
{
    private PayoutRepository $payouts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payouts = new PayoutRepository();
    }

    private function pendingBatch(): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PAYOUTS), [
            'partner_user_id' => $this->makePartner(),
            'period'          => '2026-08',
            'cnt'             => 3,
            'amount'          => 120.00,
            'status'          => 'pending',
        ]);

        return (int) $wpdb->insert_id;
    }

    /** @return array<string, mixed> */
    private function batch(int $id): array
    {
        global $wpdb;

        return (array) $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', Tables::name(Tables::PAYOUTS), $id),
            ARRAY_A
        );
    }

    public function testAPendingBatchBecomesPaid(): void
    {
        $id = $this->pendingBatch();

        self::assertTrue($this->payouts->markPaid($id));
        self::assertSame('paid', $this->batch($id)['status']);
    }

    /**
     * Ο ΕΛΕΓΧΟΣ ΠΟΥ ΕΠΙΑΣΕ ΤΗ ΔΙΚΗ ΜΟΥ ΔΙΟΡΘΩΣΗ.
     *
     * Δεν συγκρίνεται με σταθερή ώρα — συγκρίνονται **οι δύο στήλες μεταξύ
     * τους**. Και ακριβώς γι' αυτό δούλεψε: γράφτηκε για να πιάσει το παλιό
     * `current_time('mysql', true)`, και αντ' αυτού κοκκίνισε με **10800
     * δευτερόλεπτα** στη δική μου αντικατάστασή του με ώρα site.
     *
     * Τρεις ώρες, δηλαδή η θερινή διαφορά Ελλάδας: σε αυτό το περιβάλλον η
     * MySQL τρέχει σε **UTC**, άρα το `created_at` είναι UTC, άρα η παλιά γραφή
     * ήταν συνεπής και η «διόρθωση» έφερε το σφάλμα. Είχα υποθέσει ότι το
     * `DEFAULT CURRENT_TIMESTAMP` δίνει ώρα site. **Δεν το μέτρησα.**
     *
     * Η λύση δεν ήταν να μαντέψω σωστά — ήταν να γράψει την ώρα **η βάση**
     * (`NOW()`), με το ίδιο ρολόι που γράφει και τη διπλανή στήλη. Έτσι αυτός ο
     * έλεγχος περνά **σε κάθε διακομιστή**, όποια ζώνη κι αν έχει η MySQL.
     */
    public function testThePaidTimeIsInTheSameZoneAsTheCreatedTimeBesideIt(): void
    {
        $id = $this->pendingBatch();

        $this->payouts->markPaid($id);

        $row     = $this->batch($id);
        $created = strtotime((string) $row['created_at']);
        $paid    = strtotime((string) $row['paid_at']);

        self::assertNotFalse($created);
        self::assertNotFalse($paid);

        // Δέκα λεπτά ανοχή για αργή σουίτα· η παλιά συμπεριφορά έδινε ώρες.
        self::assertLessThan(
            600,
            abs($paid - $created),
            'Το paid_at και το created_at πρέπει να διαβάζονται με τον ίδιο τρόπο — αλλιώς η εκκαθάριση '
            . 'εμφανίζεται πληρωμένη πριν δημιουργηθεί, και γύρω από τα μεσάνυχτα σε λάθος μέρα.'
        );
    }

    public function testASecondClickChangesNothing(): void
    {
        $id = $this->pendingBatch();

        self::assertTrue($this->payouts->markPaid($id));

        $first = $this->batch($id)['paid_at'];

        // Δεύτερο κλικ: δεν βρίσκει εκκρεμή γραμμή, άρα δεν ξαναγράφει ώρα.
        self::assertFalse($this->payouts->markPaid($id));
        self::assertSame($first, $this->batch($id)['paid_at']);
    }

    public function testAnAbsentBatchIsNotReportedAsPaid(): void
    {
        // Ο χειριστής απαντά «Σημειώθηκε ως πληρωμένη» με βάση αυτό. Ένα true
        // εδώ θα έλεγε στον διαχειριστή ότι πλήρωσε κάτι που δεν υπάρχει.
        self::assertFalse($this->payouts->markPaid(999999));
        self::assertFalse($this->payouts->markPaid(0));
    }
}
