<?php

/**
 * `ECRM_Files::requesterMayView()` -- ποιος επιτρέπεται ΤΩΡΑ να δει ένα αρχείο.
 *
 * Εύρημα #8 του ελέγχου ασφαλείας/UI-UX/ροής-λογικής (26/08/2026). Το παλιό
 * `ECRM_Files::serve()` έλεγχε ΜΟΝΟ το signed token: όποιος το είχε, μέσα
 * στη 1ωρη ισχύ του, έπαιρνε το αρχείο -- συνδεδεμένος ή όχι, ίδιος χρήστης
 * με αυτόν που εξέδωσε το link ή όχι. Ο ιδιοκτήτης επιβεβαίωσε (AskUserQuestion,
 * 26/08) ότι δεν υπάρχει σενάριο emailed/SMS link σε μη-συνδεδεμένο παραλήπτη,
 * οπότε η `requesterMayView()` απαιτεί τώρα ΚΑΙ ο τρέχων αιτών να είναι
 * συνδεδεμένος ΚΑΙ μέσα στο ορατό scope -- και ΚΑΙ ο χρήστης για τον οποίο
 * εκδόθηκε αρχικά το token (bound_uid) να παραμένει ακόμα μέσα στο scope,
 * γιατί ένα παλιό token δεν πρέπει να συνεχίζει να δουλεύει αν ο κάτοχός του
 * έχασε στο μεταξύ την ορατότητα (π.χ. αφαιρέθηκε από την ομάδα, η σύμβαση
 * ανατέθηκε αλλού).
 *
 * Ξεχωριστό αρχείο (όχι μέσα στο `UnprotectedDocumentsTest` ή κάποιο άλλο) --
 * η `requesterMayView()` είναι καθαρή λογική εξουσιοδότησης, χωρίς καμία
 * σχέση με το πώς αποθηκεύεται/μετακινείται το ίδιο το αρχείο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Files;
use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\NetworkRepository;

final class FileServeSessionBindingTest extends IntegrationTestCase
{
    public function testANonLoggedInRequesterMayNotView(): void
    {
        $owner = $this->makeCrmUser(Roles::SELLER);

        // bound_uid == owner, δηλαδή το token είναι έγκυρο από μόνο του --
        // μόνο ο requesting_uid <= 0 (κανείς συνδεδεμένος) πρέπει να αρκεί
        // για άρνηση.
        self::assertFalse(ECRM_Files::requesterMayView($owner, 0, $owner));
    }

    public function testARequesterOutsideTheContractsScopeMayNotView(): void
    {
        $owner    = $this->makeCrmUser(Roles::SELLER);
        $stranger = $this->makeCrmUser(Roles::SELLER);

        // Ο stranger δεν έχει καμία σχέση με τον owner -- ούτε ίδιος, ούτε
        // manager του.
        self::assertFalse(ECRM_Files::requesterMayView($owner, $stranger, $owner));
    }

    /**
     * Το ίδιο το bug που έκλεινε αυτό το εύρημα: το token εκδόθηκε όσο ο
     * κάτοχός του (bound_uid) έβλεπε ακόμα τη σύμβαση, αλλά στο μεταξύ έχασε
     * την ορατότητα (π.χ. αφαιρέθηκε από την ομάδα). Ο έλεγχος ΜΟΝΟ του
     * requesting_uid δεν θα το έπιανε αν bound_uid == requesting_uid -- γι'
     * αυτό η `requesterMayView()` ξαναελέγχει και το bound_uid.
     *
     * Το `Services::reset()` ανάμεσα στις δύο φάσεις προσομοιώνει ό,τι θα
     * ήταν φυσικά αλήθεια σε πραγματική χρήση: το «πριν» και το «μετά» είναι
     * δύο ΞΕΧΩΡΙΣΤΑ HTTP requests, καθένα με το δικό του φρέσκο
     * `Services::scopeResolver()`. Χωρίς το reset, ο μεμονωμένος
     * `WordPressScopeResolver::$memo` του PHPUnit process -- που ΔΕΝ υπάρχει
     * ποτέ στην πραγματικότητα ανάμεσα σε δύο requests -- κρατάει την παλιά
     * απάντηση για τον manager και το test θα έβλεπε ένα bug που δεν
     * υπάρχει στην παραγωγή, αντί για αυτό που πράγματι υπάρχει.
     */
    public function testATokenWhoseOriginalHolderLostVisibilityMayNoLongerBeUsed(): void
    {
        $owner   = $this->makeCrmUser(Roles::SELLER);
        $manager = $this->makeCrmUser(Roles::PARTNER);

        update_user_meta($owner, NetworkRepository::PARENT_META, $manager);
        (new NetworkRepository())->rebuild($owner);

        // Στιγμή έκδοσης του token: ο manager βλέπει τον owner.
        self::assertTrue(ECRM_Files::requesterMayView($manager, $manager, $owner));

        // Η σύμβαση αλλάζει χέρια -- ο manager αποσυνδέεται από τον owner.
        delete_user_meta($owner, NetworkRepository::PARENT_META);
        (new NetworkRepository())->rebuild($owner);

        // Νέο "request": φρέσκος scope resolver, χωρίς το memo του προηγούμενου.
        \EnergyCRM\Services::reset();

        // Το ίδιο ζεύγος bound_uid/requesting_uid, τώρα εκτός scope: το παλιό
        // token δεν πρέπει να δουλεύει πια, ακόμα κι αν ο manager είναι
        // ακόμα ο ίδιος που ζητά το αρχείο.
        self::assertFalse(ECRM_Files::requesterMayView($manager, $manager, $owner));
    }

    public function testARequesterInScopeButBoundToADifferentUnrelatedUserMayNotView(): void
    {
        $owner    = $this->makeCrmUser(Roles::SELLER);
        $stranger = $this->makeCrmUser(Roles::SELLER);

        // Ο requesting_uid βλέπει τον εαυτό του (owner == requesting), αλλά
        // το token εκδόθηκε για έναν stranger που δεν έβλεπε ποτέ τον owner --
        // δεν πρέπει να μετράει.
        self::assertFalse(ECRM_Files::requesterMayView($stranger, $owner, $owner));
    }

    public function testAManagerStillInScopeMayViewTheirDownlinesFile(): void
    {
        $owner   = $this->makeCrmUser(Roles::SELLER);
        $manager = $this->makeCrmUser(Roles::PARTNER);

        update_user_meta($owner, NetworkRepository::PARENT_META, $manager);
        (new NetworkRepository())->rebuild($owner);

        self::assertTrue(ECRM_Files::requesterMayView($manager, $manager, $owner));
    }

    public function testTheOwnerThemselvesMayAlwaysViewTheirOwnFile(): void
    {
        $owner = $this->makeCrmUser(Roles::SELLER);

        self::assertTrue(ECRM_Files::requesterMayView($owner, $owner, $owner));
    }
}
