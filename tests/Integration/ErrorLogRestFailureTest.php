<?php

/**
 * Το 2.4 στο EKKREMI-29-08.html κατηγορεί 6 από τα 7 σημεία που γυρνάνε 5xx
 * ότι «γυρίζουν σκέτη συμβολοσειρά χωρίς κωδικό και χωρίς log» -- μόνο το
 * DocumentsController.php:172 θεωρήθηκε ότι τηρεί τον κανόνα του ErrorLog.php.
 *
 * AUDIT 29/08, ξανά-μέτρηση: το εύρημα ήταν παρωχημένο. Το
 * `ErrorLog::catchRestFailure()` είναι κουμπωμένο στο `rest_post_dispatch`
 * ΜΙΑ φορά, global, στο Plugin::boot() -- όχι per-controller. Πιάνει *κάθε*
 * `WP_REST_Response` με status >=500 σε route κάτω από `/ecrm/v1`,
 * ανεξάρτητα ποιος controller τη γύρισε, και ξαναγράφει το `error` με κωδικό
 * αναφοράς πριν φύγει από τον server. Και τα 7 σημεία (βρέθηκε κι ένα 7ο,
 * TasksController.php:134, που δεν ήταν στη λίστα το audit) γυρνένε ήδη
 * `new WP_REST_Response([...], 500)` -- ακριβώς τθ ?? σχήμα πουχρειάζεται το
 * φίλτρο. Κανένα δεν γυρνάει WP_Error (θα προσπερνούσε το φίλτρο).
 *
 * Επιβεβαιώθηκε πρώτα χειροκίνητα με tools/measure-error-log-hook.php (και
 * τα 7 πήραν κωδικό ECRM-XXXX) πριν γραφτεί αυτό το test -- «Μέτρα. Μη το
 * υποθέτεις.» ισχύει και όταν η μέτρηση δείχνει ότι δεν υπάρχει bug.
 *
 * Αυτό το test κλειδώνει δύο πράγματα ώστε η επόμενη φορά που κάποιος
 * αγγίξει το Plugin::boot() ή το ErrorLog να μη σπάσει αθόρυβα: (1) το hook
 * είναι πράγματι κουμπωμένο μετά το boot, (2) η ίδια η μέθοδος καλύπτει και
 * τα 7 πραγματικά σχήματα payload, όχι μόνο ένα ιδεατό.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\ErrorLog;
use WP_REST_Request;
use WP_REST_Response;

final class ErrorLogRestFailureTest extends IntegrationTestCase
{
    /**
     * Το Plugin::boot() (φορτωμένο μια φορά για όλο το test run) το κούμπωσε
     * ήδη -- εδώ το επιβεβαιώνουμε ψάχνοντας το πραγματικό instance μέσα στο
     * $wp_filter, όχι με has_filter([νέο instance, 'method']) που θα
     * απέτυχε πάντα (διαφορετικό αντικείμενο από αυτό που κούμπωσε το boot).
     */
    public function testTheHookIsWiredGloballyNotPerController(): void
    {
        global $wp_filter;

        $wired = false;

        foreach ($wp_filter['rest_post_dispatch']->callbacks ?? [] as $atPriority) {
            foreach ($atPriority as $registered) {
                $fn = $registered['function'];

                if (is_array($fn) && ($fn[0] ?? null) instanceof ErrorLog && ($fn[1] ?? null) === 'catchRestFailure') {
                    $wired = true;
                    break 2;
                }
            }
        }

        self::assertTrue(
            $wired,
            'catchRestFailure() πρέπει να είναι κουμπωμένο στο rest_post_dispatch από το Plugin::boot(), ' .
            'όχι επαναλαμβανόμενα σε κάθε controller.'
        );
    }

    /**
     * Το ίδιο σχήμα response που φτιάχνει το καθένα από τα 7 σημεία σήμερα.
     * Μια αλλαγή που ξεφεύγει από αυτό το σχήμα (π.χ. WP_Error αντί για
     * WP_REST_Response) θα προσπερνούσε το φίλτρο σιωπηλά -- αυτό το test
     * θα το έπιανε μόνο αν κάποιο από αυτά αλλάξει σχήμα, όχι νέο endpoint.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public static function realFivehundredShapes(): array
    {
        return [
            ['ContractDocumentsController.php:121', ['ok' => false, 'error' => 'Σφάλμα δημιουργίας PDF.']],
            ['ContractSaveController.php:205', ['ok' => false, 'error' => 'Η αποθήκευση απέτυχε.']],
            ['LeadsController.php:147', ['ok' => false, 'id' => 0]],
            ['LeadsController.php:216', ['ok' => false, 'error' => 'Η μετατροπή απέτυχε.']],
            ['PayoutsController.php:111', ['ok' => false, 'error' => 'Σφάλμα δημιουργίας PDF.']],
            ['RenewalsController.php:154', ['ok' => false, 'error' => 'Η ανανέωση απέτυχε.']],
            ['TasksController.php:134', ['ok' => false, 'id' => 0]],
        ];
    }

    /**
     * @dataProvider realFivehundredShapes
     *
     * @param array<string, mixed> $data
     */
    public function testEveryKnownFivehundredShapeGetsAReferenceCode(string $where, array $data): void
    {
        $request  = new WP_REST_Request('POST', '/ecrm/v1/measure-2-4');
        $response = new WP_REST_Response($data, 500);

        $after = self::errorLog()->catchRestFailure($response, null, $request)->get_data();

        self::assertNotEmpty($after['code'] ?? '', "{$where}: δεν πήρε κωδικό αναφοράς.");
        self::assertStringContainsString(
            (string) $after['code'],
            (string) ($after['error'] ?? ''),
            "{$where}: ο κωδικός πρέπει να φαίνεται στο μήνυμα που βλέπει ο χρήστης."
        );
    }

    /** A route outside ecrm/v1 is not ours to rewrite. */
    public function testARouteOutsideOurNamespaceIsLeftAlone(): void
    {
        $request  = new WP_REST_Request('POST', '/wp/v2/posts');
        $response = new WP_REST_Response(['message' => 'κάτι άλλο'], 500);

        $after = self::errorLog()->catchRestFailure($response, null, $request)->get_data();

        self::assertSame('κάτι άλλο', $after['message']);
        self::assertArrayNotHasKey('code', $after);
    }

    private static function errorLog(): ErrorLog
    {
        static $log = null;

        return $log ??= new ErrorLog();
    }
}
