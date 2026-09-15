<?php

/**
 * Το δικό μας επίπεδο αποστολής email -- χωρίς plugin τρίτων.
 *
 * Αφορμή: το email υπογραφής έφτανε πάντα στα Ανεπιθύμητα. Η αιτία δεν ήταν
 * ένα bug· ήταν ότι το site δεν είχε ΚΑΘΟΛΟΥ σωστό δρόμο αποστολής. Το
 * `wp_mail()` έφευγε με την PHP `mail()` απευθείας από τον web server, με
 * «From» το προεπιλεγμένο `wordpress@<domain>`, και κανένα DNS record δεν
 * έλεγε στο Gmail ότι αυτός ο server επιτρέπεται να στέλνει για λογαριασμό
 * του domain μας.
 *
 * ΤΙ ΚΑΝΕΙ Ο ΚΩΔΙΚΑΣ ΚΑΙ ΤΙ ΟΧΙ. Ο κώδικας εδώ αποφασίζει ΑΠΟ ΠΟΥ φεύγει το
 * mail και ΜΕ ΤΙ ΤΑΥΤΟΤΗΤΑ. Το αν θα το εμπιστευτεί ο παραλήπτης κρίνεται
 * από SPF/DKIM στο DNS του domain -- δεν γίνεται σε PHP, με κανένα plugin,
 * με κανέναν τρόπο. Χωρίς αυτά τα records το mail θα συνεχίσει να πηγαίνει
 * spam όσο σωστός κι αν είναι ο κώδικας.
 *
 * ΓΙΑΤΙ ΔΕΝ ΟΡΙΖΟΥΜΕ Sender (envelope-from). Μπαίνει ο πειρασμός να μπει το
 * δικό μας domain και στον φάκελο, «για να ταιριάζουν όλα». Είναι παγίδα: το
 * SPF ελέγχεται πάνω στο envelope domain, οπότε αν το ορίσουμε εμείς πριν
 * μπει το SPF record του relay στο DNS, ο έλεγχος περνάει από «άγνωστο» σε
 * ΑΠΟΤΥΧΗΜΕΝΟ -- χειρότερα από πριν. Το αφήνουμε στον relay (βάζει δικό του
 * Return-Path που περνά SPF στο δικό του domain) και η ευθυγράμμιση με το
 * δικό μας «From» γίνεται μέσω DKIM, που είναι ο κανονικός τρόπος.
 *
 * ΓΙΑΤΙ ΟΡΙΖΟΥΜΕ Hostname. Το PHPMailer φτιάχνει το Message-ID από το
 * hostname του server (π.χ. `serverXX.hosting.gr`). Όταν αυτό δεν έχει καμία
 * σχέση με το domain του αποστολέα, είναι σήμα «κάτι δεν πάει καλά εδώ» για
 * τα φίλτρα. Το κλειδώνουμε στο domain του From.
 *
 * ΓΙΑΤΙ ΚΑΤΑΓΡΑΦΟΥΜΕ ΤΙΣ ΑΠΟΤΥΧΙΕΣ. Μέχρι τώρα μια αποτυχία SMTP (λάθος
 * κωδικός, κλειστή θύρα) ήταν εντελώς σιωπηλή: το `wp_mail()` γύριζε false
 * και κανείς δεν το έβλεπε ποτέ. Ο μόνος τρόπος να καταλάβεις αν έφυγε το
 * mail ήταν να ρωτήσεις τον παραλήπτη. Τώρα η αποτυχία φτάνει στο ίδιο
 * μητρώο με τα υπόλοιπα σφάλματα, με το πραγματικό μήνυμα του διακομιστή.
 *
 * ΡΥΘΜΙΣΗ -- στο `wp-config.php`, πριν το «stop editing»:
 *
 *   define('ECRM_SMTP_HOST',       'smtp-relay.brevo.com');
 *   define('ECRM_SMTP_PORT',       587);                    // προεπιλογή 587
 *   define('ECRM_SMTP_USER',       '...');                  // από τον πάροχο
 *   define('ECRM_SMTP_PASS',       '...');                  // από τον πάροχο
 *   define('ECRM_SMTP_SECURE',     'tls');                  // 'tls' | 'ssl' | '' (χωρίς)
 *   define('ECRM_SMTP_FROM_EMAIL', 'no-reply@to-domain-mas.gr');
 *   define('ECRM_SMTP_FROM_NAME',  'Η εταιρεία');
 *   define('ECRM_SMTP_REPLY_TO',   'info@to-domain-mas.gr'); // προαιρετικό
 *
 * Χωρίς `ECRM_SMTP_HOST` η κλάση είναι εντελώς ανενεργή και το `wp_mail()`
 * δουλεύει όπως πριν -- ώστε το τοπικό περιβάλλον (mail catcher της Local)
 * να μη χρειάζεται καμία αλλαγή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

final class SmtpMailer
{
    /**
     * Δευτερόλεπτα αναμονής στον διακομιστή SMTP.
     *
     * Η προεπιλογή του PHPMailer είναι 300 -- πέντε λεπτά. Ένας relay που δεν
     * απαντά θα κρατούσε τον agent να κοιτάει ροδέλα όσο η αίτηση παγώνει.
     * Δέκα δευτερόλεπτα είναι άφθονα για relay που δουλεύει, και η αποτυχία
     * καταγράφεται αντί να κρεμάει.
     */
    private const TIMEOUT = 10;

    public function __construct(private readonly ErrorLog $errors)
    {
    }

    public function register(): void
    {
        // Η καταγραφή αποτυχιών δεν εξαρτάται από δικό μας SMTP: ακόμη κι όταν
        // στέλνει το WordPress μόνο του, μια αποτυχία πρέπει να φαίνεται.
        add_action('wp_mail_failed', [$this, 'recordFailure']);

        if (! self::enabled()) {
            return;
        }

        add_action('phpmailer_init', [$this, 'configure']);
    }

    /** Ενεργό μόνο όταν το site έχει ρητά ορίσει SMTP host στο wp-config.php. */
    public static function enabled(): bool
    {
        return defined('ECRM_SMTP_HOST') && '' !== trim((string) ECRM_SMTP_HOST);
    }

    public function configure(PHPMailer $phpMailer): void
    {
        if (! self::enabled()) {
            return;
        }

        $secure = defined('ECRM_SMTP_SECURE') ? strtolower(trim((string) ECRM_SMTP_SECURE)) : 'tls';
        $user   = defined('ECRM_SMTP_USER') ? (string) ECRM_SMTP_USER : '';
        $pass   = defined('ECRM_SMTP_PASS') ? (string) ECRM_SMTP_PASS : '';

        $phpMailer->isSMTP();
        // enabled() (πάνω) εγγυάται defined('ECRM_SMTP_HOST') κατά την εκτέλεση,
        // αλλά το PHPStan δεν περνάει τη στένωση από μια άλλη μέθοδο -- το ίδιο
        // defined() ξαναγράφεται εδώ, τοπικά, ίδιο μοτίβο με τα υπόλοιπα.
        $phpMailer->Host    = defined('ECRM_SMTP_HOST') ? (string) ECRM_SMTP_HOST : '';
        $phpMailer->Port    = defined('ECRM_SMTP_PORT') ? (int) ECRM_SMTP_PORT : 587;
        $phpMailer->Timeout = self::TIMEOUT;

        // Relay χωρίς αυθεντικοποίηση υπάρχει (π.χ. εσωτερικός διακομιστής της
        // εταιρείας). Το SMTPAuth=true με άδεια στοιχεία θα τον απέρριπτε.
        $phpMailer->SMTPAuth = $user !== '';

        if ($user !== '') {
            $phpMailer->Username = $user;
            $phpMailer->Password = $pass;
        }

        // Κενό σημαίνει «χωρίς κρυπτογράφηση» -- συνειδητή επιλογή, όχι
        // παράλειψη, γι' αυτό δεν πέφτει στο 'tls' από μόνο του.
        $phpMailer->SMTPSecure = in_array($secure, ['tls', 'ssl'], true) ? $secure : '';

        $from = self::fromEmail();

        if ($from === null) {
            return;
        }

        $name = defined('ECRM_SMTP_FROM_NAME') ? trim((string) ECRM_SMTP_FROM_NAME) : '';

        // Το τρίτο όρισμα false: να ΜΗΝ αγγίξει το envelope-from (δες docblock).
        $phpMailer->setFrom($from, $name !== '' ? $name : $from, false);

        $domain = substr($from, (int) strpos($from, '@') + 1);

        if ($domain !== '') {
            $phpMailer->Hostname = $domain;
        }

        if (defined('ECRM_SMTP_REPLY_TO') && is_email((string) ECRM_SMTP_REPLY_TO)) {
            $phpMailer->clearReplyTos();
            $phpMailer->addReplyTo((string) ECRM_SMTP_REPLY_TO);
        }
    }

    /**
     * Η αποτυχία αποστολής, στο μητρώο σφαλμάτων.
     *
     * @param mixed $error Το WordPress δίνει WP_Error· η υπογραφή του hook
     *                     δεν το εγγυάται, οπότε ελέγχεται.
     */
    public function recordFailure($error): void
    {
        if (! $error instanceof WP_Error) {
            return;
        }

        $message = trim($error->get_error_message());

        $this->errors->recordThrowable(
            new \RuntimeException(
                'Αποτυχία αποστολής email: ' . ($message !== '' ? $message : 'χωρίς αιτιολογία από τον διακομιστή.')
            )
        );
    }

    /** Η ρυθμισμένη διεύθυνση αποστολέα, ή null όταν δεν έχει οριστεί έγκυρη. */
    private static function fromEmail(): ?string
    {
        if (! defined('ECRM_SMTP_FROM_EMAIL')) {
            return null;
        }

        $from = trim((string) ECRM_SMTP_FROM_EMAIL);

        return is_email($from) ? $from : null;
    }
}
