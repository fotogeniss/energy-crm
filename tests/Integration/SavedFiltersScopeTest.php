<?php

/**
 * `/filters` -- τα αποθηκευμένα φίλτρα του χρήστη σε user meta.
 *
 * AUDIT εύρημα §2.5 (EKKREMI-29-08.html): 19 από τα 54 route paths χωρίς
 * integration test, τέσσερα ρητά αναφερόμενα -- αυτό είναι το πρώτο.
 * Ο κώδικας ζει αποκλειστικά σε user meta ανά χρήστη (κανένα scope σε
 * επίπεδο βάσης), οπότε ο κίνδυνος εδώ δεν είναι SQL leak αλλά λογικά
 * λάθη: το MAX_FILTERS cap, το 404 σε λάθος index, και το ότι η ίδια η
 * απομόνωση ανά χρήστη πράγματι δουλεύει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use WP_REST_Request;
use WP_REST_Response;

final class SavedFiltersScopeTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testANewUserStartsWithNoSavedFilters(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $response = $this->get();

        self::assertSame(200, $response->get_status());
        self::assertSame([], $response->get_data()['filters']);
    }

    public function testAStoredFilterIsInvisibleToAnotherUser(): void
    {
        $owner = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($owner);
        $this->store('Δικό μου');

        $stranger = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($stranger);

        self::assertSame([], $this->get()->get_data()['filters']);

        wp_set_current_user($owner);
        self::assertCount(1, $this->get()->get_data()['filters']);
    }

    public function testTheTwentiethFilterSavesButTheTwentyFirstIsRefused(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        for ($i = 1; $i <= 20; $i++) {
            $response = $this->store('Φίλτρο ' . $i);
            self::assertSame(200, $response->get_status(), "Filter {$i} should have been accepted.");
        }

        $response = $this->store('Φίλτρο 21');

        self::assertSame(409, $response->get_status());
        self::assertCount(20, $this->get()->get_data()['filters']);
    }

    public function testDeletingAnOutOfRangeIndexIs404AndChangesNothing(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));
        $this->store('Μοναδικό');

        $response = $this->delete(5);

        self::assertSame(404, $response->get_status());
        self::assertCount(1, $this->get()->get_data()['filters']);
    }

    public function testDeleteRemovesExactlyTheRequestedOneAndReindexes(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));
        $this->store('Πρώτο');
        $this->store('Δεύτερο');
        $this->store('Τρίτο');

        $response = $this->delete(1);
        $filters  = $response->get_data()['filters'];

        self::assertSame(200, $response->get_status());
        self::assertSame(['Πρώτο', 'Τρίτο'], array_column($filters, 'name'));
    }

    private function get(): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/filters'));
    }

    private function store(string $name): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/filters');
        $request->set_param('name', $name);

        return rest_do_request($request);
    }

    private function delete(int $idx): WP_REST_Response
    {
        return rest_do_request(new WP_REST_Request('DELETE', '/ecrm/v1/filters/' . $idx));
    }
}
