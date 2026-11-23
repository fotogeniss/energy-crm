<?php

/**
 * Ποιος χρήστης μπορεί να παίρνει υποψηφίους που έρχονται χωρίς login
 * (site σύγκρισης τιμών, AI τηλεφωνικό κέντρο).
 *
 * Ένας κανόνας για όλες τις πόρτες: ο χρήστης υπάρχει, δεν είναι
 * απενεργοποιημένος και μπορεί να φτιάξει σύμβαση. Αλλιώς οι υποψήφιοι θα
 * πήγαιναν σε λογαριασμό που δεν ανοίγει κανείς.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Access\Capability;
use EnergyCRM\Persistence\TeamRepository;

final class IntakeOwner
{
    private function __construct()
    {
    }

    /** Γιατί ο χρήστης δεν μπορεί να πάρει υποψηφίους, ή '' αν μπορεί. */
    public static function problem(int $uid): string
    {
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
