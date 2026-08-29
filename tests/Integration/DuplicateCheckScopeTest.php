<?php

/**
 * `GET /contracts/duplicate` — the fact of a clash may cross scope, nothing else.
 *
 * AUDIT finding, 29/08: DuplicateCheckController::present() returned `code`,
 * `status`, `status_label` and `provider` unconditionally, for every match,
 * regardless of `in_scope` — contradicting the controller's own docblock
 * ("What crosses the scope boundary is only the *fact* of a clash"). The
 * frontend (public/assets/ecrm-form.js, checkDup()) rendered exactly what it
 * was given, so the leak was not latent: any seller typing a colleague's ΑΦΜ
 * or supply number saw that colleague's contract code and status today.
 *
 * This file measures the boundary the docblock always claimed to have. The
 * endpoint stays deliberately company-wide — a scoped search would hide the
 * collision until the provider rejected the application, which is the whole
 * point of the route (see the controller's own docblock) — only the fields
 * disclosed outside scope are narrowed to match what was already promised.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class DuplicateCheckScopeTest extends IntegrationTestCase
{
    private const AFM = '123456789';

    private const SUPPLY = '99988877766';

    private CustomerRepository $customers;

    private int $alice;

    private int $bob;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customers = new CustomerRepository();
        $this->alice      = $this->makeCrmUser(Roles::SELLER);
        $this->bob        = $this->makeCrmUser(Roles::SELLER);

        $customerId = $this->customers->create($this->customerData(self::AFM));
        self::assertGreaterThan(0, $customerId);

        $this->contractId = $this->contract($customerId, $this->alice);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * The owner of the match sees everything the screen needs to recognise
     * their own row: code, status, provider, customer name.
     */
    public function testTheOwnerSeesTheFullMatch(): void
    {
        wp_set_current_user($this->alice);

        $match = $this->firstMatch($this->check(self::AFM));

        self::assertTrue($match['in_scope']);
        self::assertTrue($match['is_mine']);
        self::assertSame('ECRM-DUPTEST', $match['code']);
        self::assertSame('new', $match['status']);
        self::assertNotSame('', $match['status_label']);
    }

    /**
     * Out of scope, only the fact of a clash survives — code, status,
     * status_label and provider must all be withheld, not merely masked.
     */
    public function testAnotherPartnerLearnsOnlyThatAClashExists(): void
    {
        wp_set_current_user($this->bob);

        $match = $this->firstMatch($this->check(self::AFM));

        self::assertFalse($match['in_scope']);
        self::assertFalse($match['is_mine']);
        self::assertSame('afm', $match['match_on']);
        self::assertSame('άλλος συνεργάτης δικτύου', $match['customer']);

        self::assertSame('', $match['code'] ?? '', 'The contract code must not cross the scope boundary.');
        self::assertSame('', $match['status'] ?? '', 'The raw status must not cross the scope boundary.');
        self::assertSame('', $match['status_label'] ?? '', 'The status label must not cross the scope boundary.');
        self::assertSame('', $match['provider'] ?? '', 'The provider name must not cross the scope boundary.');
    }

    /** Same boundary, reached by supply number instead of ΑΦΜ. */
    public function testAnotherPartnerLearnsOnlyTheFactOfASupplyClash(): void
    {
        wp_set_current_user($this->bob);

        $match = $this->firstMatch($this->check('', self::SUPPLY));

        self::assertFalse($match['in_scope']);
        self::assertSame('supply', $match['match_on']);
        self::assertSame('', $match['code'] ?? '');
    }

    /**
     * A colleague with the owner in their downline is not "another partner" —
     * the visibility check is scope, not identity, and this is the control
     * that proves the withholding above is the scope gate and not a route
     * that hides these fields from everyone.
     */
    public function testAManagerWithTheOwnerInTheirDownlineSeesTheFullMatch(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);

        update_user_meta($this->alice, NetworkRepository::PARENT_META, $manager);
        (new NetworkRepository())->rebuild($this->alice);

        wp_set_current_user($manager);

        $match = $this->firstMatch($this->check(self::AFM));

        self::assertTrue($match['in_scope']);
        self::assertSame('ECRM-DUPTEST', $match['code']);
    }

    /** The route is worth nothing against a hammering script without a budget. */
    public function testTheRouteIsRateLimited(): void
    {
        wp_set_current_user($this->bob);

        // Clear whatever a previous test in this run may have consumed for bob.
        delete_transient('ecrm_rl_duplicate_u' . $this->bob);

        for ($i = 0; $i < 30; $i++) {
            self::assertNotSame(429, $this->check(self::AFM)->get_status(), "Budget exhausted early, at request {$i}.");
        }

        self::assertSame(429, $this->check(self::AFM)->get_status(), 'The 31st request in the window must be refused.');
    }

    private function contract(int $customerId, int $partnerId): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'customer_id'     => $customerId,
            'partner_user_id' => $partnerId,
            'status'          => 'new',
            'code'            => 'ECRM-DUPTEST',
            'supply_number'   => self::SUPPLY,
        ]);

        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function check(string $afm = '', string $supply = ''): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/contracts/duplicate');
        $request->set_param('afm', $afm);
        $request->set_param('supply', $supply);

        return rest_do_request($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstMatch(WP_REST_Response $response): array
    {
        $matches = $response->get_data()['matches'] ?? [];
        self::assertNotSame([], $matches, 'Expected at least one match.');

        return $matches[0];
    }
}
