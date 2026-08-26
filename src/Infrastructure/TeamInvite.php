<?php

/**
 * Email πρόσκλησης για νέο μέλος ομάδας — build queue 13.
 *
 * ## Τι άλλαζε, και γιατί
 *
 * Ως τις 25/08/2026 το `TeamController::create()` έφτιαχνε τον λογαριασμό
 * και γύρναγε τον κωδικό ΣΕ ΚΑΘΑΡΟ ΚΕΙΜΕΝΟ στην απάντηση REST, για να τον
 * αντιγράψει ο manager και να τον πει προφορικά/γραπτά στο νέο μέλος. Ο
 * κωδικός περνούσε λοιπόν από το δίκτυο, το JS, ίσως ένα Viber/SMS του
 * manager — τρία σημεία διαρροής για κάτι που δεν χρειαζόταν να υπάρξει ως
 * αλυσίδα κειμένου καθόλου.
 *
 * Η λύση είναι αυτή που ήδη ξέρει το ίδιο το WordPress: `get_password_reset_key()`
 * (η ίδια συνάρτηση πίσω από το «Ξέχασα τον κωδικό μου») φτιάχνει ένα
 * μονόδρομο token, αποθηκευμένο hashed στη βάση, που λήγει μόνο του
 * (`password_reset_expiration`, προεπιλογή 24 ώρες — δεν έχει αλλάξει εδώ).
 * Ο κωδικός λογαριασμού γεννιέται τυχαίος και δεν τον βλέπει ΚΑΝΕΙΣ ποτέ — το
 * μέλος τον ορίζει μόνο του μέσω wp-login.php?action=rp, την ίδια οθόνη που
 * θα έβλεπε ούτως ή άλλως αν πατούσε «Ξέχασα τον κωδικό».
 *
 * ## Γιατί δεν είναι απλό `wp_mail()` κλήση μέσα στον controller
 *
 * Το ίδιο σχήμα (πάρε key, φτιάξε link, στείλε email με τα σωστά brand
 * στοιχεία) χρειάζεται να δοκιμαστεί χωρίς να χτίζεται ολόκληρο το
 * TeamController — και η αποστολή email σε production χωρίς SMTP δεν
 * επιστρέφει ποτέ true σε τοπικό dev, οπότε το `send()` επιστρέφει `bool`
 * ρητά ώστε ο καλών να ξέρει αν πραγματικά έφυγε, αντί να υποθέσει «σίγουρα
 * πήγε» επειδή δεν πέταξε εξαίρεση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use ECRM_Admin;
use WP_User;

final class TeamInvite
{
    /**
     * Στέλνει το email πρόσκλησης σε έναν μόλις δημιουργημένο χρήστη.
     *
     * `false` σημαίνει «ο λογαριασμός υπάρχει, το email όχι» — ο καλών πρέπει
     * να το πει στον manager ρητά, ποτέ σιωπηλή «επιτυχία».
     */
    public function send(int $userId, string $displayName): bool
    {
        $user = get_userdata($userId);

        if (! $user instanceof WP_User || ! is_email($user->user_email)) {
            return false;
        }

        $key = get_password_reset_key($user);

        if (is_wp_error($key)) {
            return false;
        }

        $link = site_url(
            'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode($user->user_login),
            'login'
        );

        $company = class_exists(ECRM_Admin::class)
            ? (string) ECRM_Admin::get('company_name', get_bloginfo('name'))
            : get_bloginfo('name');

        $name    = $displayName !== '' ? $displayName : $user->display_name;
        $subject = sprintf('Πρόσκληση στο CRM — %s', $company);
        $body    = sprintf(
            "Καλωσόρισες %s,\n\n"
                . "Προστέθηκες στην ομάδα του %s στο Energy CRM.\n\n"
                . "Όρισε τον κωδικό πρόσβασής σου από τον παρακάτω σύνδεσμο "
                . "(ισχύει 24 ώρες, μετά ζήτα νέα πρόσκληση):\n%s\n\n%s",
            $name,
            $company,
            $link,
            $company
        );

        return wp_mail($user->user_email, $subject, $body);
    }
}
