<?php

/**
 * (325) Τα οικιακά φύλλα ρεύματος Protergia (αποστολή 29/10/2026).
 *
 * Ως τώρα τα οικιακά του καταλόγου (07/10) χωρίς δικό τους φύλλο τύπωναν το
 * γενικό `protergia_he` -- που είναι η αίτηση του Picasso 2.0 Small, με
 * τυπωμένα «39,90 €» και «0,174 €/kWh». Ο πελάτης του Value Balance 12M
 * υπέγραφε αίτηση Picasso. Η Protergia έστειλε αίτηση και ΓΟΣ για κάθε
 * πρόγραμμα, εκτός από το Value Bright 2.0.
 *
 * Το πρόγραμμα αποφασίζει το φύλλο, όπως στα επαγγελματικά (283).
 *
 * Picasso: η Protergia (29/10) -- «η αίτηση για το φοιτητικό πρόγραμμα
 * picasso student είναι ίδια χωρίς διαφοροποίηση με την αίτηση για το
 * κανονικό picasso 39,90€». Το «Picasso 2.0» της λίστας 07/10, το Student και
 * το Picasso S 39,90 τυπώνουν άρα το ίδιο φύλλο. Τα υπόλοιπα 8 οικιακά Picasso
 * (S 49,90 / 64,90, M, L) απέκτησαν δικό τους έντυπο στις 03/11 (348).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class ProtergiaHomeSheets
{
    /** Πρόγραμμα => φύλλο. */
    private const SHEETS = [
        'protergia_value_sure4_12m'  => 'protergia_oik_sure4_12m',
        'protergia_value_balance_12m' => 'protergia_oik_balance_12m',
        'protergia_value_standard2'  => 'protergia_oik_standard2',
        'protergia_value_special'    => 'protergia_oik_special',
        'protergia_student_plan'     => 'protergia_oik_student_plan',
        'protergia_dynamic_one'      => 'protergia_oik_dynamic_one',
        'protergia_picasso2'         => 'protergia_oik_picasso_s1',
        'protergia_picasso2_student' => 'protergia_oik_picasso_s1',
        'protergia_pic_h_s1'         => 'protergia_oik_picasso_s1',
        // (348) Protergia 03/11: έντυπο για κάθε οικιακό πακέτο· ίδια αίτηση, αλλάζουν
        // μόνο η τιμή και το όνομα του πακέτου στη σελίδα 2. Οι ΓΟΣ κοινοί (`tail`).
        'protergia_pic_h_s2'         => 'protergia_oik_picasso_s2',
        'protergia_pic_h_s3'         => 'protergia_oik_picasso_s3',
        'protergia_pic_h_m1'         => 'protergia_oik_picasso_m1',
        'protergia_pic_h_m2'         => 'protergia_oik_picasso_m2',
        'protergia_pic_h_m3'         => 'protergia_oik_picasso_m3',
        'protergia_pic_h_l1'         => 'protergia_oik_picasso_l1',
        'protergia_pic_h_l2'         => 'protergia_oik_picasso_l2',
        'protergia_pic_h_l3'         => 'protergia_oik_picasso_l3',
    ];

    private function __construct()
    {
    }

    /** Το φύλλο του προγράμματος, ή '' αν δεν έχει δικό του. */
    public static function templateKey(string $program): string
    {
        return self::SHEETS[$program] ?? '';
    }

    /** @return list<string> */
    public static function programs(): array
    {
        return array_keys(self::SHEETS);
    }

    /** @return list<string> */
    public static function templates(): array
    {
        return array_values(array_unique(self::SHEETS));
    }
}
