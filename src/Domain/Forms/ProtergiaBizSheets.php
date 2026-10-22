<?php

/**
 * (322) Τα επαγγελματικά ρεύματος Protergia που απέκτησαν δικό τους φύλλο
 * με την αποστολή εντύπων της 22/10/2026.
 *
 * Ως τώρα τα 13 του `ProtergiaBizCatalogue` και τα 9 επαγγελματικά Picasso
 * (`ProtergiaPicassoPlans`) τύπωναν το γενικό `protergia_he_biz`, δηλαδή
 * την παλιά αίτηση του Picasso 2.0: ο πελάτης υπέγραφε άλλο τιμολόγιο από
 * αυτό που του πουλήθηκε. Τώρα κάθε πρόγραμμα τυπώνει τη δική του αίτηση
 * (με τον πίνακα τιμών της Protergia ήδη τυπωμένο) και τους δικούς του ΓΟΣ.
 *
 * Το πρόγραμμα αποφασίζει το φύλλο, όχι ο τύπος πελάτη -- κανόνας (283).
 * Το κλειδί του φύλλου είναι ο ίδιος ο κωδικός του προγράμματος, όπως στο
 * `ProtergiaBizPlans`.
 *
 * Οι χάρτες βγαίνουν από το `tools/protergia-biz-2026-maps.py`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class ProtergiaBizSheets
{
    /** Τα επαγγελματικά Picasso 2.0: ένα έντυπο ανά πακέτο, κοινοί ΓΟΣ. */
    public const PICASSO = [
        'protergia_pic_b_s1', 'protergia_pic_b_s2', 'protergia_pic_b_s3',
        'protergia_pic_b_m1', 'protergia_pic_b_m2', 'protergia_pic_b_m3',
        'protergia_pic_b_l1', 'protergia_pic_b_l2', 'protergia_pic_b_l3',
    ];

    private function __construct()
    {
    }

    /** Το φύλλο του προγράμματος, ή '' αν δεν έχει δικό του. */
    public static function templateKey(string $program): string
    {
        return in_array($program, self::programs(), true) ? $program : '';
    }

    /**
     * Όλα τα προγράμματα με δικό τους φύλλο: τα ρεύματος του καταλόγου και
     * τα επαγγελματικά Picasso.
     *
     * @return list<string>
     */
    public static function programs(): array
    {
        $power = array_keys(array_filter(
            ProtergiaBizCatalogue::all(),
            static fn (array $p): bool => $p['energy'] === 'power'
        ));

        return [...$power, ...self::PICASSO];
    }
}
