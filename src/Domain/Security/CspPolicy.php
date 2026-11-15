<?php

/**
 * (394) Η Content-Security-Policy του CRM, ως κείμενο. Έλεγχος ασφαλείας
 * 15/11/2026, εύρημα 4 (project: claude/energy-crm-security-review-1511.md).
 *
 * Τι επιτρέπεται να φορτώσει η σελίδα: σενάρια μόνο από το ίδιο το site ή με
 * το nonce της αίτησης (καθόλου inline χωρίς nonce, καθόλου `eval`), σύνδεση
 * μόνο με το ίδιο το site, κανένα `<object>`, καμία φόρμα προς αλλού, καμία
 * ενσωμάτωση της σελίδας σε ξένο site. Αν ποτέ ξεφύγει κάποιο κείμενο
 * χωρίς escape, ο browser δεν θα τρέξει ό,τι κουβαλά.
 *
 * Τα στυλ μένουν με `'unsafe-inline'`: η εφαρμογή χρησιμοποιεί `style="--h:…"`
 * σε πολλές γραμμές, και τα attributes στυλ δεν παίρνουν nonce. Ένα στυλ δεν
 * τρέχει κώδικα· το ρίσκο που μένει είναι μικρό.
 *
 * Οι εικόνες από `https:` γενικά: τα λογότυπα παρόχων τα ορίζει ο διαχειριστής
 * με όποιο URL θέλει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Security;

final class CspPolicy
{
    /** Πόσες διαφορετικές παραβιάσεις κρατιούνται για τη σελίδα «Υγεία». */
    public const MAX_KEPT = 100;

    private const MAX_FIELD = 200;

    private function __construct()
    {
    }

    /**
     * @param list<string> $scriptHosts Επιπλέον πηγές σεναρίων (π.χ. https://cdn.jsdelivr.net).
     */
    public static function header(string $nonce, string $reportUri, array $scriptHosts = []): string
    {
        $script = array_merge(["'self'", "'nonce-" . $nonce . "'"], $scriptHosts);

        $directives = [
            'default-src'     => ["'self'"],
            'script-src'      => $script,
            'style-src'       => ["'self'", "'unsafe-inline'"],
            'img-src'         => ["'self'", 'data:', 'blob:', 'https:'],
            'font-src'        => ["'self'", 'data:'],
            'connect-src'     => ["'self'"],
            'worker-src'      => ["'self'", 'blob:'],
            'manifest-src'    => ["'self'"],
            'frame-src'       => ["'self'", 'blob:'],
            'object-src'      => ["'none'"],
            'base-uri'        => ["'self'"],
            'form-action'     => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        $parts = [];

        foreach ($directives as $name => $sources) {
            $parts[] = $name . ' ' . implode(' ', $sources);
        }

        if ($reportUri !== '') {
            $parts[] = 'report-uri ' . $reportUri;
        }

        return implode('; ', $parts);
    }

    /**
     * Μία αναφορά του browser σε σχήμα που κρατιέται: ποια οδηγία, τι
     * μπλοκαρίστηκε, σε ποια σελίδα. Χωρίς query string πουθενά: στη
     * διεύθυνση της σελίδας μπορεί να είναι ένα token παρακολούθησης.
     * Δέχεται και τα δύο σχήματα (report-uri και Reporting API).
     *
     * @param array<string, mixed> $report
     * @return array{directive: string, blocked: string, page: string, line: int}|null
     */
    public static function normalise(array $report): ?array
    {
        $body = $report['csp-report'] ?? $report['body'] ?? $report;

        if (! is_array($body)) {
            return null;
        }

        // Reporting API: στέλνει και άλλα είδη (deprecation, intervention).
        if (isset($report['type']) && $report['type'] !== 'csp-violation') {
            return null;
        }

        $directive = self::field($body, ['effective-directive', 'effectiveDirective',
            'violated-directive', 'violatedDirective']);
        $directive = strtolower(trim(explode(' ', $directive)[0]));

        if ($directive === '' || preg_match('/^[a-z\-]+$/', $directive) !== 1) {
            return null;
        }

        $blocked = self::field($body, ['blocked-uri', 'blockedURL']);
        $page    = self::field($body, ['document-uri', 'documentURL']);
        $line    = (int) self::field($body, ['line-number', 'lineNumber']);

        return [
            'directive' => $directive,
            'blocked'   => self::clean($blocked),
            'page'      => self::clean($page),
            'line'      => max(0, $line),
        ];
    }

    /**
     * Το κλειδί με το οποίο ίδιες παραβιάσεις μετρώνται μαζί.
     *
     * @param array{directive: string, blocked: string, page: string, line: int} $normalised
     */
    public static function key(array $normalised): string
    {
        return md5($normalised['directive'] . '|' . $normalised['blocked'] . '|' . $normalised['page']
            . '|' . $normalised['line']);
    }

    /**
     * Η πρώτη τιμή που υπάρχει, μόνο αν είναι απλή τιμή (όχι πίνακας).
     *
     * @param array<mixed>  $body
     * @param list<string>  $names
     */
    private static function field(array $body, array $names): string
    {
        foreach ($names as $name) {
            if (isset($body[$name]) && is_scalar($body[$name])) {
                return (string) $body[$name];
            }
        }

        return '';
    }

    /** Χωρίς query/fragment, χωρίς χαρακτήρες ελέγχου, κομμένο. */
    private static function clean(string $uri): string
    {
        $uri = (string) preg_replace('/[?#].*$/s', '', $uri);
        $uri = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $uri);

        return mb_substr(trim($uri), 0, self::MAX_FIELD);
    }
}
