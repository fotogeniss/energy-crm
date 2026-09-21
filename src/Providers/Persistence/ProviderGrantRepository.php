<?php

/**
 * Ποιους παρόχους έχει ΓΡΑΜΜΕΝΟΥΣ ένας χρήστης.
 *
 * «Γραμμένους», όχι «βλέπει»: τι βλέπει τελικά αποφασίζει το
 * `Access\ProviderVisibility`, με τομή κατά μήκος του δικτύου (δες
 * `Domain\ProviderAccess`). Εδώ ζει μόνο η λίστα του καθενός.
 *
 * ## Γιατί user meta και όχι δικός του πίνακας
 *
 * Ιδια θέση με την υπόλοιπη ιεραρχία: το `ecrm_parent`, το `ecrm_path` και το
 * `ecrm_disabled` ζουν ήδη εκεί. Μια λίστα μερικών id ανά χρήστη δεν δικαιολογεί
 * πίνακα, foreign keys, έλεγχο υγείας και εγγραφή στο `Tables::all()` -- και
 * όταν σβηστεί ο χρήστης, το WordPress σβήνει και τη λίστα μαζί του χωρίς
 * κανένα βήμα από εμάς. Αν έρθει ποτέ ερώτημα «ποιοι έχουν τον πάροχο Χ σε όλη
 * την εταιρεία», εκείνη είναι η μέρα για πίνακα.
 *
 * Μορφή: id χωρισμένα με κόμμα ("3,7,12"). Κενό string = γραμμένη, άδεια λίστα
 * -- ΔΙΑΦΟΡΕΤΙΚΟ από το «δεν υπάρχει καθόλου», που το `has()` ξεχωρίζει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Providers\Persistence;

final class ProviderGrantRepository
{
    public const META = 'ecrm_providers';

    /** Εχει γραφτεί ποτέ λίστα γι' αυτόν (έστω και άδεια); */
    public function has(int $userId): bool
    {
        return $userId > 0 && metadata_exists('user', $userId, self::META);
    }

    /**
     * Η γραμμένη λίστα. Χωρίς γραμμένη λίστα: άδεια -- κανένας πάροχος. Ενας
     * χρήστης που φτιάχτηκε έξω από τη ροή μας (π.χ. από το wp-admin) δεν
     * πουλάει τίποτα μέχρι να του δοθεί ρητά. Το ασφαλές, όχι το βολικό.
     *
     * @return list<int>
     */
    public function grantsOf(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return self::parse((string) get_user_meta($userId, self::META, true));
    }

    /**
     * @param array<int|string> $providerIds
     */
    public function set(int $userId, array $providerIds): void
    {
        if ($userId <= 0) {
            return;
        }

        update_user_meta($userId, self::META, self::format($providerIds));
    }

    /**
     * Βγάζει τους δοσμένους παρόχους από τη λίστα κάθε δοσμένου χρήστη.
     * Οσοι δεν τους είχαν μένουν ανέγγιχτοι (καμία εγγραφή).
     *
     * @param list<int> $userIds
     * @param list<int> $providerIds
     *
     * @return int Σε πόσους άλλαξε κάτι.
     */
    public function strip(array $userIds, array $providerIds): int
    {
        if ($providerIds === []) {
            return 0;
        }

        $changed = 0;

        foreach ($userIds as $userId) {
            if (! $this->has($userId)) {
                continue;
            }

            $current = $this->grantsOf($userId);
            $kept    = array_values(array_diff($current, $providerIds));

            if (count($kept) === count($current)) {
                continue;
            }

            $this->set($userId, $kept);
            $changed++;
        }

        return $changed;
    }

    /**
     * @return list<int>
     */
    public static function parse(string $stored): array
    {
        $ids = [];

        foreach (explode(',', $stored) as $part) {
            $id = (int) trim($part);

            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * @param array<int|string> $providerIds
     */
    public static function format(array $providerIds): string
    {
        return implode(',', self::parse(implode(',', array_map('strval', $providerIds))));
    }
}
