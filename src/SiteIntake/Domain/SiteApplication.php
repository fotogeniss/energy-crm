<?php

/**
 * Μια αίτηση όπως έρχεται από το site σύγκρισης τιμών, ελεγμένη.
 *
 * ## Τι πάει πού
 *
 * - Στις κανονικές στήλες του υποψηφίου (`leads`): ό,τι ήδη ξέρει να δείχνει
 *   η οθόνη Υποψηφίων — όνομα, κινητό, email, «ενδιαφέρον» (το πρόγραμμα),
 *   σημείωση. Ο πωλητής βλέπει την αίτηση χωρίς καμία νέα οθόνη.
 * - Στο `leads.site_data`, κρυπτογραφημένο: ΑΦΜ, διεύθυνση, αριθμός παροχής,
 *   κατηγορία, kWh, το πρόγραμμα αναλυτικά. Τα χρειάζεται μόνο η «Μετατροπή»
 *   για να γεμίσει πελάτη και σύμβαση· δεν τα δείχνει καμία λίστα.
 *
 * ## Τι ΔΕΝ ελέγχει
 *
 * Το κινητό: κανονικοποιείται από τον έναν κανόνα του CRM
 * (`ECRM_Messaging::normalize_phone()`), στο Http, όχι εδώ.
 * Το ΑΦΜ: ελέγχεται μόνο ότι είναι 9 ψηφία, ΟΠΩΣ ήρθε. Το site το έχει ήδη
 * ελέγξει με το ψηφίο ελέγχου· εδώ δεν «καθαρίζεται» (κόψιμο μη-ψηφίων
 * επιτρέπεται σε ένα μόνο σημείο, δες `AfmIsNormalisedInOnePlaceTest`).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\SiteIntake\Domain;

final class SiteApplication
{
    public const SOURCE = 'site';

    /**
     * @param array<string, mixed> $private Τα στοιχεία που πάνε κρυπτογραφημένα στο site_data.
     */
    private function __construct(
        public readonly string $ref,
        public readonly string $fullName,
        public readonly string $phone,
        public readonly string $email,
        public readonly string $consentAt,
        public readonly string $consentIp,
        public readonly string $interest,
        public readonly string $notes,
        public readonly array $private,
    ) {
    }

    /**
     * @param array<string, mixed> $p Το JSON του site.
     *
     * @throws InvalidSiteApplication
     */
    public static function fromPayload(array $p): self
    {
        $ref = self::text($p, 'ref', 64);
        if (preg_match('/^[A-Za-z0-9\-]{8,64}$/', $ref) !== 1) {
            throw new InvalidSiteApplication('Λείπει ή είναι λάθος το αναγνωριστικό αίτησης (ref).');
        }

        $name = self::text($p, 'full_name', 160);
        if ($name === '' || preg_match('/\p{L}/u', $name) !== 1) {
            throw new InvalidSiteApplication('Λείπει το ονοματεπώνυμο.');
        }

        $phone = self::text($p, 'phone', 40);
        if ($phone === '') {
            throw new InvalidSiteApplication('Λείπει το κινητό.');
        }

        $email = self::text($p, 'email', 160);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = '';
        }

        $afm = self::text($p, 'afm', 9);
        if ($afm !== '' && preg_match('/^\d{9}$/', $afm) !== 1) {
            throw new InvalidSiteApplication('Το ΑΦΜ πρέπει να είναι 9 ψηφία.');
        }

        $category = (string) ($p['category'] ?? '');
        if (! in_array($category, ['home', 'business'], true)) {
            throw new InvalidSiteApplication('Λάθος κατηγορία (home ή business).');
        }

        $consentAt = self::text($p, 'consent_at', 32);
        if ($consentAt === '' || strtotime($consentAt) === false) {
            throw new InvalidSiteApplication('Λείπει η ώρα συναίνεσης του πελάτη.');
        }

        $consentIp = self::text($p, 'consent_ip', 64);
        if ($consentIp !== '' && filter_var($consentIp, FILTER_VALIDATE_IP) === false) {
            $consentIp = '';
        }

        $kwh     = max(0, min(100000, (int) ($p['monthly_kwh'] ?? 0)));
        $plan    = is_array($p['plan'] ?? null) ? $p['plan'] : [];
        $planOut = [
            'provider'      => self::text($plan, 'provider', 120),
            'provider_slug' => self::text($plan, 'provider_slug', 64),
            'name'          => self::text($plan, 'name', 160),
            'tariff'        => self::text($plan, 'tariff', 16),
            'monthly'       => round((float) ($plan['monthly'] ?? 0), 2),
            'price_kwh'     => round((float) ($plan['price_kwh'] ?? 0), 5),
            'fixed_monthly' => round((float) ($plan['fixed_monthly'] ?? 0), 2),
        ];

        $private = [
            'afm'              => $afm,
            'street'           => self::text($p, 'street', 160),
            'city'             => self::text($p, 'city', 80),
            'postal_code'      => self::text($p, 'postal_code', 5),
            'supply_number'    => self::text($p, 'supply_number', 40),
            'category'         => $category,
            'monthly_kwh'      => $kwh,
            'current_provider' => self::text($p, 'current_provider', 120),
            'bill_in_own_name' => (bool) ($p['bill_in_own_name'] ?? true),
            'docs_later'       => (bool) ($p['docs_later'] ?? false),
            'terms_version'    => self::text($p, 'terms_version', 20),
            'reference'        => self::text($p, 'reference', 20),
            'plan'             => $planOut,
        ];

        return new self(
            $ref,
            $name,
            $phone,
            $email,
            gmdate('Y-m-d H:i:s', (int) strtotime($consentAt)),
            $consentIp,
            self::interestLine($planOut, $kwh, $category),
            self::notesText($private),
            $private,
        );
    }

    /**
     * Η γραμμή που βλέπει ο πωλητής στη λίστα Υποψηφίων (στήλη 255 χαρακτήρων).
     *
     * @param array<string, mixed> $plan
     */
    private static function interestLine(array $plan, int $kwh, string $category): string
    {
        $parts = [];

        if ($plan['name'] !== '') {
            $parts[] = trim($plan['provider'] . ' ' . $plan['name']);
        }
        if ($kwh > 0) {
            $parts[] = $kwh . ' kWh/μήνα';
        }
        if ($plan['monthly'] > 0) {
            $parts[] = '~' . number_format((float) $plan['monthly'], 2, ',', '.') . ' €/μήνα';
        }
        $parts[] = $category === 'business' ? 'Επαγγελματικό' : 'Οικιακό';

        return mb_substr(implode(' · ', $parts), 0, 255);
    }

    /**
     * Η σημείωση του υποψηφίου. ΧΩΡΙΣ ΑΦΜ, διεύθυνση και αριθμό παροχής: η
     * σημείωση είναι απλό κείμενο και φαίνεται σε λίστες· αυτά μένουν στο
     * κρυπτογραφημένο site_data και περνούν στη σύμβαση με τη «Μετατροπή».
     *
     * @param array<string, mixed> $private
     */
    private static function notesText(array $private): string
    {
        $lines = ['Αίτηση από το site σύγκρισης τιμών'
            . ($private['reference'] !== '' ? ', αριθμός ' . $private['reference'] : '') . '.'];

        if ($private['current_provider'] !== '') {
            $lines[] = 'Πάροχος σήμερα: ' . $private['current_provider'];
        }
        if (! $private['bill_in_own_name']) {
            $lines[] = 'Ο λογαριασμός ΔΕΝ είναι στο όνομά του (θέλει μισθωτήριο ή Ε9).';
        }
        if ($private['docs_later']) {
            $lines[] = 'Είπε ότι θα στείλει τα χαρτιά αργότερα.';
        }
        $lines[] = 'ΑΦΜ, διεύθυνση και αριθμός παροχής μπαίνουν στη σύμβαση με τη «Μετατροπή».';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $p
     */
    private static function text(array $p, string $key, int $max): string
    {
        $value = $p[$key] ?? '';

        if (! is_scalar($value)) {
            return '';
        }

        // Χαρακτήρες ελέγχου (νέες γραμμές, NUL) δεν έχουν θέση σε κανένα πεδίο μιας γραμμής.
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));

        return mb_substr($clean, 0, $max);
    }
}
