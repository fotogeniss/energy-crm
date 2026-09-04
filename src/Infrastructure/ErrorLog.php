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
 * ## Δύο κατηγορίες, και η γραμμή ανάμεσά τους είναι ο κωδικός HTTP
 *
 * 4xx — ο χρήστης μπορεί να το διορθώσει: λείπει ΑΦΜ, μη έγκυρο ΑΦΜ, λείπει
 * δικαιολογητικό, η μετάβαση δεν επιτρέπεται. Το CRM ήδη απαντά με ελληνικό
 * μήνυμα και συχνά με `field`, ώστε να μπει δίπλα στο input. Αυτά ΔΕΝ
 * καταγράφονται και δεν παίρνουν κωδικό: δεν είναι βλάβες, είναι η εφαρμογή που
 * κάνει τη δουλειά της.
 *
 * 5xx — εμείς σπάσαμε. Ο χρήστης δεν έχει τι να κάνει, και το «Η αποθήκευση
 * απέτυχε» δεν λέει τίποτα σε κανέναν. Εδώ το σφάλμα καταγράφεται με **κωδικό
 * αναφοράς**, και ο χρήστης βλέπει μόνο τον κωδικό. Το πρωτότυπο μήνυμα μένει
 * στο log.
 *
 * Ο κωδικός δεν είναι διακοσμητικός: είναι ο μόνος τρόπος να συνδεθεί ένα «δεν
 * δούλεψε κάτι» στο τηλέφωνο με τη συγκεκριμένη γραμμή που έσπασε.
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

    /** Ό,τι βλέπει ο χρήστης όταν φταίμε εμείς. Το %s είναι ο κωδικός. */
    private const USER_MESSAGE = 'Παρουσιάστηκε τεχνικό πρόβλημα. Δεν φταις εσύ — '
        . 'δοκίμασε ξανά σε λίγο. Αν επιμείνει, δώσε τον κωδικό %s.';

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

            $code = $this->record('rest', $message, (string) $request->get_route(), 0);

            // Το πρωτότυπο μήνυμα μένει στο log και ΔΕΝ φτάνει στον χρήστη: είναι
            // εσωτερικό και δεν του λέει τι να κάνει. Παίρνει τον κωδικό, που
            // είναι το μόνο που χρειάζεται για να το αναφέρει.
            if (is_array($data)) {
                $data['error'] = sprintf(self::USER_MESSAGE, $code);
                $data['code']  = $code;
                $response->set_data($data);
            }
        }

        return $response;
    }

    /** Χειροκίνητη καταχώριση από catch block. Επιστρέφει τον κωδικό αναφοράς. */
    public function recordThrowable(\Throwable $e): string
    {
        return $this->record('exception', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
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

    /** @return string Ο κωδικός αναφοράς, π.χ. ECRM-7F32. */
    private function record(string $kind, string $message, string $file, int $line): string
    {
        // Πριν από κάθε διακλάδωση: ο μετρητής της ημέρας μετράει ΣΥΜΒΑΝΤΑ, όχι
        // κωδικούς. Ένα σφάλμα που επαναλαμβάνεται χίλιες φορές δεν φτιάχνει
        // νέα εγγραφή εδώ (βλ. παρακάτω) -- στην τάση όμως πρέπει να φαίνεται
        // ως χίλια, αλλιώς η χειρότερη μέρα μοιάζει με την ήσυχη.
        Metrics::bump(Metrics::ERRORS);

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
        // Ο κωδικός ΜΕΝΕΙ ο ίδιος — ο χρήστης που το ανέφερε πρέπει να βρίσκει
        // την ίδια εγγραφή, με το πλήθος να δείχνει πόσες φορές συνέβη.
        if (
            $log !== []
            && ($log[0]['message'] ?? '') === $entry['message']
            && ($log[0]['where'] ?? '') === $entry['where']
        ) {
            $log[0]['count'] = (int) ($log[0]['count'] ?? 1) + 1;
            $log[0]['at']    = $entry['at'];

            update_option(self::OPTION, array_slice($log, 0, self::KEEP), false);

            return (string) ($log[0]['code'] ?? '');
        }

        $entry['count'] = 1;
        $entry['code']  = self::newCode(array_column($log, 'code'));
        array_unshift($log, $entry);

        update_option(self::OPTION, array_slice($log, 0, self::KEEP), false);

        self::notifyAdmin($entry);

        return $entry['code'];
    }

    /**
     * Email στο admin_email του site για ΚΑΘΕ νέο κωδικό σφάλματος -- όχι για
     * επαναλήψεις ήδη γνωστού (αυτές μόνο ανεβάζουν το count στην ίδια
     * εγγραφή, βλ. record()). Χωρίς αυτό, η μόνη ένδειξη ότι κάτι έσπασε είναι
     * κάποιος να ανοίξει χειροκίνητα την Υγεία -- στο live αυτό σημαίνει
     * μέρες.
     *
     * Throttled σε 1 email/ώρα συνολικά (όχι ανά κωδικό): ένας βρόχος που
     * παράγει δεκάδες ΔΙΑΦΟΡΕΤΙΚΑ σφάλματα μέσα σε λίγα δευτερόλεπτα δεν
     * πρέπει να γίνει βόμβα στο inbox. Η Υγεία δείχνει πάντα την πλήρη
     * εικόνα -- το email είναι ειδοποίηση, όχι το αρχείο.
     *
     * @param array<string, mixed> $entry
     */
    private static function notifyAdmin(array $entry): void
    {
        $lock = 'ecrm_error_notify_lock';

        if (get_transient($lock) !== false) {
            return;
        }

        set_transient($lock, 1, HOUR_IN_SECONDS);

        $to = get_option('admin_email');

        if (! is_string($to) || $to === '') {
            return;
        }

        $subject = sprintf('[Energy CRM] Σφάλμα %s', $entry['code']);

        $body = sprintf(
            "Νέο σφάλμα καταγράφηκε στο Energy CRM.\n\n"
            . "Κωδικός: %s\nΤύπος: %s\nΔιαδρομή: %s\nΣημείο: %s\nΏρα (UTC): %s\n\n"
            . "Μήνυμα: %s\n\n"
            . "Πλήρη λεπτομέρεια στην Υγεία (Energy CRM → Υγεία). Αν συνέβησαν κι "
            . "άλλα σφάλματα την ίδια ώρα, δεν στάλθηκε ξεχωριστό email γι' αυτά "
            . "(το πολύ 1 email/ώρα) -- έλεγξε εκεί.",
            $entry['code'],
            $entry['kind'],
            $entry['route'],
            $entry['where'],
            $entry['at'],
            $entry['message']
        );

        wp_mail($to, $subject, $body);
    }

    /**
     * Κωδικός αναφοράς: κοντός για να ειπωθεί στο τηλέφωνο, μοναδικός μέσα στα
     * όσα κρατάμε.
     *
     * Τέσσερα δεκαεξαδικά είναι 65.536 τιμές για 50 εγγραφές, οπότε η σύγκρουση
     * είναι πρακτικά αδύνατη — ο έλεγχος όμως κοστίζει τίποτα και μια σύγκρουση
     * θα έστελνε τον χρήστη σε ξένο συμβάν.
     *
     * Το όριο των 20 προσπαθειών είναι φράγμα ατέρμονου βρόχου, όχι πρόβλεψη.
     *
     * @param list<mixed> $taken
     */
    public static function newCode(array $taken): string
    {
        $used = array_map('strval', $taken);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = 'ECRM-' . strtoupper(bin2hex(random_bytes(2)));

            if (! in_array($code, $used, true)) {
                return $code;
            }
        }

        // Είκοσι συγκρούσεις στη σειρά δεν συμβαίνουν· αν συμβούν, ένα
        // μακρύτερο είναι καλύτερο από έναν διπλό.
        return 'ECRM-' . strtoupper(bin2hex(random_bytes(3)));
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
