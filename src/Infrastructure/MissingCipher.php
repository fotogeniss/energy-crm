<?php

/**
 * Raised when encryption was switched on but this PHP cannot perform it.
 *
 * Deliberately fatal to the write that caused it. The alternative — storing
 * the value as it came — leaves a column full of readable tax numbers on a
 * site whose owner switched encryption on and believes it is protected. A
 * failed save is visible and recoverable; silent plaintext is neither.
 *
 * Seen in the wild on a stock Windows PHP build with no sodium extension.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use RuntimeException;

final class MissingCipher extends RuntimeException
{
    public static function sodiumUnavailable(): self
    {
        return new self(
            'Η κρυπτογράφηση προσωπικών δεδομένων είναι ενεργή (ECRM_ENCRYPT_PII) '
            . 'αλλά η επέκταση sodium λείπει από αυτή την εγκατάσταση PHP. '
            . 'Ενεργοποίησε την επέκταση ή απενεργοποίησε την κρυπτογράφηση — '
            . 'η αποθήκευση σταμάτησε ώστε να μην καταχωρηθούν δεδομένα σε καθαρό κείμενο.'
        );
    }
}
