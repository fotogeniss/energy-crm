<?php

/**
 * (317) Θέση εντύπου με όρο τύπου πελάτη: `"when": "company"` ή `"not_company"`.
 *
 * Ένα έντυπο με ΞΕΧΩΡΙΣΤΑ τμήματα «Ιδιώτη/Ατομικής» και «Εταιρείας»
 * (volton_fa) θέλει το ίδιο ΑΦΜ στη μία γραμμή ή στην άλλη, ποτέ και στις
 * δύο. Χωρίς `when` η θέση ισχύει πάντα, όπως ως τώρα σε κάθε έντυπο.
 *
 * Ο τύπος διαβάζεται από το ίδιο `typos_pelati_etaireia` που τυπώνει το Χ
 * «ΕΤΑΙΡΕΙΑ», ώστε τμήμα και κουτάκι να μη διαφωνούν ποτέ. Ο ίδιος κανόνας
 * ζει και στο «Πάνω στο έντυπο» (ecrm-form.js, docWhenOk).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class PlacementCondition
{
    /**
     * @param array<string, mixed> $pos
     * @param array<string, mixed> $values
     */
    public static function applies(array $pos, array $values): bool
    {
        $when = (string) ($pos['when'] ?? '');

        if ($when === '') {
            return true;
        }

        $company = ($values['typos_pelati_etaireia'] ?? '') === 'X';

        if ($when === 'company') {
            return $company;
        }

        if ($when === 'not_company') {
            return ! $company;
        }

        // Άγνωστος όρος: καλύτερα κενό κουτί παρά τιμή σε λάθος τμήμα.
        return false;
    }
}
