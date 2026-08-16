<?php

/**
 * Raised when a write would land under a key that did not encrypt this data.
 *
 * Fatal to that write, on purpose, and a sibling of MissingCipher for the same
 * reason: the alternative is silent and permanent. Under a rotated salt every
 * encrypted field reads as empty, so a save that is allowed through writes
 * those blanks over ciphertext that is otherwise intact on disk and fully
 * recoverable by putting the old salt back.
 *
 * The difference from MissingCipher is worth stating, because it decides how
 * wide the refusal is. Missing sodium means nothing can be encrypted at all.
 * A rotated key means one specific thing is unsafe — overwriting the protected
 * columns — while the rest of the CRM is perfectly fine. So this stops writes
 * that touch ΑΦΜ, ΑΔΤ, addresses or the extras bag, and nothing else: notes,
 * statuses, documents and tasks keep working. A CRM that refuses everything is
 * an outage, and an outage gets worked around.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use RuntimeException;

final class RotatedKey extends RuntimeException
{
    public static function fingerprintMismatch(): self
    {
        return new self(
            'Το κλειδί κρυπτογράφησης δεν είναι αυτό που έγραψε τα δεδομένα — '
            . 'το SECURE_AUTH_SALT άλλαξε. Η αποθήκευση σταμάτησε ΕΠΙΤΗΔΕΣ: τα '
            . 'κρυπτογραφημένα πεδία διαβάζονται ως κενά και μια αποθήκευση θα τα '
            . 'έγραφε μόνιμα κενά. Τα δεδομένα είναι ακόμα ακέραια στον δίσκο. '
            . 'Επανάφερε το παλιό salt — ΜΗΝ τρέξεις το backfill. Δες docs/BACKUP.md.'
        );
    }
}
