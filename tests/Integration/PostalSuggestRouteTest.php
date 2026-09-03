<?php

/**
 * `GET /postal/suggest` -- σύνδεση αιτήματος→`PostalLookup`→JSON. Η ίδια η
 * αναζήτηση έχει ήδη unit tests (`PostalLookupTest`, (216)) -- δεν
 * επαναλαμβάνεται εδώ σκόπιμα, μόνο το routing/auth/JSON shape.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use WP_REST_Request;

final class PostalSuggestRouteTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/postal/suggest';

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testAKnownPostalCodeReturnsNomosAndCity(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($seller);

        $request = new WP_REST_Request('GET', self::ROUTE);
        $request->set_query_params(['postal_code' => '10431']);
        $response = rest_do_request($request);
        $data     = $response->get_data();

        self::assertTrue($data['ok']);
        self::assertSame('Αττικής', $data['nomos']);
        self::assertNotNull($data['city']);
    }

    public function testAnUnrecognisedPostalCodeReturnsNullsNotAGuess(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($seller);

        $request = new WP_REST_Request('GET', self::ROUTE);
        $request->set_query_params(['postal_code' => '99999']);
        $response = rest_do_request($request);
        $data     = $response->get_data();

        self::assertTrue($data['ok']);
        self::assertNull($data['nomos']);
        self::assertNull($data['city']);
    }

    public function testAnAnonymousRequestIsRejected(): void
    {
        wp_set_current_user(0);

        $request = new WP_REST_Request('GET', self::ROUTE);
        $request->set_query_params(['postal_code' => '10431']);
        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());
    }
}
