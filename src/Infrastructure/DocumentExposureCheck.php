<?php

/**
 * Η δοκιμη «ειναι ο φακελος εγγραφων ανοιχτος;» δεν περιμενει πια κανεναν να
 * ανοιξει την Υγεια.
 *
 * Η HealthChecks::documentsAreExposed() υπηρχε ηδη απο το (29) και δουλευει
 * σωστα -- γραφει αβλαβες αρχειο, ζητα το URL του, βλεπει αν απαντηθηκε. Το
 * κενο δεν ηταν ο ελεγχος, ηταν το ποτε τρεχει: μονο οταν καποιος ανοιξει
 * χειροκινητα τη σελιδα. Ακριβως το ιδιο σχημα που περιγραφει το docblock του
 * DocumentProtection -- «κανεις δεν πατα κουμπι που δεν του ειπε κανεις οτι
 * υπαρχει» -- ισχυε και εδω, μονο που το «κουμπι» ηταν ολοκληρη η σελιδα.
 *
 * Αν ποτε αλλαξει server (nginx -> κατι αλλο, νεο deployment που ξεχασε τον
 * κανονα) ο φακελος μενει δημοσιος μεχρι καποιος τυχαια να κοιταξει -- και
 * μεσα του ειναι σαρωμενες ταυτοτητες και ΑΦΜ.
 *
 * ## Γιατι ημερησιο και οχι ωριαιο
 *
 * Το DocumentProtection τρεχει ωριαια επειδη μετακινει backlog -- δουλεια που
 * θελει να προχωραει συνεχεια. Εδω δεν υπαρχει backlog, υπαρχει μια ναι/οχι
 * ερωτηση για ρυθμιση server που δεν αλλαζει απο μονη της παρα μονο σε
 * deployment. Ιδιο ρυθμο με το Retention: μια φορα την ημερα αρκει, και δεν
 * χρειαζεται να ανταγωνιστει request που εξυπηρετουν συνεργατες.
 *
 * ## Γιατι email σε καθε τρεξιμο που βρισκει ανοιχτο, οχι μια φορα
 *
 * Το ErrorLog στελνει ενα email ανα ωρα το πολυ επειδη ενας βρογχος μπορει να
 * παραγει δεκαδες σφαλματα μεσα σε δευτερολεπτα -- το throttle υπαρχει για να
 * μην γινει βομβα στο inbox. Εδω το cron τρεχει μια φορα την ημερα, οποτε το
 * ιδιο το προγραμμα ειναι ηδη το throttle: αν μεινει ανοιχτο, ο διαχειριστης
 * το ξαναμαθαινει καθε μερα μεχρι να διορθωθει, οχι μια φορα και μετα σιωπη.
 * Για εκθεση σαρωμενων ταυτοτητων η επαναληψη ειναι το σωστο -- η σιωπη ειναι
 * το ιδιο προβλημα που αυτη η κλαση φτιαχνει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class DocumentExposureCheck
{
    public const HOOK = 'ecrm_check_document_exposure';

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'onScheduledCheck']);

        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 2 * MINUTE_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Cron entry point. Καμια επιστρεφομενη τιμη -- ο,τι αξιζει να μαθευτει
     * παει σε email και σε μετρητη, οχι σε return value που το WordPress
     * πεταει.
     */
    public function onScheduledCheck(): void
    {
        Heartbeat::mark(self::HOOK);

        $exposed = HealthChecks::documentsAreExposed();

        if ($exposed !== true) {
            // false: κλειστος, οπως πρεπει. null: το site δεν εφτασε τον
            // εαυτο του -- ουτε τα δυο ειναι κατι να ειδοποιηθει κανεις.
            return;
        }

        Metrics::bump(Metrics::DOCS_EXPOSED);
        $this->notifyAdmin();
    }

    /**
     * Ιδιο pattern με ErrorLog::notifyAdmin(): admin_email, χωρις να δειχνει
     * περιεχομενο εγγραφου -- μονο το γεγονος και τι να κανει.
     */
    private function notifyAdmin(): void
    {
        $to = get_option('admin_email');

        if (! is_string($to) || $to === '') {
            return;
        }

        $subject = '[Energy CRM] Ο φάκελος εγγράφων είναι δημόσια προσβάσιμος';

        $body = "Ο ημερήσιος έλεγχος βρήκε τον φάκελο εγγράφων (uploads/ecrm-secure) "
            . "προσβάσιμο απευθείας από το web -- σαρωμένες ταυτότητες και ΑΦΜ πελατών "
            . "μπορεί να είναι ένα μαντεμένο URL μακριά από άγνωστο.\n\n"
            . "Συνήθης αιτία: ο server άλλαξε (νέο deployment, νέος nginx) και ο κανόνας "
            . "αποκλεισμού δεν ξαναμπήκε.\n\n"
            . "Λεπτομέρειες και ο ακριβής κανόνας nginx στην Υγεία (Energy CRM → Υγεία, "
            . "ενότητα «Έγγραφα»). Αυτό το email θα ξανασταλεί κάθε μέρα όσο ο φάκελος "
            . "παραμένει ανοιχτός.";

        wp_mail($to, $subject, $body);
    }
}
