<?php

/**
 * Ο σύνδεσμος παρακολούθησης ανακαλείται ανά σύμβαση.
 *
 * Ως τις 19/08/2026 δεν ανακαλούνταν καθόλου. Το token ήταν
 * `{id}-{hmac(id, wp_salt('auth'))}` — καθαρό, 80 bits, μη απαριθμήσιμο, και
 * **αιώνιο**: ο ίδιος σύνδεσμος για την ίδια σύμβαση για πάντα. Ο μόνος τρόπος
 * να πάψει να ισχύει ήταν να αλλάξει το salt, που ακυρώνει **όλους** τους
 * συνδέσμους και πετάει έξω κάθε συνδεδεμένο χρήστη του WordPress. Έλεγχος
 * λειτουργίας, εύρημα 6.
 *
 * Δύο πράγματα δοκιμάζονται μαζί, γιατί το ένα χωρίς το άλλο δεν λέει τίποτα:
 * ότι η ανάκληση **πιάνει** τον παλιό σύνδεσμο, και ότι **δεν πιάνει** τους
 * συνδέσμους των υπόλοιπων συμβάσεων — που ήταν όλο το πρόβλημα με το salt.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Tracking;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\SignatureRoles;
use EnergyCRM\Persistence\ContractRepository;

final class TrackingLinkRevocationTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private int $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->partner   = $this->makePartner();
    }

    /** Ο σύνδεσμος δουλεύει, αλλιώς τα υπόλοιπα δεν σημαίνουν τίποτα. */
    public function testAFreshTokenResolvesToItsContract(): void
    {
        $contractId = $this->contract();

        self::assertSame(
            ['id' => $contractId, 'role' => SignatureRoles::MOBILE],
            ECRM_Tracking::verify(ECRM_Tracking::token($contractId))
        );
    }

    /** Και είναι σταθερός: δεύτερη κλήση δεν φτιάχνει δεύτερο κλειδί. */
    public function testTheTokenIsStableAcrossCalls(): void
    {
        $contractId = $this->contract();

        self::assertSame(ECRM_Tracking::token($contractId), ECRM_Tracking::token($contractId));
    }

    /** Η ανάκληση σκοτώνει τον παλιό σύνδεσμο. */
    public function testRevokingKillsTheOldLink(): void
    {
        $contractId = $this->contract();
        $token      = ECRM_Tracking::token($contractId);

        ECRM_Tracking::revoke($contractId);

        self::assertNull(ECRM_Tracking::verify($token));
    }

    /** Και ο επόμενος που ζητά σύνδεσμο παίρνει καινούργιο, που δουλεύει. */
    public function testANewLinkIsIssuedAfterRevocation(): void
    {
        $contractId = $this->contract();
        $old        = ECRM_Tracking::token($contractId);

        ECRM_Tracking::revoke($contractId);

        $new = ECRM_Tracking::token($contractId);

        self::assertNotSame($old, $new);
        self::assertSame(['id' => $contractId, 'role' => SignatureRoles::MOBILE], ECRM_Tracking::verify($new));
    }

    /**
     * Οι υπόλοιπες συμβάσεις δεν αγγίζονται.
     *
     * Αυτό ακριβώς δεν μπορούσε να γίνει πριν: η μόνη ανάκληση ήταν η αλλαγή
     * του salt, που θα σκότωνε και αυτόν εδώ τον σύνδεσμο.
     */
    public function testRevokingOneLinkLeavesTheOthersAlive(): void
    {
        $mine   = $this->contract();
        $theirs = $this->contract();
        $token  = ECRM_Tracking::token($theirs);

        ECRM_Tracking::revoke($mine);

        self::assertSame(['id' => $theirs, 'role' => SignatureRoles::MOBILE], ECRM_Tracking::verify($token));
    }

    /**
     * Σύμβαση χωρίς κλειδί δεν έχει έγκυρο σύνδεσμο.
     *
     * Και η επαλήθευση δεν παράγει κλειδί για να «βοηθήσει»: η διαδρομή είναι
     * ανώνυμη και δημόσια, οπότε μια αίτηση σε τυχαίο id θα έδινε σε
     * οποιονδήποτε τρόπο να γράφει στη βάση.
     */
    public function testVerifyingNeverMintsAKey(): void
    {
        $contractId = $this->contract();
        $token      = ECRM_Tracking::token($contractId);

        ECRM_Tracking::revoke($contractId);
        ECRM_Tracking::verify($token);

        self::assertNull($this->storedRow('contracts', $contractId)['track_key']);
    }

    /**
     * Παραποιημένο token δεν περνά.
     *
     * Ο τελευταίος χαρακτήρας αλλάζει σε κάτι **άλλο** από ό,τι ήταν. Η προφανής
     * γραφή — βάλε '0' στο τέλος — θα άφηνε το token αναλλοίωτο μία στις
     * δεκαέξι φορές, και το test θα κοκκίνιζε τυχαία μία φορά στον μήνα χωρίς
     * να έχει αλλάξει τίποτα.
     */
    public function testATamperedTokenIsRefused(): void
    {
        $contractId = $this->contract();
        $token      = ECRM_Tracking::token($contractId);
        $last       = substr($token, -1);

        $tampered = substr($token, 0, -1) . ($last === '0' ? '1' : '0');

        self::assertNotSame($token, $tampered);
        self::assertNull(ECRM_Tracking::verify($tampered));
    }

    private function contract(): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }
}
