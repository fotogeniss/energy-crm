<?php

/**
 * Η συνδεση σταματαει να δεχεται προσπαθειες οταν γινονται επιθεση.
 *
 * Το plugin ειχε ηδη rate limiter (ECRM_RateLimit) και τον χρησιμοποιουσε σε
 * ολα τα ακριβα σημεια: αναζητηση ΑΦΜ στο VIES, αποστολη SMS, εξαγωγη
 * στοιχειων, assistant. Σε ενα σημειο δεν τον χρησιμοποιουσε πουθενα: στην
 * ιδια τη συνδεση. Κανενα hook δεν αγγιζε το wp_login_failed, οποτε ουτε
 * σταματουσε καποιος τις δοκιμες ουτε τις **εβλεπε** καποιος -- μια επιθεση
 * χιλιαδων προσπαθειων περνουσε χωρις να αφησει ιχνος πουθενα.
 *
 * ## Γιατι προτεραιοτητα 10 και οχι 30 οπως το DisabledAccounts
 *
 * Το DisabledAccounts μπαινει **μετα** (30) τους ελεγχους του WordPress,
 * επιτηδες: δεν θελει να πει «απενεργοποιημενος» σε καποιον που απλως εγραψε
 * λαθος κωδικο. Εδω ισχυει το αντιθετο. Ο ελεγχος κωδικου ειναι σκοπιμα
 * ακριβος (hashing), και ειναι ακριβως αυτο που θελει να καταναλωσει ο
 * επιτιθεμενος. Μπαινοντας στο 10, πριν το wp_authenticate_username_password
 * (20), η κλειδωμενη προσπαθεια κοστιζει δυο αναγνωσεις transient αντι για
 * εναν πληρη ελεγχο κωδικου.
 *
 * ## Γιατι ΔΕΝ εξαιρειται ο διαχειριστης
 *
 * Το DisabledAccounts εξαιρει ρητα τον manage_options, και ο λογος του ειναι
 * σωστος εκει: το κλειδωμα ειναι **μη αναστρεψιμο** -- το ξεκλειδωμα θελει
 * ακριβως τα δικαιωματα που μολις χαθηκαν. Εδω το κλειδωμα ληγει μονο του σε
 * ενα τεταρτο. Και η εξαιρεση θα κοστιζε: για να ξερουμε αν ενα ονομα ανηκει
 * σε διαχειριστη πρεπει να το ψαξουμε, και τοτε η διαφορετικη συμπεριφορα
 * απανταει στον επιτιθεμενο «αυτο το ονομα υπαρχει, και ειναι ο admin».
 *
 * Για τον ιδιο λογο μετρανε και οι αποτυχιες σε **ανυπαρκτα** ονοματα: αν
 * κλειδωναν μονο τα υπαρκτα, το ιδιο το κλειδωμα θα ηταν ο καταλογος των
 * λογαριασμων.
 *
 * ## Τι αγγιζει και τι οχι
 *
 * Ισχυει για καθε διαδρομη που περναει απο wp_authenticate -- και τη φορμα
 * του wp-login.php και τα Application Passwords του REST. Ειναι σωστο (και τα
 * δυο δοκιμαζονται με τον ιδιο τροπο), αλλα αξιζει να το ξερει οποιος ξανα-
 * τρεξει το tools/load-test-appwd.php: οι **επιτυχημενες** συνδεσεις δεν
 * μετρανε ποτε, μονο οι αποτυχιες.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use EnergyCRM\Domain\Access\LoginAttempts;
use EnergyCRM\Infrastructure\Metrics;
use EnergyCRM\Infrastructure\RequestIp;
use WP_Error;
use WP_User;

final class LoginThrottle
{
    /**
     * Το προθεμα των μετρητων, χωριστο απο το ecrm_rl_ του ECRM_RateLimit.
     *
     * Ιδιο σχημα τιμης με εκεινον (`c` = πληθος, `r` = ποτε ληγει), ωστε ο
     * επομενος που θα δει τα δυο μαζι να μη χρειαστει να μαθει δυο πραγματα.
     */
    private const PREFIX = 'ecrm_lf_';

    /** Το ιδιο μηνυμα σε καθε περιπτωση: δεν λεει ποιος μετρητης κλειδωσε. */
    private const REFUSED = 'Πάρα πολλές αποτυχημένες προσπάθειες σύνδεσης. Δοκίμασε ξανά %s.';

    public function register(): void
    {
        add_filter('authenticate', [$this, 'refuseWhenLocked'], 10, 2);
        add_action('wp_login_failed', [$this, 'recordFailure'], 10, 1);
        add_action('wp_login', [$this, 'forgetUser'], 10, 1);
    }

    /**
     * Αρνηση πριν καν ελεγχθει ο κωδικος, οταν καποιος μετρητης εχει κλεισει.
     *
     * @param WP_User|WP_Error|null $user
     * @param mixed                 $username
     *
     * @return WP_User|WP_Error|null
     */
    public function refuseWhenLocked($user, $username = '')
    {
        $wait = LoginAttempts::waitSeconds($this->buckets($this->name($username)), time());

        if ($wait === 0) {
            return $user;
        }

        Metrics::bump(Metrics::LOGIN_BLOCKED);

        return new WP_Error(
            'ecrm_login_throttled',
            sprintf(self::REFUSED, LoginAttempts::waitPhrase($wait))
        );
    }

    /**
     * Μια αποτυχια, μετρημενη και στους δυο κουβαδες.
     *
     * Το παραθυρο ξεκιναει απο την **πρωτη** αποτυχια και δεν ανανεωνεται στις
     * επομενες: αλλιως ο επιτιθεμενος που δοκιμαζει συνεχεια θα κρατουσε τον
     * εαυτο του κλειδωμενο για παντα, που ακουγεται καλο μεχρι να συμβει σε
     * κοινη IP γραφειου.
     *
     * Η πρωτη γραμμη ειναι εκει επειδη το WordPress πυροδοτει το
     * wp_login_failed **και** για τις προσπαθειες που κοψαμε ηδη στο
     * refuseWhenLocked: το WP_Error μας ειναι αποτυχια σαν καθε αλλη για το
     * wp_signon(). Χωρις αυτη, καθε μπλοκαρισμενη προσπαθεια θα μετριοταν δυο
     * φορες -- μια ως «μπλοκαρισμενη», μια ως «αποτυχια» -- και θα εγραφε
     * ξανα στη βαση κατι που ειχε ηδη γραφτει. Ετσι καθε προσπαθεια μετραει
     * ακριβως μια φορα, στον σωστο μετρητη.
     *
     * @param mixed $username
     */
    public function recordFailure($username): void
    {
        $name = $this->name($username);

        if (LoginAttempts::waitSeconds($this->buckets($name), time()) > 0) {
            return;
        }

        Metrics::bump(Metrics::LOGIN_FAILED);

        foreach ($this->keys($name) as $key) {
            $this->bump($key);
        }
    }

    /**
     * Μετα απο επιτυχημενη συνδεση, ο μετρητης του ονοματος μηδενιζεται.
     *
     * Ο μετρητης της IP **δεν** μηδενιζεται, και αυτο δεν ειναι παραλειψη:
     * καποιος με εναν εγκυρο λογαριασμο θα καθαριζε τη διευθυνση του καθε
     * λιγα λεπτα και θα σαρωνε τα υπολοιπα ονοματα ανενοχλητος.
     *
     * @param mixed $username
     */
    public function forgetUser($username): void
    {
        $name = $this->name($username);

        if ($name === '') {
            return;
        }

        delete_transient(self::PREFIX . 'u_' . md5($name));
    }

    /**
     * Το ονομα χρηστη σε μορφη που μπορει να γινει κλειδι, ή '' αν δεν υπαρχει.
     *
     * Δεν δηλωνεται `string` στις τρεις δημοσιες μεθοδους επιτηδες: τα hooks
     * του WordPress τα καλει και κωδικας τριτων, και με `declare(strict_types=1)`
     * ενα `null` που περνα καποιος αλλος θα ηταν TypeError μεσα στη ροη
     * συνδεσης -- δηλαδη κλειδωμενη πορτα για ολους. Ιδιο σκεπτικο με το
     * `DisabledAccounts::refuseLogin()`, που δεχεται το ορισμα ατυπο και
     * ελεγχει το ιδιο.
     */
    private function name(mixed $username): string
    {
        return is_string($username) ? strtolower($username) : '';
    }

    /**
     * Οι δυο μετρητες σε μορφη που καταλαβαινει το Domain.
     *
     * @return list<array{int, int, int}>
     */
    private function buckets(string $username): array
    {
        $out = [];

        foreach ($this->keys($username) as $kind => $key) {
            $record = get_transient($key);

            if (! is_array($record)) {
                continue;
            }

            $out[] = [
                (int) ($record['c'] ?? 0),
                $kind === 'u' ? LoginAttempts::PER_USER : LoginAttempts::PER_IP,
                (int) ($record['r'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Τα κλειδια των δυο κουβαδων για αυτη την αιτηση.
     *
     * Λειπει οποιος δεν εχει νοημα: κενο ονομα χρηστη (κενη φορμα) δεν εχει
     * δικο του μετρητη, και **κενη διευθυνση** επισης οχι -- ο RequestIp
     * επιστρεφει '' οταν δεν υπαρχει χρησιμοποιησιμη τιμη, και ενας κοινος
     * κουβας «οσοι δεν εχουν IP» θα κλειδωνε ολους μαζι.
     *
     * Το md5 εδω δεν ειναι κρυπτογραφια, ειναι κλειδι cache σταθερου μηκους
     * και χαρακτηρων -- ιδιο μοτιβο με το ECRM_RateLimit για την IP. Τα
     * transient keys εχουν οριο μηκους και ενα ονομα χρηστη δεν το σεβεται.
     *
     * @return array<string, string>
     */
    private function keys(string $username): array
    {
        $out = [];
        $ip  = RequestIp::current();

        if ($username !== '') {
            $out['u'] = self::PREFIX . 'u_' . md5($username);
        }

        if ($ip !== '') {
            $out['i'] = self::PREFIX . 'i_' . md5($ip);
        }

        return $out;
    }

    /** Μια μοναδα πανω στον μετρητη, χωρις να μετακινηθει η ληξη του. */
    private function bump(string $key): void
    {
        $now    = time();
        $record = get_transient($key);

        if (! is_array($record) || $now > (int) ($record['r'] ?? 0)) {
            set_transient($key, ['c' => 1, 'r' => $now + LoginAttempts::WINDOW], LoginAttempts::WINDOW);

            return;
        }

        $record['c'] = (int) ($record['c'] ?? 0) + 1;

        set_transient($key, $record, max(1, (int) $record['r'] - $now));
    }
}
