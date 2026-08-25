<?php

/**
 * Η προτίμηση εμφάνισης: αποθήκευση, ανάγνωση, και ότι φτάνει στο markup.
 *
 * Το τελευταίο είναι ο λόγος που υπάρχει αυτό το αρχείο. Η αποθήκευση σε user
 * meta είναι δύο γραμμές που δύσκολα σπάνε· εκείνο που σπάει σιωπηλά είναι η
 * διαδρομή **από** τη βάση **στο** `data-theme` του `<div class="ecrm">`. Αν
 * κοπεί, η προτίμηση σώζεται κανονικά, το REST απαντά σωστά, και ο χρήστης
 * απλώς βλέπει πάντα ανοιχτό — μια αποτυχία που καμία δοκιμή αποθήκευσης δεν
 * βλέπει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_App;
use EnergyCRM\Infrastructure\ThemePreference;
use WP_REST_Request;
use WP_REST_Response;

final class ThemePreferenceTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/theme';

    protected function tearDown(): void
    {
        // Ο τρέχων χρήστης είναι καθολικός· αν μείνει, αποφασίζει την επόμενη.
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * 2026-08-25: η προεπιλογή έγινε σκούρο (HANDOVER §6γ, item 8,
     * `docs/UI-DARK-DEFAULT.html`) — το `assertSame` εδώ ρωτά τη σταθερά
     * `ThemePreference::FALLBACK`, όχι το literal `DARK`, ώστε μια
     * μελλοντική αλλαγή προεπιλογής να ξαναγράψει μόνο ΕΝΑ σημείο (την
     * ίδια τη σταθερά) και όχι και αυτό το test.
     */
    public function testTheDefaultIsTheFallbackForSomeoneWhoNeverChose(): void
    {
        $user = $this->makeCrmUser();

        self::assertSame(
            ThemePreference::FALLBACK,
            ThemePreference::forUser($user),
            'Όποιος δεν διάλεξε πρέπει να βλέπει την τρέχουσα προεπιλογή.'
        );
    }

    public function testAChosenThemeIsStoredAndReadBack(): void
    {
        $user = $this->makeCrmUser();
        wp_set_current_user($user);

        $response = $this->post(['theme' => ThemePreference::DARK]);

        self::assertSame(200, $response->get_status());
        self::assertSame(ThemePreference::DARK, $response->get_data()['theme']);
        self::assertSame(ThemePreference::DARK, ThemePreference::forUser($user));
    }

    /**
     * Το `enum` της διαδρομής κόβει πριν φτάσει στον controller.
     *
     * Ελέγχεται και ότι η ήδη αποθηκευμένη τιμή ΔΕΝ πειράχτηκε: μια απόρριψη
     * που σβήνει την προηγούμενη επιλογή είναι χειρότερη από καμία απόρριψη.
     */
    public function testAnUnknownThemeIsRefusedAndTheStoredOneSurvives(): void
    {
        $user = $this->makeCrmUser();
        wp_set_current_user($user);
        ThemePreference::save($user, ThemePreference::DARK);

        self::assertSame(400, $this->post(['theme' => 'neon'])->get_status());
        self::assertSame(ThemePreference::DARK, ThemePreference::forUser($user));
    }

    public function testTheThemeIsNotReadableOrWritableWithoutLogin(): void
    {
        wp_set_current_user(0);

        self::assertSame(401, rest_do_request(new WP_REST_Request('GET', self::ROUTE))->get_status());
        self::assertSame(401, $this->post(['theme' => ThemePreference::DARK])->get_status());
    }

    /**
     * Η μία δοκιμή που δικαιολογεί το αρχείο.
     *
     * Το κέλυφος πρέπει να τυπώσει το `data-theme` μέσα στο ίδιο το markup —
     * όχι να το γράψει JS μετά — αλλιώς η σελίδα ανάβει λευκή και μετά
     * σκουραίνει σε κάθε πλοήγηση.
     */
    public function testTheShellPrintsTheStoredThemeIntoTheMarkup(): void
    {
        $user = $this->makeCrmUser();
        wp_set_current_user($user);

        // Η προεπιλογή είναι όποια είναι σήμερα η ThemePreference::FALLBACK
        // (σκούρο, 2026-08-25) — το «άλλο» υπολογίζεται, δεν κοπιάζεται, ώστε
        // το test να παραμείνει σωστό αν η προεπιλογή ξαναλλάξει.
        $other = ThemePreference::FALLBACK === ThemePreference::LIGHT
            ? ThemePreference::DARK
            : ThemePreference::LIGHT;

        self::assertStringContainsString(
            'data-theme="' . ThemePreference::FALLBACK . '"',
            ECRM_App::render(),
            'Το κέλυφος δεν τύπωσε καθόλου data-theme.'
        );

        ThemePreference::save($user, $other);
        $rendered = ECRM_App::render();

        self::assertStringContainsString('data-theme="' . $other . '"', $rendered);
        self::assertStringNotContainsString(
            'data-theme="' . ThemePreference::FALLBACK . '"',
            $rendered,
            'Δύο data-theme στο ίδιο markup σημαίνει ότι κάποιος γράφει σταθερή τιμή.'
        );
    }

    /** Μία τιμή ανά άνθρωπο — αλλιώς δεν είναι προτίμηση, είναι ρύθμιση. */
    public function testOneUsersChoiceDoesNotTouchAnother(): void
    {
        $alice = $this->makeCrmUser();
        $bob   = $this->makeCrmUser();

        // Η Άλις διαλέγει ρητά το ΑΝΤΙΘΕΤΟ της προεπιλογής — αλλιώς, αν η
        // προεπιλογή είναι πια σκούρο και η Άλις «διάλεγε» σκούρο, το test
        // δεν θα απεδείκνυε τίποτα: ο Μπομπ θα έβγαινε σκούρο είτε
        // απομονώνονταν σωστά οι προτιμήσεις είτε όχι.
        $chosen = ThemePreference::FALLBACK === ThemePreference::LIGHT
            ? ThemePreference::DARK
            : ThemePreference::LIGHT;

        ThemePreference::save($alice, $chosen);

        self::assertSame($chosen, ThemePreference::forUser($alice));
        self::assertSame(ThemePreference::FALLBACK, ThemePreference::forUser($bob));
    }

    /** @param array<string, string> $params */
    private function post(array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::ROUTE);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }
}
