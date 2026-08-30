<?php

/**
 * /assistant (η Λίτσα) χωρίς προϋπολογισμό αιτημάτων -- εσωτερική επισκόπηση 30/08.
 *
 * `/contracts/duplicate` (DuplicateCheckScopeTest), `kb_ask`, `track_upload`
 * και το test-send SMS έχουν όλα rate limit· αυτό το endpoint καλεί
 * πραγματικό Claude API -- πραγματικό κόστος ανά αίτημα, όχι μόνο ρίσκο
 * φόρτου βάσης -- και δεν είχε κανέναν προϋπολογισμό. Ίδιο τεστ-σχήμα με το
 * `DuplicateCheckScopeTest::testTheRouteIsRateLimited()`.
 *
 * Δεν χρειάζεται πραγματικό API key για το test: το `ECRM_RateLimit::allow()`
 * ελέγχεται ΠΡΩΤΟ μέσα στη `chat()`, πριν καν το `ECRM_Extractor::api_key()`
 * -- οπότε τα αιτήματα εντός προϋπολογισμού γυρνούν 400 (λείπει API key στο
 * περιβάλλον δοκιμών), και μόνο αυτό που ξεπερνάει το όριο γυρνάει 429. Το
 * test ρωτάει μόνο για το 429, ό,τι κι αν είναι το υπόλοιπο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use WP_REST_Request;
use WP_REST_Response;

final class AssistantChatRateLimitTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/assistant';

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** The route is worth nothing against a hammering script without a budget. */
    public function testTheRouteIsRateLimited(): void
    {
        $user = $this->makeCrmUser();
        wp_set_current_user($user);

        // Clear whatever a previous test in this run may have consumed for this user.
        delete_transient('ecrm_rl_assistant_chat_u' . $user);

        for ($i = 0; $i < 20; $i++) {
            self::assertNotSame(429, $this->chat()->get_status(), "Budget exhausted early, at request {$i}.");
        }

        self::assertSame(429, $this->chat()->get_status(), 'The 21st request in the window must be refused.');
    }

    private function chat(): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::ROUTE);
        $request->set_body_params([
            'messages' => [['role' => 'user', 'content' => 'Πόσες εκκρεμότητες έχω;']],
        ]);

        return rest_do_request($request);
    }
}
