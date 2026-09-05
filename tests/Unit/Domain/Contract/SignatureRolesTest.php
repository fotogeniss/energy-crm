<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\SignatureRoles as R;
use EnergyCRM\Domain\Forms\MobilePaperwork;
use PHPUnit\Framework\TestCase;

final class SignatureRolesTest extends TestCase
{
    /**
     * Κάθε άλλο έντυπο έχει έναν υπογράφοντα, και πρέπει να συνεχίσει να έχει.
     * Αν αυτό γύριζε δύο ρόλους, κάθε απλή αίτηση κινητής ή ρεύματος θα
     * περίμενε για πάντα μια δεύτερη υπογραφή που δεν πρόκειται να έρθει.
     */
    public function testAnOrdinaryApplicationHasOneSigner(): void
    {
        self::assertSame([R::MOBILE], R::requiredFor(MobilePaperwork::OFFER_NONE, true));
        self::assertSame([R::MOBILE], R::requiredFor(MobilePaperwork::OFFER_NONE, false));
        self::assertSame([R::MOBILE], R::requiredFor(MobilePaperwork::OFFER_FAMILY, false));
    }

    /**
     * Η Συνδυαστική δένει δύο γραμμές κινητής κάτω από το ίδιο ΑΦΜ, οπότε ο
     * υπογράφων μένει ένας -- ακόμα κι όταν οι γραμμές είναι δύο.
     */
    public function testTheFamilyOfferStillHasOneSigner(): void
    {
        self::assertSame([R::MOBILE], R::requiredFor(MobilePaperwork::OFFER_FAMILY, true));
    }

    /**
     * Στο COMBO με ένα πρόσωπο, η μία υπογραφή μπαίνει και στις δύο γραμμές:
     * είναι όντως δική του, σε δύο θέσεις που τον αφορούν και τις δύο. Δεύτερη
     * αίτηση υπογραφής θα ήταν ενόχληση χωρίς αντίκρισμα.
     */
    public function testComboWithOnePersonStillNeedsOneSignature(): void
    {
        self::assertSame([R::MOBILE], R::requiredFor(MobilePaperwork::OFFER_COMBO, true));
    }

    /** Δύο πρόσωπα, δύο υπογραφές -- ο λόγος που υπάρχει όλη αυτή η κλάση. */
    public function testComboWithTwoPeopleNeedsBoth(): void
    {
        self::assertSame(
            [R::MOBILE, R::ENERGY],
            R::requiredFor(MobilePaperwork::OFFER_COMBO, false)
        );
    }

    /**
     * Το `signed_at` σημαίνει πια «υπέγραψαν όλοι», όχι «υπέγραψε κάποιος».
     * Μισοϋπογεγραμμένο έντυπο δεν πρέπει να μπορεί να φύγει στον πάροχο.
     */
    public function testHalfSignedIsNotSigned(): void
    {
        $required = R::requiredFor(MobilePaperwork::OFFER_COMBO, false);

        self::assertFalse(R::isComplete($required, [R::MOBILE]));
        self::assertSame([R::ENERGY], R::missing($required, [R::MOBILE]));

        self::assertTrue(R::isComplete($required, [R::MOBILE, R::ENERGY]));
        self::assertSame([], R::missing($required, [R::MOBILE, R::ENERGY]));

        // Η σειρά που μαζεύτηκαν δεν αλλάζει το αποτέλεσμα.
        self::assertTrue(R::isComplete($required, [R::ENERGY, R::MOBILE]));
    }

    /**
     * Ο ρόλος `mobile` ΠΡΕΠΕΙ να κρατά το ιστορικό `signature`. Κάθε υπογραφή
     * που υπάρχει σήμερα στη βάση είναι αυτού του είδους· μια μετονομασία θα
     * τις έκανε όλες αόρατες χωρίς να σπάσει τίποτα ορατά.
     */
    public function testTheMobileRoleKeepsTheHistoricalKind(): void
    {
        self::assertSame('signature', R::kindFor(R::MOBILE));
        self::assertSame('signature_energy', R::kindFor(R::ENERGY));

        // `files.doc_kind` είναι VARCHAR(24): μακρύτερο kind θα το έκοβε η
        // MySQL σιωπηλά, και δύο ρόλοι θα κατέληγαν να μοιράζονται kind.
        foreach (R::kinds() as $kind) {
            self::assertLessThanOrEqual(24, strlen($kind), $kind);
        }

        self::assertSame('', R::kindFor('landline'));
        self::assertFalse(R::isRole('landline'));
        self::assertTrue(R::isRole(R::ENERGY));
    }

    /**
     * Σταδιο 4 (05/09/2026): ποιος απο τους δυο ρολους ταυτιζεται με τον
     * ΚΥΡΙΟ πελατη της συμβασης -- δηλαδη ποιανου το `contracts.email` /
     * `mobile` μπορει να χρησιμοποιηθει απευθειας.
     *
     * **Η περιπτωση που ξεχαστηκε και επιασε αυτη η δοκιμη:** με μια μονο
     * απαιτουμενη υπογραφη, ο μοναδικος ρολος ΕΙΝΑΙ ο κυριος -- ακομα κι οταν
     * η αιτηση ειναι ρευματος και ο ρολος λεγεται (ιστορικα) `mobile`. Χωρις
     * αυτο, καθε απλη αιτηση Volton θα θεωρουνταν «δευτερευων υπογραφων»: ο
     * συνδεσμος υπογραφης θα ζητουσε email απο κενη στηλη COMBO και δεν θα
     * εφευγε ποτε.
     */
    public function testTheOnlyRequiredSignerIsAlwaysThePrimaryOne(): void
    {
        foreach (['power', 'gas', 'mobile', ''] as $energyType) {
            self::assertSame(
                R::MOBILE,
                R::primaryRoleFor($energyType, [R::MOBILE]),
                "Μονος υπογραφων σε αιτηση '{$energyType}' δεν θεωρηθηκε κυριος πελατης."
            );
        }
    }

    /**
     * Με ΔΥΟ υπογραφοντες υπαρχει πραγματικη ερωτηση «ποιος απο τους δυο»,
     * και την κρινει η ΠΡΟΕΛΕΥΣΗ της αιτησης: αιτηση Orizon (`mobile`) εχει
     * κυριο πελατη τον πελατη κινητης, αιτηση Volton τον πελατη ενεργειας.
     */
    public function testWithTwoSignersTheApplicationsOriginDecidesWhoIsPrimary(): void
    {
        $both = [R::MOBILE, R::ENERGY];

        self::assertSame(R::MOBILE, R::primaryRoleFor('mobile', $both));
        self::assertSame(R::ENERGY, R::primaryRoleFor('power', $both));
        self::assertSame(R::ENERGY, R::primaryRoleFor('gas', $both));
    }
}
