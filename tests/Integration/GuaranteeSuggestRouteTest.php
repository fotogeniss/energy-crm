<?php

/**
 * `GET /guarantee/suggest` -- το μόνο πράγμα που ελέγχεται εδώ είναι η
 * σύνδεση αιτήματος→`GuaranteeMatch`→JSON. Η ίδια η λογική επιλογής κανόνα
 * έχει ήδη 16 unit tests (`GuaranteeMatchTest`, (210)) -- δεν επαναλαμβάνονται
 * εδώ σκόπιμα.
 *
 * Το `amount: null` έναντι `amount: 0` είναι το ένα σημείο που αξίζει
 * integration test και όχι μόνο unit: είναι η ρίζα του JSON payload που θα
 * διαβάσει η JS, και ένα λάθος εδώ (π.χ. `(float) null === 0.0` αν κάποιος
 * βιαστεί να τυποποιήσει το `amount`) θα έσβηνε ακριβώς τη διάκριση που
 * υπάρχει το endpoint.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\GuaranteeRuleRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;

final class GuaranteeSuggestRouteTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/guarantee/suggest';

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        GuaranteeRuleRepository::forget();

        parent::tearDown();
    }

    public function testNoMatchingRuleReturnsNullNotZero(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($seller);

        $response = rest_do_request(new WP_REST_Request('GET', self::ROUTE));
        $data     = $response->get_data();

        self::assertTrue($data['ok']);
        self::assertArrayHasKey('amount', $data);
        self::assertNull($data['amount']);
    }

    public function testAZeroRuleReturnsZeroNotNull(): void
    {
        $seller   = $this->makeCrmUser(Roles::SELLER);
        $provider = $this->makeRule(['provider_id' => null, 'amount' => 0]);
        wp_set_current_user($seller);

        $response = rest_do_request(new WP_REST_Request('GET', self::ROUTE));
        $data     = $response->get_data();

        self::assertTrue($data['ok']);
        self::assertSame(0.0, $data['amount']);
        self::assertNotNull($provider);
    }

    public function testAMatchingRuleIsReturnedByProviderAndProgram(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $this->makeRule(['provider_id' => 5, 'amount' => 150]);
        $this->makeRule(['provider_id' => 5, 'program_id' => 9, 'amount' => 90]);
        wp_set_current_user($seller);

        $request = new WP_REST_Request('GET', self::ROUTE);
        $request->set_query_params(['provider_id' => 5, 'program_id' => 9]);
        $response = rest_do_request($request);
        $data     = $response->get_data();

        self::assertTrue($data['ok']);
        self::assertSame(90.0, $data['amount'], 'Το πρόγραμμα πρέπει να νικά τον πάροχο -- ίδια απόφαση με το (210).');
    }

    public function testThePowerBandIsReadFromTheFreeTextField(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $this->makeRule(['provider_id' => 5, 'kva_min' => 8, 'kva_max' => 15, 'amount' => 110]);
        wp_set_current_user($seller);

        $request = new WP_REST_Request('GET', self::ROUTE);
        $request->set_query_params(['provider_id' => 5, 'agreed_power' => '8,5']);
        $response = rest_do_request($request);
        $data     = $response->get_data();

        self::assertTrue($data['ok']);
        self::assertSame(110.0, $data['amount']);
    }

    public function testAnAnonymousRequestIsRejected(): void
    {
        wp_set_current_user(0);

        $response = rest_do_request(new WP_REST_Request('GET', self::ROUTE));

        self::assertSame(401, $response->get_status());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeRule(array $overrides): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::GUARANTEE_RULES), array_merge([
            'provider_id' => null,
            'program_id'  => null,
            'energy_type' => null,
            'category'    => null,
            'kva_min'     => null,
            'kva_max'     => null,
            'amount'      => 0,
            'active'      => 1,
        ], $overrides));

        return (int) $wpdb->insert_id;
    }
}
