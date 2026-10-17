<?php

/**
 * Ό,τι έστειλε το site, έτοιμο για τη «Μετατροπή» υποψηφίου σε σύμβαση.
 *
 * Η Μετατροπή φτιάχνει πελάτη και πρόχειρη σύμβαση. Για υποψήφιο από το site
 * ξέρουμε ήδη πολύ περισσότερα από κινητό: ΑΦΜ, διεύθυνση, αριθμό παροχής,
 * οικιακό ή επαγγελματικό, πάροχο. Αυτή η κλάση λέει ΤΙ μπαίνει πού· ο
 * controller απλώς το περνά στα repositories που υπάρχουν ήδη.
 *
 * Το όνομα ΔΕΝ το αγγίζει: ο υποψήφιος έχει ήδη ονοματεπώνυμο, και η
 * Μετατροπή το χωρίζει όπως πάντα. Μία συμπεριφορά για όλους τους υποψηφίους.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\SiteIntake\Domain;

use EnergyCRM\Domain\Customer\GreekAddress;

final class SiteLeadPrefill
{
    private function __construct()
    {
    }

    /**
     * Επιπλέον πεδία πελάτη.
     *
     * @param array<string, mixed> $data Το αποκρυπτογραφημένο site_data.
     *
     * @return array<string, string>
     */
    public static function customer(array $data): array
    {
        $out = [];

        if (($data['afm'] ?? '') !== '') {
            $out['afm'] = (string) $data['afm'];
        }

        // «Καραϊσκάκη 12» → οδός + αριθμός, με τον ίδιο κανόνα που χρησιμοποιεί
        // ήδη το CRM για διευθύνσεις από ελεύθερο κείμενο.
        $street = trim((string) ($data['street'] ?? ''));
        if ($street !== '') {
            $parsed           = GreekAddress::parse($street);
            $out['street']    = $parsed['street'] !== '' ? $parsed['street'] : $street;
            if ($parsed['street_no'] !== '') {
                $out['street_no'] = $parsed['street_no'];
            }
        }

        foreach (['city', 'postal_code'] as $key) {
            if (($data[$key] ?? '') !== '') {
                $out[$key] = (string) $data[$key];
            }
        }

        return $out;
    }

    /**
     * Επιπλέον πεδία της πρόχειρης σύμβασης.
     *
     * @param array<string, mixed>              $data      Το αποκρυπτογραφημένο site_data.
     * @param list<array<string, mixed>>        $providers Οι ενεργοί πάροχοι του CRM (id, slug, name).
     *
     * @return array<string, mixed>
     */
    public static function contract(array $data, array $providers): array
    {
        $out = [
            'category' => ($data['category'] ?? '') === 'business' ? 'business' : 'home',
        ];

        if (($data['supply_number'] ?? '') !== '') {
            $out['supply_number'] = (string) $data['supply_number'];
        }

        $plan       = is_array($data['plan'] ?? null) ? $data['plan'] : [];
        $providerId = self::providerId($plan, $providers);
        if ($providerId > 0) {
            $out['provider_id'] = $providerId;
        }

        $out['notes'] = self::notes($data, $plan);

        return $out;
    }

    /**
     * Ο πάροχος του προγράμματος, αν τον έχει το CRM. Πρώτα με slug, μετά με
     * όνομα χωρίς τόνους/κεφαλαία («Protergia» = «PROTERGIA»). Αν δεν βρεθεί,
     * η σύμβαση μένει χωρίς πάροχο και ο πωλητής τον διαλέγει: λάθος πάροχος
     * είναι χειρότερος από κανέναν.
     *
     * @param array<string, mixed>       $plan
     * @param list<array<string, mixed>> $providers
     */
    public static function providerId(array $plan, array $providers): int
    {
        $slug = strtolower(trim((string) ($plan['provider_slug'] ?? '')));
        $name = self::fold((string) ($plan['provider'] ?? ''));

        foreach ($providers as $row) {
            if ($slug !== '' && strtolower((string) ($row['slug'] ?? '')) === $slug) {
                return (int) $row['id'];
            }
        }

        foreach ($providers as $row) {
            if ($name !== '' && self::fold((string) ($row['name'] ?? '')) === $name) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $plan
     */
    private static function notes(array $data, array $plan): string
    {
        $lines = ['Από το site σύγκρισης τιμών.'];

        if (($plan['name'] ?? '') !== '') {
            $lines[] = 'Πρόγραμμα: ' . trim(($plan['provider'] ?? '') . ' ' . $plan['name'])
                . (($plan['tariff'] ?? '') !== '' ? ' (' . $plan['tariff'] . ')' : '');
        }
        if ((int) ($data['monthly_kwh'] ?? 0) > 0) {
            $lines[] = 'Κατανάλωση: ' . (int) $data['monthly_kwh'] . ' kWh/μήνα';
        }
        if ((float) ($plan['monthly'] ?? 0) > 0) {
            $lines[] = 'Εκτίμηση που είδε ο πελάτης: '
                . number_format((float) $plan['monthly'], 2, ',', '.') . ' €/μήνα';
        }
        if (($data['current_provider'] ?? '') !== '') {
            $lines[] = 'Πάροχος σήμερα: ' . $data['current_provider'];
        }

        return implode("\n", $lines);
    }

    /** Κεφαλαία χωρίς τόνους και κενά, για σύγκριση ονομάτων. */
    private static function fold(string $value): string
    {
        $upper = mb_strtoupper(trim($value));

        return strtr($upper, [
            'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω',
            'Ϊ' => 'Ι', 'Ϋ' => 'Υ', ' ' => '',
        ]);
    }
}
