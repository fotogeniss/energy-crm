<?php

/**
 * Τι μαθαίνει ο AlfrAId για μια αίτηση, σε μία γραμμή κειμένου.
 *
 * Ό,τι γράφεται εδώ φεύγει στο API της Anthropic με κάθε ερώτηση. Ο
 * ιδιοκτήτης αποφάσισε (05/10/2026): **κανένα προσωπικό στοιχείο**. Όχι όνομα,
 * ΑΦΜ, ΑΔΤ, τηλέφωνο, email, διεύθυνση, αριθμός παροχής ή IP.
 *
 * Η προστασία είναι σε δύο επίπεδα. Το ερώτημα στη βάση
 * (`ContractQueries::forAssistant()`) δεν διαβάζει καν τον πίνακα πελατών.
 * Και η κλάση αυτή γράφει μόνο τα πεδία του `FIELDS`, ό,τι κι αν της
 * δοθεί. Αν αύριο κάποιος προσθέσει στήλη στο ερώτημα, εδώ δεν περνάει.
 *
 * Καθαρό PHP, χωρίς WordPress, για να ελέγχεται σε unit test.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Assistant;

final class ContractBrief
{
    /** Τα μόνα πεδία που επιτρέπεται να βγουν. */
    public const FIELDS = [
        'code', 'kind', 'provider', 'program', 'status', 'idle_days',
        'created', 'signed', 'missing', 'history',
    ];

    /** Πόσες αιτήσεις διαβάζει από μία ερώτηση με κωδικούς. */
    public const MAX_CODES = 3;

    private function __construct()
    {
    }

    /**
     * Οι κωδικοί αιτήσεων που γράφει ο χρήστης, π.χ. «ORIZON-7166» ή
     * «orizon-0007166». Κεφαλαία, χωρίς διπλούς, το πολύ τρεις.
     *
     * @return list<string>
     */
    public static function codesIn(string $text): array
    {
        if (! preg_match_all('/\b([A-Za-z]{1,12})-(\d{1,10})\b/u', $text, $m, PREG_SET_ORDER)) {
            return [];
        }

        $out = [];

        foreach ($m as $hit) {
            $code = strtoupper($hit[1]) . '-' . $hit[2];

            if (! in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return array_slice($out, 0, self::MAX_CODES);
    }

    /**
     * Μία αίτηση σε μία γραμμή.
     *
     * @param array<string, mixed> $c Κλειδιά από το `FIELDS`. Ό,τι άλλο
     *                                αγνοείται.
     */
    public static function line(array $c): string
    {
        $c = array_intersect_key($c, array_flip(self::FIELDS));

        $parts = [(string) ($c['code'] ?? '?')];

        $kind = trim((string) ($c['kind'] ?? ''));

        if ($kind !== '') {
            $parts[] = $kind;
        }

        $offer = trim((string) ($c['provider'] ?? '') . ' ' . (string) ($c['program'] ?? ''));

        if ($offer !== '') {
            $parts[] = $offer;
        }

        $status = '«' . (string) ($c['status'] ?? '') . '»';

        if (isset($c['idle_days']) && is_numeric($c['idle_days'])) {
            $days    = (int) $c['idle_days'];
            $status .= ' χωρίς αλλαγή εδώ και ' . $days . ($days === 1 ? ' μέρα' : ' μέρες');
        }

        $parts[] = 'κατάσταση ' . $status;

        if (! empty($c['created'])) {
            $parts[] = 'καταχωρήθηκε ' . (string) $c['created'];
        }

        if (array_key_exists('signed', $c)) {
            $parts[] = $c['signed'] ? 'έχει υπογραφή πελάτη' : 'χωρίς υπογραφή πελάτη';
        }

        if (array_key_exists('missing', $c)) {
            $missing = is_array($c['missing']) ? $c['missing'] : [];

            $parts[] = $missing === []
                ? 'δεν λείπουν δικαιολογητικά'
                : 'λείπουν: ' . implode(', ', array_map('strval', $missing));
        }

        $history = is_array($c['history'] ?? null) ? $c['history'] : [];

        if ($history !== []) {
            $parts[] = 'ιστορικό: ' . implode('; ', array_map('strval', $history));
        }

        return '- ' . implode(' · ', $parts);
    }
}
