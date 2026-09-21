<?php

/**
 * Ποιους παρόχους βλέπει ένας άνθρωπος -- η απάντηση, όχι ο τρόπος που βρέθηκε.
 *
 * ## Ο κανόνας
 *
 * Κανείς δεν βλέπει πάροχο που δεν βλέπει ο από πάνω του. Ο admin ορίζει τι
 * βλέπει ένας manager, ο manager δίνει στους δικούς του μόνο από όσα έχει ο
 * ίδιος, και ούτω καθεξής προς τα κάτω. Γι' αυτό η τελική απάντηση δεν είναι
 * «η λίστα του χρήστη» αλλά η ΤΟΜΗ όλων των λιστών στη γραμμή του, από την
 * κορυφή ως τον ίδιο: `along()`.
 *
 * Γιατί τομή την ώρα της ανάγνωσης και όχι απλώς «η λίστα που γράψαμε»: γιατί
 * ένας άνθρωπος αλλάζει προϊστάμενο με τρόπους που δεν περνούν από εδώ -- ο
 * προϊστάμενός του φεύγει (`DepartingUser`), τον βγάζουν από την ομάδα, κάποιος
 * αλλάζει το `ecrm_parent` από το wp-admin. Με τομή, σε όλες αυτές τις
 * περιπτώσεις ο χρήστης κόβεται αυτόματα στα όρια του νέου του manager, χωρίς
 * κανείς να θυμηθεί να το κάνει. Μια γραμμένη λίστα θα έμενε πίσω σιωπηλά.
 *
 * ## Το «χωρίς περιορισμό»
 *
 * Ο admin δεν έχει λίστα: βλέπει όλους, και όσους προστεθούν αύριο. Αυτό
 * ταξιδεύει ως έννοια (`unrestricted()`), όχι ως λίστα με όλα τα σημερινά id --
 * ίδιο μάθημα με το `ScopeResolver::visibleUserIds()`, όπου η λίστα-αντί-για-
 * έννοια ήταν ακριβώς η παγίδα.
 *
 * Καθαρή λογική, χωρίς WordPress (§1.12).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Providers\Domain;

final class ProviderAccess
{
    /**
     * @param list<int>|null $ids null = χωρίς περιορισμό.
     */
    private function __construct(private readonly ?array $ids)
    {
    }

    /** Ο admin: βλέπει κάθε πάροχο, και όσους προστεθούν μετά. */
    public static function unrestricted(): self
    {
        return new self(null);
    }

    /**
     * Μια συγκεκριμένη λίστα. Καθαρίζεται εδώ (θετικά, μοναδικά, ταξινομημένα)
     * ώστε η σύγκριση δύο απαντήσεων να μην εξαρτάται από τη σειρά γραφής.
     *
     * @param array<int|string> $ids
     */
    public static function only(array $ids): self
    {
        return new self(self::normalise($ids));
    }

    /**
     * Η τομή των λιστών κατά μήκος της γραμμής ενός ανθρώπου.
     *
     * Ο καλών περνά μία λίστα για κάθε άνθρωπο της γραμμής που ΕΧΕΙ περιορισμό
     * (οι admin παραλείπονται: δεν περιορίζουν κανέναν). Η σειρά δεν έχει
     * σημασία για το αποτέλεσμα. Καμία λίστα = κανείς δεν περιορίζει = χωρίς
     * περιορισμό -- συμβαίνει μόνο όταν όλη η γραμμή είναι admin.
     *
     * @param list<array<int|string>> $lists
     */
    public static function along(array $lists): self
    {
        $result = null;

        foreach ($lists as $list) {
            $clean  = self::normalise($list);
            $result = $result === null ? $clean : array_values(array_intersect($result, $clean));
        }

        return new self($result);
    }

    public function isUnrestricted(): bool
    {
        return $this->ids === null;
    }

    public function allows(int $providerId): bool
    {
        if ($providerId <= 0) {
            return false;
        }

        return $this->ids === null || in_array($providerId, $this->ids, true);
    }

    /**
     * Κρατά από τα δοσμένα id μόνο όσα επιτρέπονται, με τη σειρά τους.
     *
     * @param array<int|string> $ids
     *
     * @return list<int>
     */
    public function filter(array $ids): array
    {
        return array_values(array_filter(
            self::normalise($ids, false),
            fn (int $id): bool => $this->allows($id)
        ));
    }

    /**
     * Επιτρέπονται ΟΛΑ τα δοσμένα; Ενα που λείπει αρκεί για «όχι».
     *
     * @param array<int|string> $ids
     */
    public function covers(array $ids): bool
    {
        foreach (self::normalise($ids, false) as $id) {
            if (! $this->allows($id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Η λίστα, ή null για «χωρίς περιορισμό».
     *
     * @return list<int>|null
     */
    public function ids(): ?array
    {
        return $this->ids;
    }

    /**
     * @param array<int|string> $ids
     *
     * @return list<int>
     */
    private static function normalise(array $ids, bool $sort = true): array
    {
        $clean = [];

        foreach ($ids as $id) {
            $value = (int) $id;

            if ($value > 0 && ! in_array($value, $clean, true)) {
                $clean[] = $value;
            }
        }

        if ($sort) {
            sort($clean);
        }

        return $clean;
    }
}
