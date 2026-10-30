<?php

/**
 * (326) Η «δίδυμη» αίτηση: αέριο από αίτηση ρεύματος, ή ρεύμα από αίτηση
 * αερίου, στον ίδιο πελάτη και πάροχο.
 *
 * Η Protergia (29/10): «Η συνδυαστική προσφορά προκύπτει από την υπογραφή
 * των ξεχωριστών αιτήσεων ρεύματος και φυσικού αερίου στον ίδιο ΑΦΜ. Αρκεί
 * στην αίτηση ΦΑ του πελάτη στο πεδίο "Αριθμός Παροχής" να υπάρχει η
 * δυνατότητα να προστίθεται ο ΑΠ». Ο πωλητής έγραφε τα πάντα δύο φορές.
 *
 * Τι περνάει (mockup docs/UI-ADD-GAS.html, εγκρίθηκε 30/10): ο πελάτης,
 * οι διευθύνσεις, ο υπεύθυνος επικοινωνίας και ο νόμιμος εκπρόσωπος, ο
 * τρόπος πληρωμής και αποστολής λογαριασμού, οι συναινέσεις -- ό,τι αφορά
 * τον ΠΕΛΑΤΗ. Τι δεν περνάει: ό,τι αφορά την ΠΑΡΟΧΗ (πρόγραμμα, αριθμός
 * παροχής, μετρητής, ένδειξη, kVA, φάσεις, εγγύηση, προηγούμενος
 * πάροχος, προωθητική ενέργεια) και ό,τι αφορά τη ΣΥΜΦΩΝΙΑ (υπογραφή,
 * συναίνεση GDPR, ημερομηνίες, κατάσταση, κωδικός): ο πελάτης δεν έχει
 * υπογράψει ακόμα τη δεύτερη αίτηση.
 *
 * Ο αριθμός παροχής ρεύματος πάει εκεί που τον θέλει η Protergia: από το
 * ρεύμα στο «Αριθμός Παροχής Ρεύματος (Power + Gas)» του αερίου, και από
 * εκεί -- αν τον είχε γράψει ο πωλητής -- πίσω στον αριθμό παροχής του
 * ρεύματος.
 *
 * Καθαρή κλάση, χωρίς βάση: τη δοκιμάζουν τα unit tests.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class CompanionDraft
{
    /** Στήλες της σύμβασης που αφορούν τον πελάτη, όχι την παροχή. */
    private const COLUMNS = [
        'customer_id',
        'provider_id',
        'customer_type',
        'category',
        'activation_type',
        'supply_addr_same',
        'supply_street',
        'supply_street_no',
        'supply_city',
        'supply_postal_code',
        'supply_region',
        'billing_addr_same',
        'billing_street',
        'billing_street_no',
        'billing_city',
        'billing_postal_code',
        'billing_region',
    ];

    /** Κλειδιά του `extra_json` που αφορούν τον πελάτη, όχι την παροχή. */
    public const SHARED_EXTRAS = [
        'activity',
        'bill_delivery',
        'capacity_role',
        'company_type',
        'contact_adt',
        'contact_afm',
        'contact_email',
        'contact_first_name',
        'contact_last_name',
        'contact_mobile',
        'contact_phone',
        'gdpr_rights_ack',
        'gemi',
        'group_data_consent',
        'kad',
        'kod_synergati',
        'no_marketing_calls',
        'payment_method',
        'rep_first_name',
        'rep_id',
        'rep_last_name',
        'survey_consent',
        'terms_notice',
    ];

    /** Το πεδίο της αίτησης ΦΑ για τον αριθμό παροχής ρεύματος. */
    public const POWER_SUPPLY_EXTRA = 'gas_power_supply_number';

    private function __construct()
    {
    }

    /** Το είδος της δίδυμης αίτησης, ή '' (κινητή, άγνωστο). */
    public static function target(string $energy): string
    {
        return ['power' => 'gas', 'gas' => 'power'][$energy] ?? '';
    }

    /**
     * Οι στήλες της νέας αίτησης, χωρίς κατάσταση/σημειώσεις/κωδικό.
     *
     * @param array<string, mixed> $source η σύμβαση όπως τη δίνει η `find()`
     *                                     (με το `extra_json` αποκρυπτογραφημένο)
     *
     * @return array<string, mixed> κενό αν η πηγή δεν είναι ρεύμα ή αέριο
     */
    public static function from(array $source): array
    {
        $energy = (string) ($source['energy_type'] ?? '');
        $target = self::target($energy);

        if ($target === '') {
            return [];
        }

        $draft = ['energy_type' => $target];

        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $source) && $source[$column] !== null && $source[$column] !== '') {
                $draft[$column] = $source[$column];
            }
        }

        $extra = json_decode((string) ($source['extra_json'] ?? ''), true);
        $extra = is_array($extra) ? $extra : [];
        $out   = array_intersect_key($extra, array_flip(self::SHARED_EXTRAS));

        if ($energy === 'power') {
            $supply = trim((string) ($source['supply_number'] ?? ''));
            if ($supply !== '') {
                $out[self::POWER_SUPPLY_EXTRA] = $supply;
            }
        } else {
            $supply = trim((string) ($extra[self::POWER_SUPPLY_EXTRA] ?? ''));
            if ($supply !== '') {
                $draft['supply_number'] = $supply;
            }
        }

        // Τιμές που ήδη πέρασαν από το ExtraFields::toJson() στην αρχική
        // αποθήκευση· εδώ μόνο ξανακωδικοποιούνται, χωρίς WordPress.
        if ($out !== []) {
            $draft['extra_json'] = (string) json_encode($out, JSON_UNESCAPED_UNICODE);
        }

        return $draft;
    }

    /**
     * Η σημείωση της νέας αίτησης: από πού ήρθε.
     *
     * @param array<string, mixed> $source
     */
    public static function note(array $source): string
    {
        $label = ((string) ($source['energy_type'] ?? '')) === 'gas' ? 'αέριο' : 'ρεύμα';

        return 'Μαζί με ' . (string) ($source['code'] ?? '') . ' (' . $label . ')';
    }
}
