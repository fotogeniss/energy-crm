<?php

/**
 * (352c) Πού επιτρέπεται να μπει ένας χρήστης «χωρίς ομάδα».
 *
 * Όποιος φτιάχτηκε από το WordPress (Users → Add User) δεν είχε προϊστάμενο,
 * άρα δεν φαινόταν σε καμία ομάδα του CRM και κανείς δεν μπορούσε να του δώσει
 * παρόχους. Ο διαχειριστής τον βάζει στην ομάδα του ή κάτω από έναν Συνεργάτη.
 *
 * Ο κανόνας είναι ένας: ο νέος προϊστάμενος δεν είναι ο ίδιος, και δεν είναι
 * κάποιος από κάτω του. Ένας «ορφανός» Συνεργάτης μπορεί να έχει δική του
 * ομάδα· αν μπει κάτω από δικό του πωλητή, το δέντρο κάνει κύκλο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Team;

final class Placement
{
    private function __construct()
    {
    }

    /**
     * @param list<int> $managerAncestors Η αλυσίδα προϊσταμένων του νέου προϊσταμένου, από κάτω προς τα πάνω.
     */
    public static function allowed(int $member, int $manager, array $managerAncestors): bool
    {
        if ($member <= 0 || $manager <= 0 || $member === $manager) {
            return false;
        }

        return ! in_array($member, $managerAncestors, true);
    }
}
