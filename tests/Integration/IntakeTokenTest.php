<?php

/**
 * Ο σύνδεσμος «ο σύνδεσμός μου», δικός του κύκλος ζωής.
 *
 * Το `ECRM_Intake` αντιγράφει το σχήμα token του `ECRM_Tracking`
 * (`{id}-{hmac20}`, κλειδί ανακλητό στο user_meta) — αυτό το αρχείο αντιγράφει
 * επίτηδες τη δομή του `TrackingLinkRevocationTest`, ίδιοι κανόνες, άλλος
 * φορέας (πωλητής, όχι σύμβαση).
 *
 * Το `ECRM_Intake::verify()` προσθέτει και έναν δεύτερο φύλακα που το
 * tracking δεν έχει, `partner_active()` — ο σύνδεσμος ενός πωλητή που έφυγε ή
 * απενεργοποιήθηκε σταματά να δουλεύει χωρίς ανάκληση. Αυτός δοκιμάζεται
 * μέσω των δημόσιων REST endpoints στο `IntakeSubmitTest`, όχι εδώ: είναι
 * private συμπεριφορά, και η σωστή θέση να ελεγχθεί είναι η δημόσια πύλη
 * που τον χρησιμοποιεί, όχι reflection πάνω σε μέθοδο του υλοποίησης.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Intake;
use EnergyCRM\Access\Roles;

final class IntakeTokenTest extends IntegrationTestCase
{
    /** Ο σύνδεσμος δουλεύει, αλλιώς τα υπόλοιπα δεν σημαίνουν τίποτα. */
    public function testAFreshTokenResolvesToItsPartner(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        self::assertSame($partner, ECRM_Intake::verify(ECRM_Intake::token($partner)));
    }

    /** Και είναι σταθερός: δεύτερη κλήση δεν φτιάχνει δεύτερο κλειδί. */
    public function testTheTokenIsStableAcrossCalls(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        self::assertSame(ECRM_Intake::token($partner), ECRM_Intake::token($partner));
    }

    /** Η ανάκληση σκοτώνει τον παλιό σύνδεσμο. */
    public function testRevokingKillsTheOldLink(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);
        $token   = ECRM_Intake::token($partner);

        ECRM_Intake::revoke($partner);

        self::assertNull(ECRM_Intake::verify($token));
    }

    /** Και ο επόμενος που ζητά σύνδεσμο παίρνει καινούργιο, που δουλεύει. */
    public function testANewLinkIsIssuedAfterRevocation(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);
        $old     = ECRM_Intake::token($partner);

        ECRM_Intake::revoke($partner);

        $new = ECRM_Intake::token($partner);

        self::assertNotSame($old, $new);
        self::assertSame($partner, ECRM_Intake::verify($new));
    }

    /** Ο σύνδεσμος ενός πωλητή δεν πιάνει για τον άλλον. */
    public function testRevokingOneLinkLeavesTheOthersAlive(): void
    {
        $mine   = $this->makeCrmUser(Roles::SELLER);
        $theirs = $this->makeCrmUser(Roles::SELLER);
        $token  = ECRM_Intake::token($theirs);

        ECRM_Intake::revoke($mine);

        self::assertSame($theirs, ECRM_Intake::verify($token));
    }

    /** Παραποιημένο token δεν περνά. */
    public function testATamperedTokenIsRefused(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);
        $token   = ECRM_Intake::token($partner);
        $last    = substr($token, -1);

        $tampered = substr($token, 0, -1) . ($last === '0' ? '1' : '0');

        self::assertNotSame($token, $tampered);
        self::assertNull(ECRM_Intake::verify($tampered));
    }

    /**
     * Πωλητής χωρίς κλειδί δεν έχει έγκυρο σύνδεσμο, και η επαλήθευση δεν
     * παράγει κλειδί για να «βοηθήσει» — η διαδρομή είναι ανώνυμη.
     */
    public function testVerifyingNeverMintsAKey(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);
        $token   = ECRM_Intake::token($partner);

        ECRM_Intake::revoke($partner);
        ECRM_Intake::verify($token);

        self::assertSame('', get_user_meta($partner, ECRM_Intake::META_KEY, true));
    }
}
