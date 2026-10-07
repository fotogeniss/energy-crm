<?php

/**
 * Τα προγράμματα της Protergia όπως τα έδωσε ο ιδιοκτήτης, 07/10/2026.
 *
 * Λίστα από το υλικό της Protergia: 10 οικιακά ρεύματος και 4 φυσικού αερίου.
 * Από αυτά, μόνο το Value Lite 2.0 υπήρχε ήδη (με δικό του έντυπο,
 * `ProtergiaHomePlans::LITE_2`). Τα υπόλοιπα 13 είναι νέα.
 *
 * ## Έντυπο
 *
 * Τα νέα δεν έχουν ακόμα δικό τους φύλλο. Οι κωδικοί τους δεν υπάρχουν στο
 * `ProtergiaHomePlans`/`ProtergiaGasPlans`, οπότε το `ECRM_FormFill::template_key()`
 * τα στέλνει στο γενικό έντυπο (`protergia_he` για ρεύμα, `protergia_fa` για
 * αέριο). Επιλογή του ιδιοκτήτη: γενικό έντυπο ως να έρθουν τα PDF.
 *
 * ## Power+Gas
 *
 * Το συνδυαστικό μπαίνει δύο φορές, μία στο ρεύμα και μία στο αέριο: ο
 * συνεργάτης κάνει δύο αιτήσεις με το ίδιο πρόγραμμα (επιλογή ιδιοκτήτη).
 *
 * ## Χρώμα (price_type)
 *
 * Μόνο όπου το λέει το ίδιο το όνομα ή το προηγούμενο πρόγραμμα: Sure =
 * σταθερό, Dynamic = δυναμικό, Special = ειδικό, Κυμαινόμενο = variable.
 * Όπου δεν ξέρουμε, μένει κενό και το πρόγραμμα φαίνεται σε κάθε χρώμα. Ο
 * διαχειριστής το διορθώνει από τη σελίδα παρόχων.
 *
 * ## Τα παλιά
 *
 * Όσα δεν είναι στη λίστα απενεργοποιούνται (`RETIRED`). Δεν σβήνονται: οι
 * παλιές αιτήσεις τα κρατάνε και τυπώνονται στο φύλλο τους όπως πριν.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class ProtergiaCatalogue
{
    /**
     * Νέα προγράμματα, με τη σειρά της λίστας.
     *
     * @var array<string, array{label: string, energy: string, priceType: string, sort: int}>
     */
    private const ADDED = [
        'protergia_picasso2' => [
            'label'     => 'Protergia Picasso 2.0',
            'energy'    => 'power',
            'priceType' => '',
            'sort'      => 1,
        ],
        'protergia_picasso2_student' => [
            'label'     => 'Protergia Picasso 2.0 Student',
            'energy'    => 'power',
            'priceType' => '',
            'sort'      => 2,
        ],
        'protergia_dynamic_one' => [
            'label'     => 'Protergia Dynamic One',
            'energy'    => 'power',
            'priceType' => 'dynamic',
            'sort'      => 3,
        ],
        'protergia_value_special' => [
            'label'     => 'Protergia Value Special',
            'energy'    => 'power',
            'priceType' => 'special',
            'sort'      => 4,
        ],
        'protergia_value_sure4_12m' => [
            'label'     => 'Protergia Value Sure 4.0 12M',
            'energy'    => 'power',
            'priceType' => 'fixed',
            'sort'      => 5,
        ],
        'protergia_value_balance_12m' => [
            'label'     => 'Protergia Value Balance 12M',
            'energy'    => 'power',
            'priceType' => '',
            'sort'      => 6,
        ],
        'protergia_value_bright2' => [
            'label'     => 'Protergia Value Bright 2.0',
            'energy'    => 'power',
            'priceType' => 'variable',
            'sort'      => 7,
        ],
        'protergia_student_plan' => [
            'label'     => 'Protergia Student Plan',
            'energy'    => 'power',
            'priceType' => '',
            'sort'      => 8,
        ],
        'protergia_value_standard2' => [
            'label'     => 'Protergia Value Standard 2.0',
            'energy'    => 'power',
            'priceType' => '',
            'sort'      => 10,
        ],
        'protergia_power_gas_he' => [
            'label'     => 'Protergia Power+Gas — Συνδυασμός ΗΕ & ΦΑ',
            'energy'    => 'power',
            'priceType' => '',
            'sort'      => 11,
        ],
        'protergia_gas_sure2' => [
            'label'     => 'Protergia Gas Sure 2.0 — Σταθερό',
            'energy'    => 'gas',
            'priceType' => 'fixed',
            'sort'      => 1,
        ],
        'protergia_gas_standard' => [
            'label'     => 'Protergia Gas Standard — Κυμαινόμενο',
            'energy'    => 'gas',
            'priceType' => 'variable',
            'sort'      => 2,
        ],
        'protergia_gas_flex' => [
            'label'     => 'Protergia Gas Flex — Κυμαινόμενο',
            'energy'    => 'gas',
            'priceType' => 'variable',
            'sort'      => 3,
        ],
        'protergia_power_gas_fa' => [
            'label'     => 'Protergia Power+Gas — Συνδυασμός ΗΕ & ΦΑ',
            'energy'    => 'gas',
            'priceType' => '',
            'sort'      => 4,
        ],
    ];

    /** Το Value Lite 2.0 μένει, στη θέση 9 της λίστας. */
    public const KEPT = [ProtergiaHomePlans::LITE_2 => 9];

    /** Όσα δεν είναι πια στη λίστα της Protergia. */
    public const RETIRED = [
        ProtergiaHomePlans::SURE_12,
        ProtergiaHomePlans::SURE_18,
        ProtergiaHomePlans::BRIGHT,
        ProtergiaGasPlans::SURE,
        ProtergiaGasPlans::SINGLE,
    ];

    private function __construct()
    {
    }

    /** @return array<string, array{label: string, energy: string, priceType: string, sort: int}> */
    public static function added(): array
    {
        return self::ADDED;
    }

    public static function isRetired(string $code): bool
    {
        return in_array($code, self::RETIRED, true);
    }
}
