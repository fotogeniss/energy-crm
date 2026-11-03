<?php

/**
 * Οι ρυθμίσεις της σύνδεσης με το site: το κοινό μυστικό και ποιος χρήστης
 * παίρνει τις αιτήσεις.
 *
 * Το μυστικό ζει στο SecretStore (κρυπτογραφημένο στη βάση, ή σταθερά
 * `ECRM_SITE_INTAKE_SECRET` στο wp-config αν ο admin προτιμά να μην είναι στη
 * βάση). Χωρίς μυστικό η πόρτα είναι κλειστή: κανένα αίτημα δεν περνά.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\SiteIntake\Infrastructure;

use EnergyCRM\Access\Capability;
use EnergyCRM\Infrastructure\SecretStore;
use EnergyCRM\Persistence\TeamRepository;

final class SiteIntakeConfig
{
    private const SECRET = 'site_intake_secret';

    private const OWNER = 'ecrm_site_intake_owner';

    public function __construct(private readonly SecretStore $secrets)
    {
    }

    public function secret(): string
    {
        return $this->secrets->get(self::SECRET);
    }

    public function hasSecret(): bool
    {
        return $this->secret() !== '';
    }

    public function maskedSecret(): string
    {
        return $this->secrets->mask(self::SECRET);
    }

    public function isPinned(): bool
    {
        return $this->secrets->isPinned(self::SECRET);
    }

    /** Νέο μυστικό. Το παλιό παύει αμέσως· το site θέλει το καινούργιο. */
    public function rotate(): string
    {
        $secret = bin2hex(random_bytes(32));
        $this->secrets->put(self::SECRET, $secret);

        return $secret;
    }

    public function ownerId(): int
    {
        return (int) get_option(self::OWNER, 0);
    }

    public function setOwner(int $userId): void
    {
        update_option(self::OWNER, max(0, $userId), false);
    }

    /**
     * Ο χρήστης υπάρχει, δεν έχει απενεργοποιηθεί και μπορεί να φτιάξει
     * σύμβαση. Ίδιος κανόνας με τον «σύνδεσμό μου»: αν φύγει ο χρήστης, οι
     * αιτήσεις δεν πρέπει να πηγαίνουν σε λογαριασμό που δεν ανοίγει κανείς.
     */
    public function ownerIsActive(): bool
    {
        return $this->ownerProblem() === '';
    }

    /** Γιατί ο χρήστης δεν μπορεί να πάρει αιτήσεις, ή '' αν μπορεί. Για τη σελίδα ρυθμίσεων. */
    public function ownerProblem(): string
    {
        $uid = $this->ownerId();

        if ($uid <= 0) {
            return 'Δεν έχει οριστεί χρήστης.';
        }
        if (get_userdata($uid) === false) {
            return 'Ο χρήστης δεν υπάρχει πια.';
        }
        if (get_user_meta($uid, TeamRepository::DISABLED_META, true)) {
            return 'Ο χρήστης είναι απενεργοποιημένος στην Ομάδα.';
        }
        if (! user_can($uid, Capability::CREATE_CONTRACT)) {
            return 'Ο χρήστης δεν έχει δικαίωμα να φτιάχνει σύμβαση.';
        }

        return '';
    }
}
