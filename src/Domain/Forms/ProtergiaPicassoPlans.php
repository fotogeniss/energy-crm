<?php

/**
 * Τα Picasso S / M / L της Protergia, οικιακά και επαγγελματικά (08/10/2026).
 *
 * Λίστα χειρόγραφη του ιδιοκτήτη: τρία μεγέθη (S, M, L), και για κάθε μέγεθος
 * τρεις τιμές. Επιλογή του ιδιοκτήτη: η κάθε τιμή είναι ΞΕΧΩΡΙΣΤΟ πρόγραμμα,
 * άρα 9 οικιακά και 9 επαγγελματικά. Η τιμή μπαίνει στο όνομα, γιατί αυτό
 * ξεχωρίζει τα τρία προγράμματα του ίδιου μεγέθους στο dropdown.
 *
 * Τι ακριβώς είναι η τιμή (πάγιο πακέτου, ποσό ανά διάστημα, κάτι άλλο) δεν
 * ξέρουμε ακόμα, γι' αυτό δεν γράφεται σε στήλη `fixed_charge`: ένα νούμερο
 * σε λάθος στήλη θα εμφανιζόταν σαν να ισχύει. Μένει στο όνομα, όπως το έδωσε
 * ο ιδιοκτήτης. Το ίδιο και το χρώμα (`price_type`): δεν ξέρουμε, μένει κενό.
 *
 * Έντυπο: δεν υπάρχει δικό τους φύλλο, άρα τυπώνεται το γενικό (οικιακό
 * `protergia_he`, επαγγελματικό `protergia_he_biz`), ως να έρθουν τα PDF.
 * Οι κωδικοί δεν είναι κλειδιά εντύπου, γι' αυτό πέφτουν στο γενικό.
 *
 * Τα Picasso 2.0 / Picasso 2.0 Student της 306 μένουν όπως είναι.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class ProtergiaPicassoPlans
{
    /**
     * Τιμές ανά κατηγορία και μέγεθος, με τη σειρά της λίστας.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const PRICES = [
        'home'     => [
            'S' => ['39,90', '49,90', '64,90'],
            'M' => ['82,90', '102,90', '134,90'],
            'L' => ['259,90', '359,90', '479,90'],
        ],
        'business' => [
            'S' => ['49,90', '64,90', '82,90'],
            'M' => ['99,90', '124,90', '154,90'],
            'L' => ['254,90', '344,90', '444,90'],
        ],
    ];

    private function __construct()
    {
    }

    /**
     * code => [label, category, sort].
     *
     * @return array<string, array{label: string, category: string, sort: int}>
     */
    public static function all(): array
    {
        $out = [];

        foreach (self::PRICES as $category => $sizes) {
            $sort = 20;

            foreach ($sizes as $size => $prices) {
                foreach ($prices as $i => $price) {
                    $code = sprintf(
                        'protergia_pic_%s_%s%d',
                        $category === 'home' ? 'h' : 'b',
                        strtolower($size),
                        $i + 1
                    );

                    $out[$code] = [
                        'label'    => 'Protergia Picasso ' . $size . ' ' . $price . ' €'
                            . ($category === 'business' ? ' — Επαγγελματικό' : ''),
                        'category' => $category,
                        'sort'     => $sort++,
                    ];
                }
            }
        }

        return $out;
    }
}
