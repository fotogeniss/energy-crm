<?php

/**
 * Τα τελευταία σφάλματα, ώστε να υπάρχει τι να σταλεί όταν κάτι σπάσει.
 *
 * Με WP_DEBUG κλειστό ο χρήστης βλέπει «There has been a critical error» και
 * τίποτα άλλο. Τα error_log() του plugin πάνε στο log της PHP, που στην
 * παραγωγή συνήθως δεν το φτάνει κανείς.
 *
 * ΤΙ ΔΕΝ ΚΡΑΤΑΕΙ, και είναι ο λόγος που δεν είναι απλό:
 * περιεχόμενο αιτήματος, POST/GET, cookies, headers. Ένας καταγραφέας που τα
 * κρατούσε θα ήταν δεύτερο αντίγραφο από ΑΦΜ και ΑΔΤ, με άλλη διάρκεια ζωής και
 * έξω από τη διαγραφή GDPR. Κρατά ώρα, μήνυμα, αρχείο:γραμμή, διαδρομή χωρίς
 * query string, και id χρήστη.
 *
 * Το μήνυμα περνά από scrub(): μια εξαίρεση μπορεί να κουβαλά τιμή στο κείμενό
 * της, και δεν ελέγχουμε τι γράφει κάθε βιβλιοθήκη.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class ErrorLog
{
    public const OPTION = 'ecrm_error_log';

    /** Όσα χωράνε σε μία οθόνη χωρίς να φουσκώσει το option. */
    private const KEEP = 50;

    /** Κόβει μήνυμα που ξέφυγε· κανένα δικό μας δεν πλησιάζει. */
    private const MAX_MESSAGE = 500;

    /** Μόνο αυτά είναι μοιραία· τα warnings θα γέμιζαν την οθόνη. */
    private const FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    public function register(): void
    {
        register_shutdown_function([$this, 'catchFatal']);
        add_filter('rest_post_dispatch', [$this, 'catchRestFailure'], 20, 3);
    }

    /** Ο τελευταίος λόγος που πέθανε το request, αν πέθανε. */
    public function catchFatal(): void
    {
        $error = error_get_last();

        if ($error === null || ! in_array($error['type'], self::FATAL, true)) {
            return;
        }

        // Μόνο δικά μας. Σφάλμα άλλου plugin δεν είναι δικό μας να το δείξουμε,
        // και η οθόνη θα γέμιζε με θόρυβο που δεν μπορούμε να διορθώσουμε.
        if (! str_contains(str_replace('\\', '/', $error['file']), '/plugins/energy-crm/')) {
            return;
        }

        $this->record('fatal', $error['message'], $error['file'], $error['line']);
    }

    /**
     * Απάντηση 5xx από δικό μας route.
     *
     * @param mixed $response
     * @param mixed $server   Αχρησιμοποίητο· η υπογραφή είναι του WordPress.
     * @param mixed $request
     *
     * @return mixed
     */
    public function catchRestFailure($response, $server, $request)
    {
        unset($server);

        if (
            $response instanceof \WP_REST_Response
            && $request instanceof \WP_REST_Request
            && $response->get_status() >= 500
            && str_starts_with((string) $request->get_route(), '/' . \EnergyCRM\Http\Router::NAMESPACE)
        ) {
            $data    = $response->get_data();
            $message = is_array($data) && isset($data['error']) && is_scalar($data['error'])
                ? (string) $data['error']
                : 'HTTP ' . $response->get_status();

            $this->record('rest', $message, (string) $request->get_route(), 0);
        }

        return $response;
    }

    /** Χειροκίνητη καταχώριση από catch block. */
    public function recordThrowable(\Throwable $e): void
    {
        $this->record('exception', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    }

    /**
     * @return list<array<string, mixed>> Νεότερα πρώτα.
     */
    public function recent(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? array_values($stored) : [];
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }

    private function record(string $kind, string $message, string $file, int $line): void
    {
        $entry = [
            'at'      => gmdate('Y-m-d H:i:s'),
            'kind'    => $kind,
            'message' => self::scrub($message),
            'where'   => self::shorten($file) . ($line > 0 ? ':' . $line : ''),
            'route'   => self::path(),
            'user'    => get_current_user_id(),
            'version' => \EnergyCRM\Plugin::VERSION,
        ];

        $log = $this->recent();

        // Ίδιο σφάλμα στη σειρά μετριέται, δεν επαναλαμβάνεται: ένα cron που
        // σπάει κάθε λεπτό θα έσβηνε όλο το υπόλοιπο ιστορικό μέσα σε μια ώρα.
        if ($log !== [] && ($log[0]['message'] ?? '') === $entry['message'] && ($log[0]['where'] ?? '') === $entry['where']) {
            $log[0]['count'] = (int) ($log[0]['count'] ?? 1) + 1;
            $log[0]['at']    = $entry['at'];
        } else {
            $entry['count'] = 1;
            array_unshift($log, $entry);
        }

        update_option(self::OPTION, array_slice($log, 0, self::KEEP), false);
    }

    /**
     * Βγάζει ό,τι μοιάζει με προσωπικό δεδομένο από ένα μήνυμα σφάλματος.
     *
     * Δεν είναι απόδειξη ότι δεν περνά τίποτα — είναι ό,τι μπορεί να γίνει χωρίς
     * να ξέρουμε τι γράφει κάθε βιβλιοθήκη στα δικά της μηνύματα.
     */
    public static function scrub(string $message): string
    {
        // Η σειρά μετράει: τα μακρύτερα πρώτα, αλλιώς το ΑΦΜ κόβει εννιά ψηφία
        // από τη μέση ενός IBAN και το υπόλοιπο μένει.
        //
        // Lookarounds και όχι \b: το \b της PCRE δουλεύει σε ASCII ακόμα και με
        // /u, οπότε δεν βλέπει όριο πριν από ένα ελληνικό «Α» και το ΑΔΤ θα
        // περνούσε άθικτο.
        $patterns = [
            '/ecrm1:[A-Za-z0-9+\/=]+/u'                              => '[κρυπτογραφημένο]',
            '/(?<![A-Z0-9])[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}(?![A-Z0-9])/u' => '[IBAN;]',
            '/[\w.+-]+@[\w-]+\.[\w.]+/u'                             => '[email]',
            '/(?<![0-9A-Za-zΑ-Ωα-ω])[Α-ΩA-Z]{1,3}[ -]?[0-9]{6}(?![0-9])/u' => '[ΑΔΤ;]',
            '/(?<![0-9])(?:\+?30)?6[0-9]{9}(?![0-9])/u'               => '[κινητό;]',
            '/(?<![0-9])[0-9]{9}(?![0-9])/u'                          => '[ΑΦΜ;]',
        ];

        $clean = (string) preg_replace(array_keys($patterns), array_values($patterns), $message);

        return mb_substr($clean, 0, self::MAX_MESSAGE);
    }

    /** Απόλυτη διαδρομή σε σχετική, ώστε να μη λέει πού ζει το site. */
    private static function shorten(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $at   = strpos($file, '/plugins/energy-crm/');

        return $at === false ? basename($file) : substr($file, $at + 20);
    }

    /** Η διαδρομή χωρίς query string: εκεί μπαίνουν παράμετροι. */
    private static function path(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- κρατιέται μόνο το path, χωρίς query.
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';

        if ($uri === '') {
            return wp_doing_cron() ? '(cron)' : '(cli)';
        }

        return sanitize_text_field((string) (parse_url($uri, PHP_URL_PATH) ?? ''));
    }
}
