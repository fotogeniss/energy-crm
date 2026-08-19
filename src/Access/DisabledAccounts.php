<?php

/**
 * Ο απενεργοποιημένος λογαριασμός δεν μπαίνει, και δεν μένει μέσα.
 *
 * Ο διαχειριστής βλέπει στην ομάδα του διακόπτη «ενεργός/ανενεργός» και κουμπί
 * «Αφαίρεση». Και τα δύο έγραφαν `ecrm_disabled` στα meta του χρήστη — και
 * τίποτα δεν το διάβαζε. Ολόκληρο το plugin είχε **έξι** αναφορές στη σημαία:
 * δύο που τη γράφουν, δύο που τη διαβάζουν για να τη **ζωγραφίσουν** στην
 * οθόνη, και τον ορισμό της. Κανένα φίλτρο `authenticate`, κανένας έλεγχος σε
 * `permission_callback`.
 *
 * Δηλαδή ο συνεργάτης που έφευγε για ανταγωνιστή, και τον οποίο ο διαχειριστής
 * «αφαιρούσε», συνέχιζε να συνδέεται με τον ίδιο κωδικό, να βλέπει ΑΦΜ, ΑΔΤ και
 * σαρωμένες ταυτότητες πελατών, και να τραβά εξαγωγή σε Excel. Το σχόλιο της
 * `TeamRepository::detach()` έλεγε «δεν μπορεί πλέον να συνδεθεί» και ήταν απλώς
 * αναληθές.
 *
 * ## Γιατί `user_has_cap` και όχι έλεγχος στους Guards
 *
 * Ο προφανής δρόμος ήταν μια γραμμή στον `Guards::crmUser()`. Θα κάλυπτε τις
 * διαδρομές REST του `src/` και **μόνο** αυτές: το `includes/` και το `admin/`
 * έχουν δικά τους `current_user_can()`, δεκαεπτά χειριστές `admin_post_*`, και
 * ο επόμενος που θα γραφτεί δεν θα ξέρει ότι υπάρχει κανόνας να τηρήσει.
 *
 * Το `user_has_cap` απαντά μία φορά για όλους: όσο ο λογαριασμός είναι
 * απενεργοποιημένος, **κανένα** δικαίωμα `ecrm_*` δεν ισχύει. Κάθε
 * `permission_callback`, κάθε οθόνη, κάθε παλιά κλάση και κάθε μελλοντική
 * υπακούει χωρίς να το ξέρει. Ο κανόνας γίνεται ιδιότητα του χρήστη αντί για
 * έλεγχο που πρέπει να θυμηθεί ο καθένας — το ίδιο σχήμα με τον `ScopeClause`.
 *
 * Το `authenticate` μπαίνει από πάνω για τη δεύτερη μισή δουλειά: το πρώτο
 * φίλτρο αδειάζει τα δικαιώματα κάποιου που είναι **ήδη μέσα**, το δεύτερο δεν
 * τον αφήνει να ξαναμπεί. Το ένα χωρίς το άλλο αφήνει ακριβώς την περίπτωση που
 * μας ενδιαφέρει: τον άνθρωπο που απενεργοποιήθηκε ενώ ήταν συνδεδεμένος.
 *
 * ## Ο διαχειριστής δεν κλειδώνεται ποτέ έξω
 *
 * Χρήστης με `manage_options` εξαιρείται, πάντα. Χωρίς αυτό, ένα λάθος κλικ σε
 * λογαριασμό διαχειριστή είναι μη αναστρέψιμο: το ξεκλείδωμα απαιτεί ακριβώς τα
 * δικαιώματα που μόλις χάθηκαν, και δεν υπάρχει δεύτερη πόρτα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use EnergyCRM\Persistence\TeamRepository;
use WP_Error;
use WP_User;

final class DisabledAccounts
{
    /** Το ίδιο μήνυμα στη σύνδεση και παντού αλλού: είναι το ίδιο γεγονός. */
    public const REFUSED = 'Ο λογαριασμός σου έχει απενεργοποιηθεί. Επικοινώνησε με τον υπεύθυνό σου.';

    public function __construct(private readonly TeamRepository $team)
    {
    }

    public function register(): void
    {
        // Προτεραιότητα 30: μετά τους ελέγχους ονόματος/κωδικού του WordPress
        // (20), ώστε να αρνούμαστε λογαριασμό που αλλιώς θα έμπαινε — και όχι
        // να λέμε «απενεργοποιημένος» σε κάποιον που έγραψε λάθος κωδικό.
        add_filter('authenticate', [$this, 'refuseLogin'], 30);
        add_filter('user_has_cap', [$this, 'stripCapabilities'], 10, 4);
    }

    /**
     * Άρνηση σύνδεσης, ή ό,τι έδωσε το WordPress αν δεν μας αφορά.
     *
     * @param WP_User|WP_Error|null $user
     *
     * @return WP_User|WP_Error|null
     */
    public function refuseLogin($user)
    {
        if (! $user instanceof WP_User) {
            return $user;
        }

        if (user_can($user, 'manage_options') || ! $this->isDisabled((int) $user->ID)) {
            return $user;
        }

        return new WP_Error('ecrm_account_disabled', self::REFUSED);
    }

    /**
     * Κανένα δικαίωμα του CRM για απενεργοποιημένο λογαριασμό.
     *
     * Ο έλεγχος του διαχειριστή γίνεται πάνω στον ίδιο τον πίνακα `$allcaps`
     * και όχι με `user_can()`: το `user_can()` μέσα σε αυτό το φίλτρο θα
     * ξανακαλούσε το ίδιο φίλτρο.
     *
     * Τα υπόλοιπα δικαιώματα του WordPress μένουν άθικτα. Δεν είναι δουλειά
     * αυτού του plugin να αποφασίσει τι άλλο κάνει ο χρήστης στο site· η σημαία
     * λέει «εκτός CRM», όχι «εκτός WordPress».
     *
     * @param array<string, bool> $allcaps
     * @param array<int, string>  $caps
     * @param array<int, mixed>   $args
     * @param WP_User             $user
     *
     * @return array<string, bool>
     */
    public function stripCapabilities(array $allcaps, array $caps, array $args, $user): array
    {
        unset($caps, $args);

        if (! $user instanceof WP_User || ! empty($allcaps['manage_options'])) {
            return $allcaps;
        }

        if (! $this->isDisabled((int) $user->ID)) {
            return $allcaps;
        }

        foreach (Capability::all() as $capability) {
            $allcaps[$capability] = false;
        }

        return $allcaps;
    }

    /**
     * Χωρίς δική μας μνήμη, επίτηδες.
     *
     * Το `user_has_cap` τρέχει δεκάδες φορές σε μία σελίδα, οπότε ο πειρασμός
     * να κρατηθεί η απάντηση είναι προφανής. Δεν κρατιέται: το `get_user_meta`
     * περνά ήδη από την cache αντικειμένων του WordPress, που **ακυρώνεται**
     * όταν γραφτεί το meta. Δικός μας πίνακας δεν θα ακυρωνόταν, και το αίτημα
     * που απενεργοποιεί κάποιον θα συνέχιζε να τον βλέπει ενεργό ως το τέλος
     * του — δηλαδή ακριβώς το αίτημα όπου έχει σημασία.
     */
    private function isDisabled(int $userId): bool
    {
        return $userId > 0 && $this->team->isDisabled($userId);
    }
}
